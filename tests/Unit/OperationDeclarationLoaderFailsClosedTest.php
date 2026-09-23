<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Exceptions\OperationDeclarationInvalid;
use Larena\Core\Registry\OperationDeclarationLoader;

$loader = new OperationDeclarationLoader();

/**
 * @param array<string, mixed> $document
 */
function larena_reject(OperationDeclarationLoader $loader, array $document, string $expectedReason): void
{
    try {
        $loader->fromArray($document, 'fixture.yaml', 'larena/core');
    } catch (OperationDeclarationInvalid $rejection) {
        assert(
            $rejection->reasonCode === $expectedReason,
            'expected ' . $expectedReason . ', got ' . $rejection->reasonCode,
        );

        return;
    }

    throw new RuntimeException('the loader must reject: expected ' . $expectedReason);
}

$valid = [
    'schema' => 'larena.operations.contract.v1',
    'package' => 'larena/core',
    'version' => '1.0.0',
    'operations' => [[
        'name' => 'core.thing.change',
        'execution_mode' => 'sync',
        'risk' => 'change',
        'reversible' => true,
        'audit_event' => 'core.thing.changed',
        'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        'output' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        'receipt' => ['type' => 'object', 'properties' => ['change' => ['type' => 'string']]],
    ]],
];

assert(count($loader->fromArray($valid, 'fixture.yaml', 'larena/core')) === 1);

// The Batch 2 defect, reproduced. Two operation entries were appended after the
// neighbouring key, so YAML read them as members of the wrong list. Here the
// wrong list is a top-level key the contract does not know at all.
$defect = $valid;
$defect['presets'] = ['administrator', ['code' => 'core.thing.read', 'risk' => 'high']];
larena_reject($loader, $defect, 'unknown_top_level_key');

// The same mistake one level down: a scalar where a mapping belongs.
$scalarMember = $valid;
$scalarMember['operations'] = ['core.thing.change'];
larena_reject($loader, $scalarMember, 'operation_not_a_mapping');

$unknownOperationKey = $valid;
$unknownOperationKey['operations'][0]['presets'] = ['administrator'];
larena_reject($loader, $unknownOperationKey, 'unknown_operation_key');

$duplicate = $valid;
$duplicate['operations'][] = $valid['operations'][0];
larena_reject($loader, $duplicate, 'duplicate_operation_name');

larena_reject($loader, ['schema' => 'other', 'package' => 'larena/core', 'operations' => []], 'unknown_schema');
larena_reject($loader, ['schema' => 'larena.operations.contract.v1', 'package' => 'other/core', 'operations' => []], 'invalid_package');

$wrongPackage = $valid;
$wrongPackage['package'] = 'larena/access';
larena_reject($loader, $wrongPackage, 'package_mismatch');

$emptyOperations = $valid;
$emptyOperations['operations'] = [];
larena_reject($loader, $emptyOperations, 'operations_not_a_list');

foreach ([
    ['execution_mode', 'denied', 'invalid_execution_mode'],
    ['risk', 'catastrophic', 'invalid_risk_class'],
    ['reversible', 'yes', 'invalid_reversible'],
    ['transactional', 'yes', 'invalid_transactional'],
    ['transports', ['carrier-pigeon'], 'unknown_transport'],
    ['input', [], 'invalid_schema'],
    ['access_scope', '', 'invalid_access_scope'],
] as [$field, $value, $reason]) {
    $broken = $valid;
    $broken['operations'][0][$field] = $value;
    larena_reject($loader, $broken, $reason);
}

foreach (['name', 'execution_mode', 'risk', 'reversible', 'input', 'output'] as $required) {
    $missing = $valid;
    unset($missing['operations'][0][$required]);
    larena_reject($loader, $missing, 'missing_operation_field');
}

$badName = $valid;
$badName['operations'][0]['name'] = 'Thing';
larena_reject($loader, $badName, 'invalid_declaration');

$unknownSchemaField = $valid;
$unknownSchemaField['operations'][0]['input']['exclusiveMinimum'] = 1;
larena_reject($loader, $unknownSchemaField, 'unknown_schema_field');

// A list document instead of a mapping. The helper's own signature says mapping,
// so this one case goes straight to the loader.
try {
    $loader->fromArray(['a', 'b'], 'fixture.yaml', 'larena/core');
    throw new RuntimeException('a list document must be rejected');
} catch (OperationDeclarationInvalid $rejection) {
    assert($rejection->reasonCode === 'not_a_mapping');
}

try {
    $loader->loadFile(__DIR__ . '/does-not-exist.yaml');
    throw new RuntimeException('a missing file must be rejected');
} catch (OperationDeclarationInvalid $rejection) {
    assert($rejection->reasonCode === 'unreadable_file');
}

echo "Operation declaration rejection passed.\n";

<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Registry\PackageDescriptorFileValidator;

$validator = new PackageDescriptorFileValidator();
$packageRoot = dirname(__DIR__, 2);

// Core's own descriptor files are well formed.
assert($validator->violations($packageRoot) === [], 'core descriptor files must be structurally valid');

$codes = $validator->declaredAccessOperationCodes($packageRoot);
foreach ([
    'core.scope.manage', 'core.scope.read', 'core.plane.manage', 'core.plane.membership.manage',
    'core.plane.read', 'core.operation_registry.read', 'core.transport.read', 'core.transport.invoke',
] as $expected) {
    assert(in_array($expected, $codes, true), $expected . ' must be declared in access.yaml');
}

// A package directory without descriptor files yields nothing and no violation.
$empty = sys_get_temp_dir() . '/larena-descriptor-empty-' . getmypid();
@mkdir($empty, 0777, true);
assert($validator->violations($empty) === []);
assert($validator->declaredAccessOperationCodes($empty) === []);

// The Batch 2 defect, written exactly as it happened: two operation entries
// appended after the presets key, so YAML read them as presets.
$defective = sys_get_temp_dir() . '/larena-descriptor-defect-' . getmypid();
@mkdir($defective, 0777, true);
file_put_contents($defective . '/access.yaml', <<<YAML
schema: larena.access.descriptor.v1
package: larena/access
operations:
  - code: access.role.read
    risk: high
    audit: denial
presets:
  - administrator
  - editor
  - reader
  - code: access.node_grant.manage
    risk: critical
    audit: always
  - code: access.node_grant.read
    risk: high
    audit: denial
YAML);

$violations = $validator->violations($defective);
assert(count($violations) === 2, 'both misplaced entries must be reported, got ' . count($violations));
foreach ($violations as $violation) {
    assert($violation['reason_code'] === 'unexpected_map_in_list');
    assert(str_contains($violation['detail'], 'wrong key'), 'the detail must say what happened');
}

// And the codes are absent, which is the harm the gates missed: the operations
// were never declared.
$defectiveCodes = $validator->declaredAccessOperationCodes($defective);
assert($defectiveCodes === ['access.role.read'], 'the misplaced codes are not declared operations');

// Other malformed shapes are caught too.
$cases = [
    ["schema: other\npackage: larena/access\noperations: []\n", 'unknown_schema'],
    ["schema: larena.access.descriptor.v1\npackage: larena/access\nunknown_key: 1\n", 'unknown_top_level_key'],
    ["schema: larena.access.descriptor.v1\npackage: larena/access\noperations:\n  administrator: true\n", 'not_a_list'],
    ["schema: larena.access.descriptor.v1\npackage: larena/access\noperations:\n  - access.role.read\n", 'unexpected_scalar_in_list'],
    ["schema: larena.access.descriptor.v1\npackage: larena/access\noperations:\n  - risk: high\n", 'missing_member_key'],
    ["- a\n- b\n", 'not_a_mapping'],
];

foreach ($cases as $index => [$yaml, $expectedReason]) {
    $path = sys_get_temp_dir() . '/larena-descriptor-case-' . getmypid() . '-' . $index;
    @mkdir($path, 0777, true);
    file_put_contents($path . '/access.yaml', $yaml);

    $reasons = array_column($validator->violations($path), 'reason_code');
    assert(in_array($expectedReason, $reasons, true), 'expected ' . $expectedReason . ', got ' . implode(', ', $reasons));

    @unlink($path . '/access.yaml');
    @rmdir($path);
}

@unlink($defective . '/access.yaml');
@rmdir($defective);
@rmdir($empty);

echo "Package descriptor file validation passed.\n";

<?php

declare(strict_types=1);

namespace Larena\Core\Registry;

use Larena\Core\Contracts\OperationDeclaration;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Enums\TransportKind;
use Larena\Core\Exceptions\OperationDeclarationInvalid;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads one package's `operations.yaml`.
 *
 * Every key is closed. That is deliberate and it has a history: two access
 * operation codes were once appended after the wrong key in a descriptor file,
 * YAML read them as members of the neighbouring list, and every gate in three
 * repositories stayed green because nothing validated that file's structure. A
 * loader that accepts only the keys it knows turns that class of mistake into a
 * failure at the first read.
 */
final class OperationDeclarationLoader
{
    public const CONTRACT_SCHEMA = 'larena.operations.contract.v1';

    private const TOP_LEVEL_FIELDS = ['schema', 'package', 'version', 'operations'];

    private const OPERATION_FIELDS = [
        'name', 'execution_mode', 'risk', 'reversible', 'access_scope', 'audit_event',
        'idempotency_key', 'transactional', 'transports', 'input', 'output', 'receipt',
    ];

    private const REQUIRED_OPERATION_FIELDS = ['name', 'execution_mode', 'risk', 'reversible', 'input', 'output'];

    private const SCHEMA_FIELDS = [
        'type', 'properties', 'required', 'additionalProperties', 'items', 'enum',
        'minLength', 'maxLength', 'minimum', 'maximum', 'pattern', 'format',
    ];

    private const DECLARABLE_EXECUTION_MODES = ['sync', 'queued', 'scheduled'];

    private const SAFE_PACKAGE = '/^larena\/[a-z][a-z0-9-]*$/';

    public function __construct(private readonly int $maximumContractBytes = 262144)
    {
    }

    /**
     * @return list<OperationDeclaration>
     */
    public function loadFile(string $path, ?string $expectedPackage = null): array
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new OperationDeclarationInvalid('unreadable_file', $path, 'not a readable regular file');
        }

        $size = filesize($realPath);
        if (!is_int($size) || $size < 1 || $size > $this->maximumContractBytes) {
            throw new OperationDeclarationInvalid('size_out_of_bounds', $path, 'file size is outside the allowed boundary');
        }

        try {
            $parsed = Yaml::parseFile($realPath, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw new OperationDeclarationInvalid('invalid_yaml', $path, $exception->getMessage());
        } catch (Throwable $exception) {
            throw new OperationDeclarationInvalid('unreadable_yaml', $path, $exception->getMessage());
        }

        return $this->fromArray($parsed, $path, $expectedPackage);
    }

    /**
     * @param mixed $parsed
     * @return list<OperationDeclaration>
     */
    public function fromArray(mixed $parsed, string $path, ?string $expectedPackage = null): array
    {
        if (!is_array($parsed) || array_is_list($parsed)) {
            throw new OperationDeclarationInvalid('not_a_mapping', $path, 'the document must be a mapping');
        }

        $this->rejectUnknownKeys(array_keys($parsed), self::TOP_LEVEL_FIELDS, $path, 'unknown_top_level_key');

        $schema = $parsed['schema'] ?? null;
        if ($schema !== self::CONTRACT_SCHEMA) {
            throw new OperationDeclarationInvalid('unknown_schema', $path, 'expected ' . self::CONTRACT_SCHEMA);
        }

        $package = $parsed['package'] ?? null;
        if (!is_string($package) || preg_match(self::SAFE_PACKAGE, $package) !== 1) {
            throw new OperationDeclarationInvalid('invalid_package', $path, 'package must be a larena/* name');
        }

        if ($expectedPackage !== null && $package !== $expectedPackage) {
            throw new OperationDeclarationInvalid(
                'package_mismatch',
                $path,
                'declared ' . $package . ', expected ' . $expectedPackage,
            );
        }

        $operations = $parsed['operations'] ?? null;
        if (!is_array($operations) || !array_is_list($operations) || $operations === []) {
            throw new OperationDeclarationInvalid('operations_not_a_list', $path, 'operations must be a non-empty list');
        }

        $declarations = [];
        $seen = [];

        foreach ($operations as $index => $operation) {
            $declaration = $this->declaration($operation, $package, $path, $index);
            if (isset($seen[$declaration->name])) {
                throw new OperationDeclarationInvalid('duplicate_operation_name', $path, $declaration->name);
            }

            $seen[$declaration->name] = true;
            $declarations[] = $declaration;
        }

        return $declarations;
    }

    private function declaration(mixed $operation, string $package, string $path, int|string $index): OperationDeclaration
    {
        $location = $path . ' operations[' . $index . ']';

        if (!is_array($operation) || array_is_list($operation)) {
            throw new OperationDeclarationInvalid(
                'operation_not_a_mapping',
                $location,
                'a list member that is not a mapping usually means the entry was appended under the wrong key',
            );
        }

        $this->rejectUnknownKeys(array_keys($operation), self::OPERATION_FIELDS, $location, 'unknown_operation_key');

        foreach (self::REQUIRED_OPERATION_FIELDS as $field) {
            if (!array_key_exists($field, $operation)) {
                throw new OperationDeclarationInvalid('missing_operation_field', $location, $field);
            }
        }

        $name = $operation['name'];
        if (!is_string($name)) {
            throw new OperationDeclarationInvalid('invalid_operation_name', $location, 'name must be a string');
        }

        $executionMode = $this->executionMode($operation['execution_mode'], $location);
        $risk = $this->risk($operation['risk'], $location);

        $reversible = $operation['reversible'];
        if (!is_bool($reversible)) {
            throw new OperationDeclarationInvalid('invalid_reversible', $location, 'reversible must be a boolean');
        }

        $transactional = $operation['transactional'] ?? false;
        if (!is_bool($transactional)) {
            throw new OperationDeclarationInvalid('invalid_transactional', $location, 'transactional must be a boolean');
        }

        try {
            return new OperationDeclaration(
                package: $package,
                name: $name,
                executionMode: $executionMode,
                riskClass: $risk,
                reversible: $reversible,
                inputSchema: $this->schema($operation['input'], $location, 'input'),
                outputSchema: $this->schema($operation['output'], $location, 'output'),
                receiptSchema: array_key_exists('receipt', $operation)
                    ? $this->schema($operation['receipt'], $location, 'receipt')
                    : null,
                accessScope: $this->optionalString($operation, 'access_scope', $location),
                auditEvent: $this->optionalString($operation, 'audit_event', $location),
                idempotencyKey: $this->optionalString($operation, 'idempotency_key', $location),
                transactional: $transactional,
                transports: $this->transports($operation['transports'] ?? null, $location),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new OperationDeclarationInvalid('invalid_declaration', $location, $exception->getMessage());
        }
    }

    private function executionMode(mixed $value, string $location): OperationExecutionMode
    {
        if (!is_string($value) || !in_array($value, self::DECLARABLE_EXECUTION_MODES, true)) {
            throw new OperationDeclarationInvalid(
                'invalid_execution_mode',
                $location,
                'expected one of ' . implode(', ', self::DECLARABLE_EXECUTION_MODES),
            );
        }

        // `denied` is a decision outcome, never a declared mode: an operation
        // that can only be denied has no business being registered.
        return OperationExecutionMode::from($value);
    }

    private function risk(mixed $value, string $location): OperationRiskClass
    {
        $risk = is_string($value) ? OperationRiskClass::tryFrom($value) : null;

        return $risk ?? throw new OperationDeclarationInvalid('invalid_risk_class', $location, 'unknown risk class');
    }

    /**
     * @return list<TransportKind>
     */
    private function transports(mixed $value, string $location): array
    {
        if ($value === null) {
            return [TransportKind::Local];
        }

        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new OperationDeclarationInvalid('invalid_transports', $location, 'transports must be a non-empty list');
        }

        $kinds = [];
        foreach ($value as $candidate) {
            $kind = is_string($candidate) ? TransportKind::tryFrom($candidate) : null;
            if ($kind === null) {
                throw new OperationDeclarationInvalid('unknown_transport', $location, 'unknown transport kind');
            }

            $kinds[] = $kind;
        }

        return $kinds;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(mixed $value, string $location, string $part): array
    {
        // array_is_list() is true for the empty array, so a mapping check and a
        // non-empty check are the same test here.
        if (!is_array($value) || array_is_list($value)) {
            throw new OperationDeclarationInvalid('invalid_schema', $location, $part . ' must be a non-empty mapping');
        }

        $this->assertSchemaFields($value, $location . ' ' . $part);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function assertSchemaFields(array $schema, string $location): void
    {
        foreach ($schema as $key => $value) {
            if (!is_string($key)) {
                throw new OperationDeclarationInvalid('invalid_schema_key', $location, 'schema keys must be strings');
            }

            if ($key === 'properties') {
                if (!is_array($value)) {
                    throw new OperationDeclarationInvalid('invalid_schema', $location, 'properties must be a mapping');
                }

                foreach ($value as $property => $definition) {
                    if (is_array($definition)) {
                        $this->assertSchemaFields($definition, $location . '.' . (string) $property);
                    }
                }

                continue;
            }

            if (!in_array($key, self::SCHEMA_FIELDS, true)) {
                throw new OperationDeclarationInvalid('unknown_schema_field', $location, $key);
            }

            if ($key === 'items' && is_array($value)) {
                $this->assertSchemaFields($value, $location . '.items');
            }
        }
    }

    /**
     * @param array<array-key, mixed> $operation
     */
    private function optionalString(array $operation, string $field, string $location): ?string
    {
        $value = $operation[$field] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new OperationDeclarationInvalid('invalid_' . $field, $location, $field . ' must be a non-empty string');
        }

        return $value;
    }

    /**
     * @param list<array-key> $actual
     * @param list<string> $allowed
     */
    private function rejectUnknownKeys(array $actual, array $allowed, string $path, string $reasonCode): void
    {
        foreach ($actual as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new OperationDeclarationInvalid($reasonCode, $path, (string) $key);
            }
        }
    }
}

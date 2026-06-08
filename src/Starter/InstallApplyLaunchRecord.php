<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

final class InstallApplyLaunchRecord
{
    private const SCHEMA = 'larena.install_apply_launch_record.v1';
    private const CONFIRMATIONS_BY_TARGET = [
        'package_registry_seed' => 'package_registry_seed',
        'installer_persistence_foundation' => 'installer_persistence_foundation',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function load(string $basePath, string $launchRecordPath): array
    {
        if (!self::isSafeRelativePath($launchRecordPath)) {
            return self::blocked('launch_record_path_must_be_safe_relative_path', [
                'launch_record_path' => $launchRecordPath,
            ]);
        }

        $absolutePath = self::absolutePath($basePath, $launchRecordPath);

        if (!is_file($absolutePath)) {
            return self::blocked('launch_record_missing', [
                'launch_record_path' => $launchRecordPath,
            ]);
        }

        $record = json_decode((string) file_get_contents($absolutePath), true);

        if (!is_array($record)) {
            return self::blocked('launch_record_invalid_json', [
                'launch_record_path' => $launchRecordPath,
            ]);
        }

        $errors = self::validate($record);

        if ($errors !== []) {
            return self::blocked('launch_record_invalid', [
                'launch_record_path' => $launchRecordPath,
                'errors' => $errors,
            ]);
        }

        $record['_absolute_path'] = $absolutePath;
        $record['_relative_path'] = self::relativePath($basePath, $absolutePath);

        return [
            'status' => 'passed',
            'record' => $record,
        ];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    private static function validate(array $record): array
    {
        $errors = [];

        if (($record['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = 'schema_must_be_' . self::SCHEMA;
        }

        foreach (['id', 'status', 'transition', 'target_step', 'evidence_path'] as $field) {
            if (!isset($record[$field]) || !is_string($record[$field]) || $record[$field] === '') {
                $errors[] = "missing_or_invalid_{$field}";
            }
        }

        if (($record['status'] ?? null) !== 'ready_to_apply') {
            $errors[] = 'status_must_be_ready_to_apply';
        }

        if (($record['transition'] ?? null) !== 'install_apply_launch_record') {
            $errors[] = 'transition_must_be_install_apply_launch_record';
        }

        $targetStep = $record['target_step'] ?? null;
        if (!is_string($targetStep) || !array_key_exists($targetStep, self::CONFIRMATIONS_BY_TARGET)) {
            $errors[] = 'target_step_must_be_supported_install_apply_step';
        }

        if (is_string($targetStep) && !in_array($targetStep, $record['allowed_scope'] ?? [], true)) {
            $errors[] = 'allowed_scope_must_include_target_step';
        }

        $limits = $record['limits'] ?? [];
        $expectedConfirmation = is_string($targetStep) ? self::CONFIRMATIONS_BY_TARGET[$targetStep] ?? null : null;
        if (!is_array($limits) || ($limits['requires_command_confirmation'] ?? null) !== $expectedConfirmation) {
            $errors[] = 'limits_requires_command_confirmation_must_match_target_step';
        }

        $approval = $record['operator_approval'] ?? [];
        if (!is_array($approval) || ($approval['status'] ?? null) !== 'approved') {
            $errors[] = 'operator_approval_must_be_approved';
        }

        $backup = $record['backup'] ?? [];
        if (!is_array($backup)) {
            $errors[] = 'backup_must_be_object';
        } else {
            foreach (['target', 'path'] as $field) {
                if (!isset($backup[$field]) || !is_string($backup[$field]) || $backup[$field] === '') {
                    $errors[] = "backup_missing_or_invalid_{$field}";
                }
            }
        }

        $rollback = $record['rollback_plan'] ?? [];
        if (!is_array($rollback) || ($rollback['type'] ?? null) !== 'restore_backup_or_delete_if_absent') {
            $errors[] = 'rollback_plan_must_restore_backup_or_delete_if_absent';
        }

        foreach (['evidence_path', 'backup.target', 'backup.path'] as $pathRef) {
            $value = self::pathValue($record, $pathRef);
            if (is_string($value) && !self::isSafeRelativePath($value)) {
                $errors[] = "{$pathRef}_must_be_safe_relative_path";
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function blocked(string $reason, array $payload = []): array
    {
        return array_merge([
            'status' => 'blocked',
            'reason' => $reason,
        ], $payload);
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function pathValue(array $record, string $path): mixed
    {
        $current = $record;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private static function absolutePath(string $basePath, string $path): string
    {
        return rtrim($basePath, '/') . '/' . $path;
    }

    private static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return false;
        }

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function relativePath(string $basePath, string $absolutePath): string
    {
        $basePath = rtrim($basePath, '/') . '/';

        if (str_starts_with($absolutePath, $basePath)) {
            return substr($absolutePath, strlen($basePath));
        }

        return $absolutePath;
    }
}

<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Diagnostics\RuntimeSecuritySmoke;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class StarterScenario
{
    /**
     * @return array<string, mixed>
     */
    public static function contextFromApplication(Application $app): array
    {
        $config = $app->make('config');

        if (!$config instanceof ConfigRepository) {
            throw new RuntimeException('laravel_config_repository_unavailable');
        }

        return [
            'base_path' => $app->basePath(),
            'storage_path' => $app->storagePath(),
            'bootstrap_cache_path' => $app->bootstrapPath('cache'),
            'laravel_version' => $app->version(),
            'environment' => (string) $app->environment(),
            'app_name' => (string) $config->get('app.name', 'Larena'),
            'debug' => (bool) $config->get('app.debug', false),
            'timezone' => (string) $config->get('app.timezone', 'UTC'),
            'locale' => (string) $config->get('app.locale', 'en'),
            'database_connection' => self::databaseConnectionDiagnostic($config),
        ];
    }

    /**
     * @param array{
     *     base_path: string,
     *     storage_path: string,
     *     bootstrap_cache_path: string,
     *     laravel_version: string,
     *     environment: string,
     *     app_name: string,
     *     debug: bool,
     *     timezone: string,
     *     locale: string,
     *     database_connection: array<string, mixed>
     * } $applicationContext
     *
     * @return array<string, mixed>
     */
    public static function doctor(string $outputPath, array $applicationContext): array
    {
        $runtimeOutputPath = dirname($outputPath) . '/runtime-security-from-doctor.json';
        $installedPackages = self::installedPackages($applicationContext['base_path']);
        $runtimeSecurityPackages = FoundationPackageSet::runtimeSecurity();
        $foundationPackages = FoundationPackageSet::foundationPreview();
        $missingPackages = array_values(array_diff($runtimeSecurityPackages, array_keys($installedPackages)));
        $runtimeReport = $missingPackages === []
            ? RuntimeSecuritySmoke::run($runtimeOutputPath, $applicationContext)
            : [
                'status' => 'failed',
                'reason' => 'required_runtime_security_packages_missing',
                'missing_packages' => $missingPackages,
            ];
        $checks = [
            'php_version' => [
                'status' => version_compare(PHP_VERSION, '8.3.0', '>=') ? 'passed' : 'failed',
                'actual' => PHP_VERSION,
                'expected' => '^8.3',
            ],
            'laravel_version' => [
                'status' => version_compare($applicationContext['laravel_version'], '13.0.0', '>=') ? 'passed' : 'failed',
                'actual' => $applicationContext['laravel_version'],
                'expected' => '^13.0',
            ],
            'required_packages' => [
                'status' => $missingPackages === [] ? 'passed' : 'failed',
                'installed' => array_intersect_key($installedPackages, array_flip($runtimeSecurityPackages)),
                'missing' => $missingPackages,
            ],
            'runtime_security_smoke' => [
                'status' => $runtimeReport['status'] === 'passed' ? 'passed' : 'failed',
                'evidence_path' => $runtimeOutputPath,
                'reason' => $runtimeReport['reason'] ?? null,
            ],
            'write_paths' => [
                'status' => self::storageWritable($applicationContext) ? 'passed' : 'failed',
                'paths' => [
                    'storage' => $applicationContext['storage_path'],
                    'bootstrap_cache' => $applicationContext['bootstrap_cache_path'],
                ],
            ],
            'database_connection' => $applicationContext['database_connection'],
            'package_registry' => self::packageRegistryDiagnostics($applicationContext),
            'foundation_packages' => [
                'status' => 'diagnostic',
                'required_for_runtime_security' => $runtimeSecurityPackages,
                'foundation_preview_packages' => $foundationPackages,
                'installed' => array_values(array_intersect($foundationPackages, array_keys($installedPackages))),
                'missing' => array_values(array_diff($foundationPackages, array_keys($installedPackages))),
            ],
        ];

        $report = [
            'schema' => 'larena.starter_doctor.v1',
            'status' => self::checksPassed($checks) ? 'passed' : 'failed',
            'generated_at' => gmdate('c'),
            'application' => [
                'name' => $applicationContext['app_name'],
                'environment' => $applicationContext['environment'],
                'debug' => $applicationContext['debug'],
                'timezone' => $applicationContext['timezone'],
                'locale' => $applicationContext['locale'],
            ],
            'checks' => $checks,
            'next_recommended_command' => 'php artisan larena:install --dry-run',
        ];

        self::writeJson($outputPath, $report);

        return $report;
    }

    /**
     * @param array{
     *     base_path: string,
     *     storage_path: string,
     *     bootstrap_cache_path: string,
     *     laravel_version: string,
     *     environment: string,
     *     app_name: string,
     *     debug: bool,
     *     timezone: string,
     *     locale: string,
     *     database_connection: array<string, mixed>
     * } $applicationContext
     *
     * @return array<string, mixed>
     */
    public static function installPlan(string $outputPath, bool $dryRun, array $applicationContext): array
    {
        if (!$dryRun) {
            $report = [
                'schema' => 'larena.starter_install_plan.v1',
                'status' => 'blocked',
                'generated_at' => gmdate('c'),
                'reason' => 'actual_install_requires_launch_record_and_guarded_transition',
                'mutates_state' => false,
                'actual_install_allowed' => false,
                'transition_required' => 'install_apply_launch_record',
                'required_before_mutation' => [
                    'launch_record',
                    'allowed_scope',
                    'backup_evidence',
                    'rollback_plan',
                    'evidence_path',
                    'operator_approval',
                ],
                'safe_command' => 'php artisan larena:install --dry-run',
            ];

            self::writeJson($outputPath, $report);

            return $report;
        }

        $doctorOutputPath = dirname($outputPath) . '/doctor-from-install-dry-run.json';
        $doctor = self::doctor($doctorOutputPath, $applicationContext);
        $plan = [
            [
                'step' => 'environment_preflight',
                'status' => $doctor['status'] === 'passed' ? 'ready' : 'blocked',
                'evidence' => $doctorOutputPath,
            ],
            [
                'step' => 'package_registry_seed',
                'status' => 'planned',
                'packages' => FoundationPackageSet::foundationPreview(),
                'mutates_state' => false,
            ],
            [
                'step' => 'installer_persistence_foundation',
                'status' => 'planned_after_package_registry_seed',
                'target' => 'storage/app/larena/installer-persistence-foundation.json',
                'mutates_state' => false,
                'applies_database_migrations' => false,
                'creates_database' => false,
                'writes_environment' => false,
                'planned_tables' => InstallerPersistenceFoundation::plannedTables(),
                'requires_launch_record' => true,
                'requires_command_confirmation' => 'installer_persistence_foundation',
            ],
            [
                'step' => 'database_environment',
                'status' => self::databaseReadyForFutureInstall($doctor) ? 'ready' : 'degraded',
                'required_for_current_preview' => false,
                'required_for_future_install' => true,
                'mutates_state' => false,
                'evidence' => $doctorOutputPath,
            ],
            [
                'step' => 'runtime_security_verification',
                'status' => $doctor['checks']['runtime_security_smoke']['status'] === 'passed' ? 'ready' : 'blocked',
                'mutates_state' => false,
            ],
            [
                'step' => 'admin_bootstrap',
                'status' => 'deferred',
                'reason' => 'admin package is outside foundation developer preview package set',
            ],
            [
                'step' => 'persistence_migrations',
                'status' => 'deferred',
                'reason' => 'persistence is outside first runtime-security CLI starter scenario',
            ],
        ];

        $blocked = array_values(array_filter(
            $plan,
            static fn (array $step): bool => $step['status'] === 'blocked',
        ));
        $readinessContract = InstallReadinessContract::fromDryRunPlan($doctor, $plan, $doctorOutputPath);

        $report = [
            'schema' => 'larena.starter_install_plan.v1',
            'status' => $blocked === [] ? 'passed' : 'failed',
            'generated_at' => gmdate('c'),
            'mode' => 'dry_run',
            'mutates_state' => false,
            'plan' => $plan,
            'blocked_steps' => $blocked,
            'install_readiness_contract' => $readinessContract,
            'next_gate' => 'install_apply_launch_record',
        ];

        self::writeJson($outputPath, $report);

        return $report;
    }

    /**
     * @param array{
     *     base_path: string,
     *     storage_path: string,
     *     bootstrap_cache_path: string,
     *     laravel_version: string,
     *     environment: string,
     *     app_name: string,
     *     debug: bool,
     *     timezone: string,
     *     locale: string,
     *     database_connection: array<string, mixed>
     * } $applicationContext
     *
     * @return array<string, mixed>
     */
    public static function applyInstallLaunchRecord(
        string $launchRecordPath,
        string $confirmation,
        array $applicationContext
    ): array {
        $loaded = InstallApplyLaunchRecord::load($applicationContext['base_path'], $launchRecordPath);
        if (($loaded['status'] ?? null) !== 'passed') {
            return [
                'schema' => 'larena.install_apply_result.v1',
                'status' => 'blocked',
                'generated_at' => gmdate('c'),
                'reason' => $loaded['reason'] ?? 'launch_record_not_accepted',
                'details' => $loaded,
                'mutates_state' => false,
                'safe_command' => 'php artisan larena:install --dry-run',
            ];
        }

        $record = $loaded['record'];
        $expectedConfirmation = (string) ($record['limits']['requires_command_confirmation'] ?? 'package_registry_seed');

        if ($confirmation !== $expectedConfirmation) {
            return [
                'schema' => 'larena.install_apply_result.v1',
                'status' => 'blocked',
                'generated_at' => gmdate('c'),
                'reason' => 'confirmation_must_match_launch_record',
                'required_confirmation' => $expectedConfirmation,
                'provided_confirmation' => $confirmation !== '' ? $confirmation : null,
                'launch_record' => [
                    'id' => $record['id'] ?? null,
                    'path' => $record['_relative_path'] ?? null,
                ],
                'mutates_state' => false,
                'safe_command' => 'php artisan larena:install --dry-run',
            ];
        }

        $doctorOutputPath = rtrim((string) $record['evidence_path'], '/') . '/doctor-before-apply.json';
        $doctor = self::doctor($applicationContext['base_path'] . '/' . $doctorOutputPath, $applicationContext);

        if (($doctor['status'] ?? null) !== 'passed') {
            return [
                'schema' => 'larena.install_apply_result.v1',
                'status' => 'blocked',
                'generated_at' => gmdate('c'),
                'reason' => 'doctor_preflight_failed',
                'doctor_evidence' => $doctorOutputPath,
                'mutates_state' => false,
                'safe_command' => 'php artisan larena:doctor',
            ];
        }

        return match ($record['target_step'] ?? null) {
            'package_registry_seed' => PackageRegistrySeed::apply(
                $applicationContext['base_path'],
                $record,
                self::installedPackages($applicationContext['base_path']),
                FoundationPackageSet::foundationPreview(),
            ),
            'installer_persistence_foundation' => InstallerPersistenceFoundation::apply(
                $applicationContext['base_path'],
                $record,
                $applicationContext,
            ),
            default => [
                'schema' => 'larena.install_apply_result.v1',
                'status' => 'blocked',
                'generated_at' => gmdate('c'),
                'reason' => 'target_step_must_be_supported_install_apply_step',
                'target_step' => $record['target_step'] ?? null,
                'mutates_state' => false,
                'safe_command' => 'php artisan larena:install --dry-run',
            ],
        };
    }

    /**
     * @param array{
     *     base_path: string,
     *     storage_path: string,
     *     bootstrap_cache_path: string,
     *     laravel_version: string,
     *     environment: string,
     *     app_name: string,
     *     debug: bool,
     *     timezone: string,
     *     locale: string,
     *     database_connection: array<string, mixed>
     * } $applicationContext
     *
     * @return array<string, mixed>
     */
    public static function packageRegistryDiagnostics(array $applicationContext): array
    {
        return PackageRegistryDiagnostics::inspect($applicationContext['base_path'], FoundationPackageSet::foundationPreview());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function installedPackages(string $basePath): array
    {
        $installedPath = $basePath . '/vendor/composer/installed.json';
        if (!is_file($installedPath)) {
            return [];
        }

        $installed = json_decode((string) file_get_contents($installedPath), true);
        $packages = $installed['packages'] ?? $installed;

        if (!is_array($packages)) {
            return [];
        }

        $byName = [];
        foreach ($packages as $package) {
            if (is_array($package) && isset($package['name']) && is_string($package['name'])) {
                $byName[$package['name']] = [
                    'version' => $package['version'] ?? null,
                    'install_path' => $package['install-path'] ?? null,
                ];
            }
        }

        return $byName;
    }

    /**
     * @param array{storage_path: string, bootstrap_cache_path: string} $applicationContext
     */
    private static function storageWritable(array $applicationContext): bool
    {
        return is_writable($applicationContext['storage_path'])
            && is_writable($applicationContext['bootstrap_cache_path']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function databaseConnectionDiagnostic(ConfigRepository $config): array
    {
        $default = (string) $config->get('database.default', 'sqlite');
        $connection = $config->get("database.connections.{$default}", []);

        if (!is_array($connection)) {
            return [
                'status' => 'degraded',
                'reason' => 'database_connection_config_missing',
                'default_connection' => $default,
                'required_for_current_preview' => false,
                'required_for_future_install' => true,
                'action' => 'Define the selected database connection in config/database.php and .env.',
            ];
        }

        $driver = (string) ($connection['driver'] ?? $default);
        $summary = [
            'default_connection' => $default,
            'driver' => $driver,
            'host' => isset($connection['host']) ? (string) $connection['host'] : null,
            'port' => isset($connection['port']) ? (string) $connection['port'] : null,
            'database' => isset($connection['database']) ? (string) $connection['database'] : null,
            'username_configured' => isset($connection['username']) && (string) $connection['username'] !== '',
            'password_configured' => isset($connection['password']) && (string) $connection['password'] !== '',
            'required_for_current_preview' => false,
            'required_for_future_install' => true,
            'mutates_state' => false,
        ];

        if ($driver === 'sqlite') {
            $database = (string) ($connection['database'] ?? '');
            $exists = $database === ':memory:' || ($database !== '' && is_file($database));

            return [
                ...$summary,
                'status' => $exists ? 'passed' : 'degraded',
                'reason' => $exists ? null : 'sqlite_database_file_missing',
                'action' => $exists ? null : 'Create the SQLite file or update DB_DATABASE in .env.',
            ];
        }

        if ($driver !== 'mysql') {
            return [
                ...$summary,
                'status' => 'diagnostic',
                'reason' => 'database_driver_not_checked_by_installer_foundation',
                'action' => 'Run the project-specific DB smoke before enabling install migrations.',
            ];
        }

        if (!extension_loaded('pdo_mysql')) {
            return [
                ...$summary,
                'status' => 'degraded',
                'reason' => 'pdo_mysql_extension_missing',
                'action' => 'Enable pdo_mysql for the PHP binary used by Artisan.',
            ];
        }

        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? '3306');
        $database = (string) ($connection['database'] ?? '');
        $username = (string) ($connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? '');
        $charset = (string) ($connection['charset'] ?? 'utf8mb4');

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2,
                ],
            );
            $pdo->query('select 1');

            return [
                ...$summary,
                'status' => 'passed',
                'reason' => null,
                'action' => null,
            ];
        } catch (PDOException $exception) {
            return [
                ...$summary,
                'status' => 'degraded',
                'reason' => self::databaseFailureReason($exception),
                'safe_message' => self::databaseFailureMessage($exception),
                'action' => 'Check DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE and DB_USERNAME in .env, then run php artisan config:clear and php artisan larena:doctor --full.',
            ];
        } catch (Throwable) {
            return [
                ...$summary,
                'status' => 'degraded',
                'reason' => 'database_connection_check_failed',
                'safe_message' => 'Database connection could not be checked safely. Secret values are not printed.',
                'action' => 'Check local DB service and .env values, then run php artisan config:clear and php artisan larena:doctor --full.',
            ];
        }
    }

    private static function databaseFailureReason(PDOException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'Access denied')) {
            return 'database_credentials_rejected';
        }

        if (str_contains($message, 'Connection refused')) {
            return 'database_connection_refused';
        }

        if (str_contains($message, 'Unknown database')) {
            return 'database_not_found';
        }

        return 'database_connection_failed';
    }

    private static function databaseFailureMessage(PDOException $exception): string
    {
        return match (self::databaseFailureReason($exception)) {
            'database_credentials_rejected' => 'Database credentials were rejected. Password is not printed; check local .env values.',
            'database_connection_refused' => 'Database server refused the connection. Check that the local DB service is running.',
            'database_not_found' => 'Configured database was not found. Create it manually or update DB_DATABASE.',
            default => 'Database connection failed. Secret values are not printed.',
        };
    }

    /**
     * @param array<string, mixed> $doctor
     */
    private static function databaseReadyForFutureInstall(array $doctor): bool
    {
        $database = $doctor['checks']['database_connection'] ?? [];

        return is_array($database) && ($database['status'] ?? null) === 'passed';
    }

    /**
     * @param array<string, array<string, mixed>> $checks
     */
    private static function checksPassed(array $checks): bool
    {
        foreach ($checks as $name => $check) {
            if ($name === 'package_registry') {
                continue;
            }

            if ($name === 'database_connection' && ($check['required_for_current_preview'] ?? false) === false) {
                continue;
            }

            if (($check['status'] ?? null) === 'diagnostic') {
                continue;
            }

            if (($check['status'] ?? null) !== 'passed') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeJson(string $outputPath, array $payload): void
    {
        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($outputPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}

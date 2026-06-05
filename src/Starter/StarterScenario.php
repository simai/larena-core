<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Diagnostics\RuntimeSecuritySmoke;
use RuntimeException;

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
     *     locale: string
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
     *     locale: string
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
     *     locale: string
     * } $applicationContext
     *
     * @return array<string, mixed>
     */
    public static function applyInstallLaunchRecord(
        string $launchRecordPath,
        string $confirmation,
        array $applicationContext
    ): array {
        if ($confirmation !== 'package_registry_seed') {
            return [
                'schema' => 'larena.install_apply_result.v1',
                'status' => 'blocked',
                'generated_at' => gmdate('c'),
                'reason' => 'confirmation_must_equal_package_registry_seed',
                'mutates_state' => false,
                'safe_command' => 'php artisan larena:install --dry-run',
            ];
        }

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

        $doctorOutputPath = rtrim((string) $loaded['record']['evidence_path'], '/') . '/doctor-before-apply.json';
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

        return PackageRegistrySeed::apply(
            $applicationContext['base_path'],
            $loaded['record'],
            self::installedPackages($applicationContext['base_path']),
            FoundationPackageSet::foundationPreview(),
        );
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
     *     locale: string
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
     * @param array<string, array<string, mixed>> $checks
     */
    private static function checksPassed(array $checks): bool
    {
        foreach ($checks as $name => $check) {
            if ($name === 'package_registry') {
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

<?php

declare(strict_types=1);

namespace Larena\Core\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Larena\Core\Console\Commands\ClusterSmokeCommand;
use Larena\Core\Console\Commands\DataContentSmokeCommand;
use Larena\Core\Console\Commands\DoctorCommand;
use Larena\Core\Console\Commands\InstallCommand;
use Larena\Core\Console\Commands\PackageRegistryCommand;
use Larena\Core\Console\Commands\RuntimeSecuritySmokeCommand;
use Larena\Core\Console\Commands\ValidatePackagesCommand;
use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\FirstRun\FirstRunCoordinator;
use Larena\Core\FirstRun\FirstRunPreflightService;
use Larena\Core\WebInstall\WebInstallCoordinator;
use Larena\Core\WebInstall\LaravelWebInstallDatabaseLifecycle;
use Larena\Core\WebInstall\WebInstallDatabaseLifecycle;
use Larena\Core\WebInstall\WebInstallStateStore;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/larena-core.php', 'larena-core');
        $this->app->bind(FirstRunCoordinator::class, static function (Application $app): FirstRunCoordinator {
            return new FirstRunCoordinator(
                $app->make(DatabaseManager::class)->connection(),
                $app->tagged(FirstRunContributor::class),
            );
        });

        $this->app->bind(FirstRunPreflightService::class, static function (Application $app): FirstRunPreflightService {
            return new FirstRunPreflightService(
                $app->make(DatabaseManager::class)->connection(),
                [
                    'storage' => $app->storagePath(),
                    'cache' => $app->bootstrapPath('cache'),
                    'database' => $app->databasePath(),
                ],
                $app->environment('testing'),
            );
        });

        $this->app->bind(WebInstallStateStore::class, static fn (Application $app): WebInstallStateStore => new WebInstallStateStore(
            $app->storagePath('app/private/larena-web-install'),
            (string) $app->make('config')->get('app.key'),
        ));

        $this->app->bind(WebInstallDatabaseLifecycle::class, static fn (Application $app): WebInstallDatabaseLifecycle => new LaravelWebInstallDatabaseLifecycle(
            $app,
            $app->make('config'),
            $app->make(DatabaseManager::class),
            $app->make('migrator'),
        ));

        $this->app->bind(WebInstallCoordinator::class, static function (Application $app): WebInstallCoordinator {
            $fault = getenv('LARENA_CORE_WEB_INSTALL_TEST_FAULT_CHECKPOINT');
            $faultsEnabled = filter_var(
                getenv('LARENA_CORE_WEB_INSTALL_TEST_FAULTS_ENABLED') ?: false,
                FILTER_VALIDATE_BOOL,
            );
            $allowed = [
                'before_configuration_activation',
                'after_configuration_activation',
                'before_completed_state_persistence',
                'after_completed_state_persistence',
            ];
            $hook = $app->environment(['local', 'testing'])
                && $faultsEnabled
                && is_string($fault) && in_array($fault, $allowed, true)
                ? static function (string $checkpoint) use ($fault): void {
                    if ($checkpoint === $fault) {
                        if (function_exists('posix_kill')) {
                            posix_kill(getmypid(), defined('SIGKILL') ? SIGKILL : 9);
                        }
                        exit(91);
                    }
                }
                : null;
            return new WebInstallCoordinator(
                $app->make(WebInstallDatabaseLifecycle::class),
                $app->make(WebInstallStateStore::class),
                (string) $app->make('config')->get('app.key'),
                $hook,
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ClusterSmokeCommand::class,
            DataContentSmokeCommand::class,
            DoctorCommand::class,
            InstallCommand::class,
            PackageRegistryCommand::class,
            RuntimeSecuritySmokeCommand::class,
            ValidatePackagesCommand::class,
        ]);
    }
}

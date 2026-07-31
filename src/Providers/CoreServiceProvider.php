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

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

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

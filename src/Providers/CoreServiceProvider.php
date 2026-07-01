<?php

declare(strict_types=1);

namespace Larena\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Larena\Core\Console\Commands\ClusterSmokeCommand;
use Larena\Core\Console\Commands\DataContentSmokeCommand;
use Larena\Core\Console\Commands\DoctorCommand;
use Larena\Core\Console\Commands\InstallCommand;
use Larena\Core\Console\Commands\PackageRegistryCommand;
use Larena\Core\Console\Commands\RuntimeSecuritySmokeCommand;
use Larena\Core\Console\Commands\ValidatePackagesCommand;

final class CoreServiceProvider extends ServiceProvider
{
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

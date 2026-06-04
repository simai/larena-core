<?php

declare(strict_types=1);

namespace Larena\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Larena\Core\Console\Commands\DoctorCommand;
use Larena\Core\Console\Commands\InstallCommand;
use Larena\Core\Console\Commands\PackageRegistryCommand;
use Larena\Core\Console\Commands\RuntimeSecuritySmokeCommand;

final class CoreServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            DoctorCommand::class,
            InstallCommand::class,
            PackageRegistryCommand::class,
            RuntimeSecuritySmokeCommand::class,
        ]);
    }
}

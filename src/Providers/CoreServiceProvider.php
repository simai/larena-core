<?php

declare(strict_types=1);

namespace Larena\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Larena\Core\Console\Commands\ClusterSmokeCommand;
use Larena\Core\Console\Commands\DataContentSmokeCommand;
use Larena\Core\Console\Commands\DoctorCommand;
use Larena\Core\Console\Commands\InstallCommand;
use Larena\Core\Console\Commands\OperationCoverageCommand;
use Larena\Core\Console\Commands\PackageRegistryCommand;
use Larena\Core\Console\Commands\RuntimeSecuritySmokeCommand;
use Larena\Core\Console\Commands\ValidatePackagesCommand;
use Larena\Core\Providers\Registrars\EnvironmentRegistrar;
use Larena\Core\Providers\Registrars\InstallationRegistrar;
use Larena\Core\Providers\Registrars\OperationRegistrar;
use Larena\Core\Providers\Registrars\ScopeModelRegistrar;
use Larena\Core\SitePack\SitePackLimits;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/larena-core.php', 'larena-core');
        ScopeModelRegistrar::register($this->app);
        OperationRegistrar::register($this->app);
        EnvironmentRegistrar::register($this->app);
        InstallationRegistrar::register($this->app);
        // Config only: SitePack limits touch no database.
        $this->app->singleton(SitePackLimits::class, static fn ($app): SitePackLimits => SitePackLimits::fromConfig(
            (array) $app['config']->get('larena-core.sitepack', []),
        ));
    }

    public function boot(): void
    {
        // The guarded installer applies and rolls back exactly the bootstrap
        // migrations in database/migrations, so platform schema that is not part
        // of the installer bootstrap lives in its own registered path.
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/platform');

        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ClusterSmokeCommand::class,
            DataContentSmokeCommand::class,
            DoctorCommand::class,
            InstallCommand::class,
            OperationCoverageCommand::class,
            PackageRegistryCommand::class,
            RuntimeSecuritySmokeCommand::class,
            ValidatePackagesCommand::class,
        ]);
    }
}

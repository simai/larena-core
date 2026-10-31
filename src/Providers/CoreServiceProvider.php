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
use Larena\Core\Console\Commands\SitesCacheCommand;
use Larena\Core\Console\Commands\ValidatePackagesCommand;
use Larena\Core\Features\FeatureSet;
use Larena\Core\Process\ProcessExecutor;
use Larena\Core\Process\ProcOpenProcessExecutor;
use Larena\Core\Providers\Registrars\EnvironmentRegistrar;
use Larena\Core\Providers\Registrars\InstallationRegistrar;
use Larena\Core\Providers\Registrars\OperationRegistrar;
use Larena\Core\Providers\Registrars\ScopeModelRegistrar;
use Larena\Core\Providers\Registrars\SiteRegistrar;
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
        SiteRegistrar::register($this->app);
        // Config only: SitePack limits touch no database.
        $this->app->singleton(SitePackLimits::class, static fn ($app): SitePackLimits => SitePackLimits::fromConfig(
            (array) $app['config']->get('larena-core.sitepack', []),
        ));
        // Every package starts external programs through this one shell-free executor.
        $this->app->singleton(ProcessExecutor::class, ProcOpenProcessExecutor::class);
        // The features the product chose (config/larena-product.php); packages declare theirs as they boot.
        $this->app->singleton(FeatureSet::class, static fn ($app): FeatureSet => FeatureSet::fromConfig(
            $app['config']->get(FeatureSet::CONFIG_KEY),
        ));
    }

    public function boot(): void
    {
        // The guarded installer applies and rolls back exactly the bootstrap
        // migrations in database/migrations, so platform schema that is not part
        // of the installer bootstrap lives in its own registered path.
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/platform');
        SiteRegistrar::boot($this->app);
        // Once every package has declared its features, the product's choice is checked against them.
        $this->app->booted(static function ($app): void {
            $app->make(FeatureSet::class)->validate();
        });

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
            SitesCacheCommand::class,
            ValidatePackagesCommand::class,
        ]);
    }
}

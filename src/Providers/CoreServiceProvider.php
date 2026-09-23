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
use Larena\Core\Console\Commands\OperationCoverageCommand;
use Larena\Core\Console\Commands\PackageRegistryCommand;
use Larena\Core\Console\Commands\RuntimeSecuritySmokeCommand;
use Larena\Core\Console\Commands\ValidatePackagesCommand;
use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\FirstRun\FirstRunCoordinator;
use Larena\Core\FirstRun\FirstRunPreflightService;
use Larena\Core\Contracts\ConfirmationPolicy;
use Larena\Core\Contracts\EnvironmentProfile;
use Larena\Core\Contracts\MembershipResolver;
use Larena\Core\Contracts\OperationRegistry;
use Larena\Core\Contracts\TopologyBinding;
use Larena\Core\Contracts\TransportResolver;
use Larena\Core\Contracts\PlaneRegistry;
use Larena\Core\Contracts\ScopeRefResolver;
use Larena\Core\Contracts\ScopeRegistry;
use Larena\Core\Plane\DatabaseMembershipResolver;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Registry\CoreOperationProvider;
use Larena\Core\Runtime\DeclaredEnvironmentProfile;
use Larena\Core\Runtime\EnvironmentOperationHandlers;
use Larena\Core\Runtime\HostEnvironmentDetector;
use Larena\Core\Runtime\SolutionManifestValidator;
use Larena\Core\Runtime\SolutionOperationHandlers;
use Larena\Core\Runtime\SolutionPlanner;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Registry\PackageDescriptorFileValidator;
use Larena\Core\Contracts\OperationRuntime;
use Larena\Core\Runtime\LocalTransport;
use Larena\Core\Runtime\ResolvingTransportResolver;
use Larena\Core\Runtime\RiskClassConfirmationPolicy;
use Larena\Core\Runtime\StaticTopologyBinding;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Starter\ScopeBaselineInstaller;
use Larena\Core\WebInstall\WebInstallCoordinator;
use Larena\Core\WebInstall\LaravelWebInstallDatabaseLifecycle;
use Larena\Core\Runtime\CatalogOperationHandler;
use Larena\Core\Runtime\OperationHandlerCatalog;
use Larena\Core\Runtime\OperationRegistryOperationHandlers;
use Larena\Core\Runtime\PlaneOperationHandlers;
use Larena\Core\Runtime\ScopeOperationHandlers;
use Larena\Core\WebInstall\NullWebInstallPostMigrationHook;
use Larena\Core\WebInstall\WebInstallDatabaseLifecycle;
use Larena\Core\WebInstall\WebInstallPostMigrationHook;
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

        $this->app->singleton(DatabaseScopeRegistry::class, static fn (Application $app): DatabaseScopeRegistry => new DatabaseScopeRegistry(
            $app->make(DatabaseManager::class)->connection(),
        ));
        $this->app->alias(DatabaseScopeRegistry::class, ScopeRegistry::class);
        $this->app->alias(DatabaseScopeRegistry::class, ScopeRefResolver::class);

        $this->app->singleton(DatabasePlaneRegistry::class, static fn (Application $app): DatabasePlaneRegistry => new DatabasePlaneRegistry(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(DatabaseScopeRegistry::class),
        ));
        $this->app->alias(DatabasePlaneRegistry::class, PlaneRegistry::class);

        $this->app->singleton(DatabaseMembershipResolver::class, static fn (Application $app): DatabaseMembershipResolver => new DatabaseMembershipResolver(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(DatabasePlaneRegistry::class),
        ));
        $this->app->alias(DatabaseMembershipResolver::class, MembershipResolver::class);

        $this->app->singleton(ScopeBaselineInstaller::class, static fn (Application $app): ScopeBaselineInstaller => new ScopeBaselineInstaller(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(DatabaseScopeRegistry::class),
            $app->make(DatabasePlaneRegistry::class),
        ));

        // The registry is composed from declaration files at boot and holds no
        // state of its own, so a singleton is both correct and the cheapest
        // option; nothing here touches the database.
        $this->app->singleton(DeclaredOperationRegistry::class, static function (): DeclaredOperationRegistry {
            return DeclaredOperationRegistry::fromProviders([new CoreOperationProvider()]);
        });
        $this->app->alias(DeclaredOperationRegistry::class, OperationRegistry::class);

        $this->app->singleton(PackageDescriptorFileValidator::class);

        // Handler references to handlers, for executing registry operations
        // inside the application. Each package registers the references it
        // owns; core registers its own here.
        $this->app->singleton(OperationHandlerCatalog::class, static function (Application $app): OperationHandlerCatalog {
            $catalog = new OperationHandlerCatalog();
            $catalog->register('core.handler.scope', static fn (): ScopeOperationHandlers => new ScopeOperationHandlers($app->make(DatabaseScopeRegistry::class)));
            $catalog->register('core.handler.plane', static fn (): PlaneOperationHandlers => new PlaneOperationHandlers(
                $app->make(DatabasePlaneRegistry::class),
                $app->make(DatabaseMembershipResolver::class),
            ));
            $catalog->register('core.handler.operation_registry', static fn (): OperationRegistryOperationHandlers => new OperationRegistryOperationHandlers($app->make(OperationRegistry::class)));
            $catalog->register('core.handler.environment', static fn (): EnvironmentOperationHandlers => $app->make(EnvironmentOperationHandlers::class));
            $catalog->register('core.handler.solution', static fn (): SolutionOperationHandlers => $app->make(SolutionOperationHandlers::class));

            return $catalog;
        });
        $this->app->bind(CatalogOperationHandler::class, static fn (Application $app): CatalogOperationHandler => new CatalogOperationHandler(
            $app->make(OperationRegistry::class),
            $app->make(OperationHandlerCatalog::class),
        ));

        // Ordinary hosting is the default because it is the profile this platform
        // targets: no worker, no Redis, no search engine. An installation that has
        // more says so by declaring its own profile, rather than the platform
        // assuming capabilities it cannot see.
        $this->app->bindIf(
            EnvironmentProfile::class,
            static fn (): EnvironmentProfile => DeclaredEnvironmentProfile::ordinaryHosting(),
        );

        $this->app->singleton(HostEnvironmentDetector::class, static function (Application $app): HostEnvironmentDetector {
            return HostEnvironmentDetector::forHost($app->storagePath());
        });

        $this->app->singleton(EnvironmentOperationHandlers::class, static function (Application $app): EnvironmentOperationHandlers {
            return new EnvironmentOperationHandlers(
                $app->make(EnvironmentProfile::class),
                $app->make(HostEnvironmentDetector::class),
            );
        });
        $this->app->singleton(SolutionManifestValidator::class);

        // No entitlement resolver is bound here, and that is the fail-closed
        // default: a topology with more than one node is refused until something
        // outside core says the installation holds the distributed capability.
        // Planning itself stays free — a single-node plan never asks.
        $this->app->bindIf(SolutionPlanner::class, static function (Application $app): SolutionPlanner {
            return new SolutionPlanner(null, $app->make(EnvironmentProfile::class));
        });

        $this->app->singleton(SolutionOperationHandlers::class, static function (Application $app): SolutionOperationHandlers {
            return new SolutionOperationHandlers(
                $app->make(SolutionManifestValidator::class),
                $app->make(SolutionPlanner::class),
            );
        });

        $this->app->bindIf(ConfirmationPolicy::class, RiskClassConfirmationPolicy::class);

        $this->app->bindIf(TopologyBinding::class, static fn (): TopologyBinding => new StaticTopologyBinding());

        // The local transport carries whatever operation runtime the application
        // composed. Core does not bind a default runtime, so resolving a
        // transport without one fails loudly here instead of quietly executing
        // through a half-built runtime somewhere later.
        $this->app->bind(LocalTransport::class, static fn (Application $app): LocalTransport => new LocalTransport(
            $app->make(OperationRuntime::class),
        ));

        // No network transport, node trust verifier or entitlement gate is bound
        // here: the open core ships none, and the resolver treats each absence
        // as a missing gate rather than as permission.
        $this->app->bind(TransportResolver::class, static fn (Application $app): TransportResolver => new ResolvingTransportResolver(
            $app->make(OperationRegistry::class),
            $app->make(LocalTransport::class),
            $app->make(TopologyBinding::class),
        ));

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

        $this->app->bindIf(WebInstallPostMigrationHook::class, NullWebInstallPostMigrationHook::class);

        $this->app->bind(WebInstallDatabaseLifecycle::class, static fn (Application $app): WebInstallDatabaseLifecycle => new LaravelWebInstallDatabaseLifecycle(
            $app,
            $app->make('config'),
            $app->make(DatabaseManager::class),
            $app->make('migrator'),
            $app->make(WebInstallPostMigrationHook::class),
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

<?php

declare(strict_types=1);

namespace Larena\Core\Providers\Registrars;

use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Contracts\ConfirmationPolicy;
use Larena\Core\Contracts\OperationRegistry;
use Larena\Core\Contracts\OperationRuntime;
use Larena\Core\Contracts\TopologyBinding;
use Larena\Core\Contracts\TransportResolver;
use Larena\Core\Plane\DatabaseMembershipResolver;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Registry\CoreOperationProvider;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Registry\PackageDescriptorFileValidator;
use Larena\Core\Runtime\CatalogOperationHandler;
use Larena\Core\Runtime\EnvironmentOperationHandlers;
use Larena\Core\Runtime\LocalTransport;
use Larena\Core\Runtime\OperationHandlerCatalog;
use Larena\Core\Runtime\OperationRegistryOperationHandlers;
use Larena\Core\Runtime\PlaneOperationHandlers;
use Larena\Core\Runtime\ResolvingTransportResolver;
use Larena\Core\Runtime\RiskClassConfirmationPolicy;
use Larena\Core\Runtime\ScopeOperationHandlers;
use Larena\Core\Runtime\SolutionOperationHandlers;
use Larena\Core\Runtime\StaticTopologyBinding;
use Larena\Core\Scope\DatabaseScopeRegistry;

/**
 * The operation registry, its handler catalog, confirmation and topology policies, and the transports.
 */
final class OperationRegistrar
{
    public static function register(Application $app): void
    {
        // The registry is composed from declaration files at boot and holds no
        // state of its own, so a singleton is both correct and the cheapest
        // option; nothing here touches the database.
        $app->singleton(DeclaredOperationRegistry::class, static function (): DeclaredOperationRegistry {
            return DeclaredOperationRegistry::fromProviders([new CoreOperationProvider()]);
        });
        $app->alias(DeclaredOperationRegistry::class, OperationRegistry::class);

        $app->singleton(PackageDescriptorFileValidator::class);

        // Handler references to handlers, for executing registry operations
        // inside the application. Each package registers the references it
        // owns; core registers its own here.
        $app->singleton(OperationHandlerCatalog::class, static function (Application $app): OperationHandlerCatalog {
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
        $app->bind(CatalogOperationHandler::class, static fn (Application $app): CatalogOperationHandler => new CatalogOperationHandler(
            $app->make(OperationRegistry::class),
            $app->make(OperationHandlerCatalog::class),
        ));

        $app->bindIf(ConfirmationPolicy::class, RiskClassConfirmationPolicy::class);

        $app->bindIf(TopologyBinding::class, static fn (): TopologyBinding => new StaticTopologyBinding());

        // The local transport carries whatever operation runtime the application
        // composed. Core does not bind a default runtime, so resolving a
        // transport without one fails loudly here instead of quietly executing
        // through a half-built runtime somewhere later.
        $app->bind(LocalTransport::class, static fn (Application $app): LocalTransport => new LocalTransport(
            $app->make(OperationRuntime::class),
        ));

        // No network transport, node trust verifier or entitlement gate is bound
        // here: the open core ships none, and the resolver treats each absence
        // as a missing gate rather than as permission.
        $app->bind(TransportResolver::class, static fn (Application $app): TransportResolver => new ResolvingTransportResolver(
            $app->make(OperationRegistry::class),
            $app->make(LocalTransport::class),
            $app->make(TopologyBinding::class),
        ));
    }
}

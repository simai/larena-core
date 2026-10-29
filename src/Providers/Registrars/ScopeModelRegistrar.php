<?php

declare(strict_types=1);

namespace Larena\Core\Providers\Registrars;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Larena\Core\Contracts\MembershipResolver;
use Larena\Core\Contracts\PlaneRegistry;
use Larena\Core\Contracts\ScopeRefResolver;
use Larena\Core\Contracts\ScopeRegistry;
use Larena\Core\Plane\DatabaseMembershipResolver;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;
use Larena\Core\Starter\ScopeBaselineInstaller;

/**
 * Scopes, their members and resources, planes and plane membership, and the scope baseline installer.
 */
final class ScopeModelRegistrar
{
    public static function register(Application $app): void
    {
        $app->singleton(DatabaseScopeRegistry::class, static fn (Application $app): DatabaseScopeRegistry => new DatabaseScopeRegistry(
            $app->make(DatabaseManager::class)->connection(),
        ));
        $app->alias(DatabaseScopeRegistry::class, ScopeRegistry::class);
        $app->alias(DatabaseScopeRegistry::class, ScopeRefResolver::class);
        $app->alias(DatabaseScopeRegistry::class, \Larena\Core\Contracts\ScopeLegacyAliases::class);
        // Which scopes a subject works in, and which resources belong to a scope (core scope-model).
        $app->singleton(\Larena\Core\Scope\DatabaseScopeMembershipRegistry::class, static fn (Application $app): \Larena\Core\Scope\DatabaseScopeMembershipRegistry => new \Larena\Core\Scope\DatabaseScopeMembershipRegistry(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(DatabaseScopeRegistry::class),
        ));
        $app->alias(\Larena\Core\Scope\DatabaseScopeMembershipRegistry::class, \Larena\Core\Contracts\ScopeMembershipRegistry::class);
        $app->singleton(\Larena\Core\Scope\DatabaseScopeResourceBindings::class, static fn (Application $app): \Larena\Core\Scope\DatabaseScopeResourceBindings => new \Larena\Core\Scope\DatabaseScopeResourceBindings(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(DatabaseScopeRegistry::class),
        ));
        $app->alias(\Larena\Core\Scope\DatabaseScopeResourceBindings::class, \Larena\Core\Contracts\ScopeResourceBindings::class);

        $app->singleton(DatabasePlaneRegistry::class, static fn (Application $app): DatabasePlaneRegistry => new DatabasePlaneRegistry(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(DatabaseScopeRegistry::class),
        ));
        $app->alias(DatabasePlaneRegistry::class, PlaneRegistry::class);

        $app->singleton(DatabaseMembershipResolver::class, static fn (Application $app): DatabaseMembershipResolver => new DatabaseMembershipResolver(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(DatabasePlaneRegistry::class),
        ));
        $app->alias(DatabaseMembershipResolver::class, MembershipResolver::class);

        // The product declares its root scope (larena-core.first_run.root_scope); the default is the Minimal CMS site:main.
        $app->singleton(ScopeBaselineInstaller::class, static function (Application $app): ScopeBaselineInstaller {
            $config = $app->make('config');
            $rootScope = $config->get('larena-core.first_run.root_scope', 'site:main');
            $label = $config->get('larena-core.first_run.root_scope_label', ScopeBaselineInstaller::DEFAULT_ROOT_SCOPE_LABEL);
            if (!is_string($rootScope) || !is_string($label) || trim($label) === '') {
                throw new \LogicException('The product root scope must be a scope reference with a label.');
            }

            return new ScopeBaselineInstaller(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(DatabaseScopeRegistry::class),
                $app->make(DatabasePlaneRegistry::class),
                ScopeRef::parse($rootScope),
                $label,
            );
        });
    }
}

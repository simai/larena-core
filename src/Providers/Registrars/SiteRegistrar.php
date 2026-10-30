<?php

declare(strict_types=1);

namespace Larena\Core\Providers\Registrars;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Larena\Core\Contracts\SiteBindings;
use Larena\Core\Contracts\SiteLanguages;
use Larena\Core\Enums\SiteEnvironment;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Site\ConfiguredSiteLanguages;
use Larena\Core\Site\CurrentLanguage;
use Larena\Core\Site\CurrentSite;
use Larena\Core\Site\DatabaseSiteBindings;
use Larena\Core\Site\ResolveSite;
use Larena\Core\Site\ResolveSiteLanguage;
use Larena\Core\Site\SiteLanguageFolders;
use Larena\Core\Site\SiteMapStore;
use Larena\Core\Site\SiteResolver;
use Larena\Core\Site\SiteScopes;
use Larena\Core\Starter\ScopeBaselineInstaller;

/**
 * Site domains, the compiled site map and the request's site (sites plan, stage 2). Core owns them so a site is
 * found with no admin or storage package installed.
 */
final class SiteRegistrar
{
    public const MAP_FILE = 'cache/larena-sites.php';

    public static function register(Application $app): void
    {
        $app->singleton(SiteMapStore::class, static function (Application $app): SiteMapStore {
            $config = $app->make('config');
            $environment = self::environment($config->get('larena-core.environment'), $config->get('app.env'));
            $store = $config->get('larena-core.sites.cache_store');
            $cache = $app->bound(CacheFactory::class)
                ? $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null)
                : null;

            return new SiteMapStore(
                static fn () => $app->make(DatabaseSiteBindings::class)->compileMap($environment),
                $environment,
                $cache,
                $app->bootstrapPath(self::MAP_FILE),
            );
        });
        $app->singleton(DatabaseSiteBindings::class, static fn (Application $app): DatabaseSiteBindings => new DatabaseSiteBindings(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(DatabaseScopeRegistry::class),
            $app->make(SiteMapStore::class),
        ));
        $app->alias(DatabaseSiteBindings::class, SiteBindings::class);
        $app->singleton(SiteScopes::class, static fn (Application $app): SiteScopes => new SiteScopes(
            $app->make(DatabaseScopeRegistry::class),
            $app->make(DatabaseManager::class)->connection(),
        ));
        $app->singleton(SiteResolver::class, static function (Application $app): SiteResolver {
            $scheme = $app->make('config')->get('larena-core.sites.scheme');

            return new SiteResolver(
                $app->make(SiteMapStore::class),
                $app->make(ScopeBaselineInstaller::class)->defaultSiteRef(),
                in_array($scheme, ['http', 'https'], true) ? $scheme : null,
            );
        });
        $app->bind(ResolveSite::class, static function (Application $app): ResolveSite {
            $except = $app->make('config')->get('larena-core.sites.redirect_except', ['admin']);

            return new ResolveSite(
                $app->make(SiteResolver::class),
                $app,
                array_values(array_filter(is_array($except) ? $except : [], 'is_string')),
            );
        });
        // A site's languages: the configuration's until whoever keeps the site's settings answers (the application).
        if (!$app->bound(SiteLanguages::class)) {
            $app->singleton(SiteLanguages::class, static function (Application $app): SiteLanguages {
                $config = $app->make('config');
                $languages = $config->get('larena-core.sites.languages', []);
                $main = $config->get('app.locale');

                return new ConfiguredSiteLanguages(array_values(array_filter(is_array($languages) ? $languages : [], 'is_string')), is_string($main) && $main !== '' ? $main : 'en');
            });
        }
        $app->singleton(SiteLanguageFolders::class);
        $app->bind(ResolveSiteLanguage::class, static fn (Application $app): ResolveSiteLanguage => new ResolveSiteLanguage($app->make(SiteLanguages::class), $app));
        // Outside a request (console, queue) the language is the default site's main one.
        $app->bind(CurrentLanguage::class, static function (Application $app): CurrentLanguage {
            $site = $app->make(CurrentSite::class)->site->toString();

            return new CurrentLanguage(null, '/', '', static fn (): string => $app->make(SiteLanguages::class)->main($site));
        });
        // Outside a request (console, queue) the site is the default one; ResolveSite replaces this per request.
        $app->bind(CurrentSite::class, static fn (Application $app): CurrentSite => CurrentSite::byDefault(
            $app->make(SiteResolver::class)->defaultSite(),
        ));
    }

    /**
     * Every request finds its site before routing: ResolveSite is global HTTP middleware, so an old domain is sent to
     * the main one for any path, not only for paths a route answers (the router answers 404 before a group runs). Its
     * language follows, before the redirects, which the application adds later.
     */
    public static function boot(Application $app): void
    {
        if ($app->make('config')->get('larena-core.sites.middleware', true) !== true) {
            return;
        }
        $kernel = $app->bound(\Illuminate\Contracts\Http\Kernel::class) ? $app->make(\Illuminate\Contracts\Http\Kernel::class) : null;
        if (is_object($kernel) && method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(ResolveSite::class);
            $kernel->pushMiddleware(ResolveSiteLanguage::class);
        }
    }

    /**
     * `larena-core.environment` when set (production, staging or development), else APP_ENV: production is
     * production, local and testing are development, anything else is staging.
     */
    public static function environment(mixed $configured, mixed $appEnv): SiteEnvironment
    {
        if (is_string($configured) && trim($configured) !== '') {
            return SiteEnvironment::tryFrom(strtolower(trim($configured)))
                ?? throw new \LogicException('larena-core.environment must be production, staging or development.');
        }

        return SiteEnvironment::fromAppEnv(is_string($appEnv) ? $appEnv : 'production');
    }
}

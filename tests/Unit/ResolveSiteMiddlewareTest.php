<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';
require_once __DIR__ . '/../support/http-runtime.php';

larena_core_require_http_runtime();

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\SiteEnvironment;
use Larena\Core\Enums\SiteMatch;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;
use Larena\Core\Contracts\SiteLanguages;
use Larena\Core\Site\ConfiguredSiteLanguages;
use Larena\Core\Site\CurrentLanguage;
use Larena\Core\Site\CurrentSite;
use Larena\Core\Site\DatabaseSiteBindings;
use Larena\Core\Site\ResolveSite;
use Larena\Core\Site\ResolveSiteLanguage;
use Larena\Core\Site\SiteLanguageFolders;
use Larena\Core\Site\SiteMap;
use Larena\Core\Site\SiteMapStore;
use Larena\Core\Site\SiteResolver;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$cache = new Repository(new ArrayStore());
$reader = new DatabaseSiteBindings($connection, $scopes);
$storeFor = static fn (): SiteMapStore => new SiteMapStore(
    static fn (): SiteMap => $reader->compileMap(SiteEnvironment::Production),
    SiteEnvironment::Production,
    $cache,
);
$store = $storeFor();
$bindings = new DatabaseSiteBindings($connection, $scopes, $store);
$root = ScopeRef::of(ScopeKind::Site, 'main');
$shop = ScopeRef::of(ScopeKind::Site, 'shop');
$scopes->create($root, 'Main site', 'actor:installer');
$scopes->create($shop, 'Shop', 'actor:installer');
$bindings->replace($root, [['host' => 'example.ru'], ['host' => 'www.example.ru', 'role' => 'redirect']], 'actor:admin');
$bindings->replace($shop, [['host' => 'shop.example.ru']], 'actor:admin');

$container = new Container();
$middleware = new ResolveSite(new SiteResolver($store, $root), $container);
$probe = new stdClass();
$probe->request = null;
$next = static function (Request $request) use ($probe): Response {
    $probe->request = $request;

    return new Response('page');
};
$handle = static function (string $url, string $method = 'GET', array $server = []) use ($middleware, $next, $probe) {
    $probe->request = null;

    return $middleware->handle(Request::create($url, $method, [], [], [], $server), $next);
};

// The site goes into the container and the request; the page is served.
$response = $handle('http://shop.example.ru/item?id=1');
assert($response->getStatusCode() === 200 && $probe->request instanceof Request);
$current = $container->make(CurrentSite::class);
assert($current->site->equals($shop) && $current->matched === SiteMatch::Exact && $probe->request->attributes->get(CurrentSite::ATTRIBUTE) === $current);
$handle('http://nowhere.org/');
assert($container->make(CurrentSite::class)->matched === SiteMatch::Default && $container->make(CurrentSite::class)->site->equals($root));

// A redirect domain answers 301 to the main one, built from stored domains, not from the Host header.
$response = $handle('https://www.example.ru/a/b?x=1');
assert($response->getStatusCode() === 301 && $response->headers->get('Location') === 'https://example.ru/a/b?x=1' && $probe->request === null);
$response = $handle('http://www.example.ru/a', 'HEAD');
assert($response->getStatusCode() === 301 && $response->headers->get('Location') === 'http://example.ru/a');
$response = $handle('https://www.example.ru/a', 'GET', ['HTTP_X_FORWARDED_HOST' => 'evil.example']);
assert($response->headers->get('Location') === 'https://example.ru/a');

$served = [];
// The admin and anything but GET or HEAD stay where they arrive; the site is still known.
foreach ([['https://www.example.ru/admin', 'GET'], ['https://www.example.ru/admin/sites/1', 'GET'], ['https://www.example.ru/form', 'POST']] as [$url, $method]) {
    $response = $handle($url, $method);
    assert($response->getStatusCode() === 200, $url);
    $served[] = $container->make(CurrentSite::class)->site->toString();
}
assert($served === ['site:main', 'site:main', 'site:main']);
assert($handle('https://www.example.ru/administrator')->getStatusCode() === 301, 'the exception is segment-bounded');

// The map is kept in the configured cache: a new process finds sites with no database query.
assert(is_array($cache->get(SiteMapStore::CACHE_KEY.'production')));
$connection->enableQueryLog();
$other = new ResolveSite(new SiteResolver($storeFor(), $root), $container);
$other->handle(Request::create('http://shop.example.ru/'), $next);
$other->handle(Request::create('http://www.example.ru/'), $next);
assert($connection->getQueryLog() === [] && $container->make(CurrentSite::class)->site->equals($shop));
$connection->disableQueryLog();

// A write clears the cache for every process: the next one compiles the new domains.
$bindings->replace($shop, [['host' => 'store.example.ru']], 'actor:admin');
assert(!$cache->has(SiteMapStore::CACHE_KEY.'production'), 'a write clears the cached map');
$later = new SiteResolver($storeFor(), $root);
assert($later->resolve('store.example.ru', '/')->site->equals($shop) && $later->resolve('shop.example.ru', '/')->site->equals($root));

// What the container keeps for a request goes when another site comes, and stays while the site is the same.
$scopedContainer = new Container();
$scopedContainer->scoped('kept', static fn (): stdClass => new stdClass());
$scopedMiddleware = new ResolveSite(new SiteResolver($storeFor(), $root), $scopedContainer);
$scopedMiddleware->handle(Request::create('http://example.ru/'), $next);
$kept = $scopedContainer->make('kept');
$scopedMiddleware->handle(Request::create('http://example.ru/page'), $next);
assert($scopedContainer->make('kept') === $kept, 'the same site keeps it');
$scopedMiddleware->handle(Request::create('http://store.example.ru/'), $next);
assert($scopedContainer->make('kept') !== $kept, 'another site does not');

// The request's language (sites plan, step 4.4): the domain's, else a language's folder, else the main one.
$languages = new class implements SiteLanguages {
    public int $asked = 0;

    public function languages(string $siteRef): array
    {
        ++$this->asked;

        return ['ru', 'en', 'pt-BR'];
    }

    public function main(string $siteRef): string
    {
        ++$this->asked;

        return 'ru';
    }

    public function mainInFolder(string $siteRef): bool
    {
        return false;
    }

    public function ownStructure(string $siteRef): bool
    {
        return false;
    }
};
$languageContainer = new Container();
$resolveLanguage = new ResolveSiteLanguage($languages, $languageContainer);
$of = static fn (string $path, ?string $locale = null, string $folder = ''): CurrentLanguage => $resolveLanguage->of(
    new CurrentSite($root, null, $locale, $folder, SiteMatch::Exact), $path);
$language = $of('/about');
assert($languages->asked === 0, 'an ordinary address reads no setting');
assert($language->address() === '/about' && $language->folder() === '' && $language->locale() === 'ru');
$language = $of('/en/about');
assert($language->locale() === 'en' && $language->address() === '/about' && $language->folder() === '/en');
$language = $of('/en');
assert($language->locale() === 'en' && $language->address() === '/');
$language = $of('/en/');
assert($language->locale() === 'en' && $language->address() === '/');
$language = $of('/pt-BR/a/b');
assert($language->locale() === 'pt-BR' && $language->address() === '/a/b');
// The main language has no folder; a code that is not the site's is an ordinary address.
$language = $of('/ru/about');
assert($language->locale() === 'ru' && $language->address() === '/ru/about' && $language->folder() === '');
$language = $of('/de/about');
assert($language->locale() === 'ru' && $language->address() === '/de/about');
// A domain's language wins, and its addresses have no folder.
$language = $of('/en/about', 'en');
assert($language->locale() === 'en' && $language->address() === '/en/about' && $language->folder() === '');
// The site's folder comes off first.
$language = $of('/shop/en/about', null, '/shop');
assert($language->locale() === 'en' && $language->address() === '/about');
$language = $of('/shop', null, '/shop');
assert($language->locale() === 'ru' && $language->address() === '/');
// The middleware leaves it in the request and the container, after the site.
$request = Request::create('http://example.ru/en/page');
$request->attributes->set(CurrentSite::ATTRIBUTE, new CurrentSite($root, null, null, '', SiteMatch::Default));
$resolveLanguage->handle($request, $next);
assert($request->attributes->get(CurrentLanguage::ATTRIBUTE) === $languageContainer->make(CurrentLanguage::class) && $languageContainer->make(CurrentLanguage::class)->address() === '/page');
// Where a language's addresses stand, for whatever writes one.
$folders = new SiteLanguageFolders($languages);
assert($folders->prefix('site:main', 'ru') === '' && $folders->prefix('site:main', 'en') === '/en');
assert($folders->address('site:main', 'en', '/about') === '/en/about' && $folders->address('site:main', 'en', '/') === '/en' && $folders->address('site:main', 'ru', '/') === '/');
$configured = new ConfiguredSiteLanguages(['en'], 'ru');
assert($configured->languages('site:main') === ['ru', 'en'] && $configured->main('site:main') === 'ru' && $configured->mainInFolder('site:main') === false);

echo "ResolveSiteMiddlewareTest passed.\n";

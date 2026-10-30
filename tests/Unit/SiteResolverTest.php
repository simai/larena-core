<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\SiteEnvironment;
use Larena\Core\Enums\SiteMatch;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;
use Larena\Core\Site\CurrentSite;
use Larena\Core\Site\DatabaseSiteBindings;
use Larena\Core\Site\SiteMap;
use Larena\Core\Site\SiteMapStore;
use Larena\Core\Site\SiteResolver;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$file = sys_get_temp_dir().'/larena-core-sites-'.bin2hex(random_bytes(4)).'/larena-sites.php';
$reader = new DatabaseSiteBindings($connection, $scopes);
$compiles = 0;
$store = new SiteMapStore(static function () use ($reader, &$compiles): SiteMap {
    $compiles++;

    return $reader->compileMap(SiteEnvironment::Production);
}, SiteEnvironment::Production, null, $file);
$bindings = new DatabaseSiteBindings($connection, $scopes, $store);
$root = ScopeRef::of(ScopeKind::Site, 'main');
$resolver = new SiteResolver($store, $root);
$site = static fn (string $identifier): ScopeRef => ScopeRef::of(ScopeKind::Site, $identifier);
foreach (['main', 'shop', 'blog', 'land', 'docs', 'fallback'] as $identifier) {
    $scopes->create($site($identifier), ucfirst($identifier), 'actor:installer');
}

// No domains at all: everything is the product's root site, as before domains existed.
$current = $resolver->resolve('anything.example', '/a/b');
assert($current->site->equals($root) && $current->binding === null && $current->matched === SiteMatch::Default && $current->folder === '');
assert($resolver->redirectTarget($current, '/a', 'x=1', true) === null);

$bindings->replace($site('main'), [
    ['host' => 'example.ru'],
    ['host' => 'www.example.ru', 'role' => 'redirect'],
    ['host' => 'example.com', 'locale' => 'en', 'role' => 'main'],
    ['host' => 'old.example.com', 'locale' => 'en', 'role' => 'redirect'],
    ['host' => 'old.example.de', 'locale' => 'de', 'role' => 'redirect'],
    ['host' => 'mirror.example.ru', 'role' => 'alias'],
    ['host' => 'example.test', 'environment' => 'development', 'role' => 'main'],
], 'actor:admin');
$bindings->replace($site('shop'), [
    ['host' => 'example.ru', 'folder' => '/shop'],
    ['host' => 'example.ru', 'folder' => '/shop/outlet', 'role' => 'alias'],
    ['host' => 'shop-old.ru', 'folder' => '/catalog', 'role' => 'redirect'],
], 'actor:admin');
$bindings->replace($site('blog'), [['host' => '*.example.ru'], ['host' => '*.example.ru', 'folder' => '/news', 'role' => 'alias']], 'actor:admin');
$bindings->replace($site('land'), [['host' => '*.promo.example.ru'], ['host' => '*.legacy.ru', 'role' => 'redirect']], 'actor:admin');
$bindings->replace($site('docs'), [['host' => '*.docs.ru'], ['host' => 'docs.ru', 'folder' => '/api', 'role' => 'alias'],
    ['host' => 'x.example.ru', 'folder' => '/api', 'role' => 'alias']], 'actor:admin');

// host, path => site, how, folder, locale
$cases = [
    ['example.ru', '/', 'main', SiteMatch::Exact, '', null],
    ['EXAMPLE.RU', '/about', 'main', SiteMatch::Exact, '', null],
    ['example.ru.', '/about', 'main', SiteMatch::Exact, '', null],
    ['example.ru', '/shop', 'shop', SiteMatch::Exact, '/shop', null],
    ['example.ru', '/shop/', 'shop', SiteMatch::Exact, '/shop', null],
    ['example.ru', '/shop/item/1', 'shop', SiteMatch::Exact, '/shop', null],
    ['example.ru', '/shop/outlet/x', 'shop', SiteMatch::Exact, '/shop/outlet', null],
    ['example.ru', '/shopping', 'main', SiteMatch::Exact, '', null],
    ['example.ru', 'shop/a', 'shop', SiteMatch::Exact, '/shop', null],
    ['example.com', '/', 'main', SiteMatch::Exact, '', 'en'],
    ['mirror.example.ru', '/x', 'main', SiteMatch::Exact, '', null],
    ['a.example.ru', '/', 'blog', SiteMatch::Wildcard, '', null],
    ['a.b.c.example.ru', '/post', 'blog', SiteMatch::Wildcard, '', null],
    ['a.example.ru', '/news/1', 'blog', SiteMatch::Wildcard, '/news', null],
    ['x.promo.example.ru', '/', 'land', SiteMatch::Wildcard, '', null],
    ['promo.example.ru', '/', 'blog', SiteMatch::Wildcard, '', null],
    ['legacy.ru', '/', 'main', SiteMatch::Default, '', null],
    ['docs.ru', '/api/v', 'docs', SiteMatch::Exact, '/api', null],
    ['docs.ru', '/guide', 'main', SiteMatch::Default, '', null],
    ['a.docs.ru', '/guide', 'docs', SiteMatch::Wildcard, '', null],
    ['x.example.ru', '/api/a', 'docs', SiteMatch::Exact, '/api', null],
    ['x.example.ru', '/other', 'blog', SiteMatch::Wildcard, '', null],
    ['shop-old.ru', '/', 'main', SiteMatch::Default, '', null],
    ['unknown.org', '/', 'main', SiteMatch::Default, '', null],
    ['example.test', '/', 'main', SiteMatch::Default, '', null],
];
foreach ($cases as [$host, $path, $expected, $how, $folder, $locale]) {
    $current = $resolver->resolve($host, $path);
    $label = $host.$path;
    assert($current->site->equals($site($expected)), $label.' site '.$current->site->toString());
    assert($current->matched === $how && $current->folder === $folder && $current->locale === $locale, $label);
}
assert($resolver->resolve('example.ru', '/shop/item/1')->pathInSite('/shop/item/1') === '/item/1');
assert($resolver->resolve('example.ru', '/shop')->pathInSite('/shop') === '/');

// Redirect domains go to the main domain of the same site and language, path and query kept, folders swapped.
$redirect = static fn (string $host, string $path, ?string $query = null, bool $secure = true): ?string
    => $resolver->redirectTarget($resolver->resolve($host, $path), $path, $query, $secure);
assert($redirect('www.example.ru', '/a/b', 'x=1&y=2') === 'https://example.ru/a/b?x=1&y=2');
assert($redirect('www.example.ru', '/', null, false) === 'http://example.ru/');
assert($redirect('old.example.com', '/page') === 'https://example.com/page', 'same language');
assert($redirect('old.example.de', '/page') === 'https://example.ru/page', 'no German main: the every-language one');
assert($redirect('shop-old.ru', '/catalog/item/7', 'c=1') === 'https://example.ru/shop/item/7?c=1');
assert($redirect('shop-old.ru', '/catalog') === 'https://example.ru/shop');
assert($redirect('www.example.ru', "/a b/\xD0\xBF\r\n") === 'https://example.ru/a%20b/%D0%BF%0D%0A');
assert($redirect('x.legacy.ru', '/a') === null, 'a wildcard main has no concrete host');
assert($redirect('example.ru', '/a') === null && $redirect('mirror.example.ru', '/a') === null && $redirect('unknown.org', '/') === null);
$forced = new SiteResolver($store, $root, 'https');
assert($forced->redirectTarget($forced->resolve('www.example.ru', '/a'), '/a', null, false) === 'https://example.ru/a');

// The default site: the marked one, else the product's root scope.
$bindings->setDefaultSite($site('fallback'), 'actor:admin');
assert($resolver->resolve('unknown.org', '/')->site->equals($site('fallback')));
assert($resolver->defaultSite()->equals($site('fallback')));

// Once compiled, finding a site asks nothing of the database.
$resolver->resolve('example.ru', '/');
$connection->flushQueryLog();
$connection->enableQueryLog();
$before = $compiles;
foreach ($cases as [$host, $path]) {
    $resolver->resolve($host, $path);
}
assert($connection->getQueryLog() === [] && $compiles === $before);

// Every write clears the map: the next request sees the new domains.
$bindings->replace($site('blog'), [['host' => 'blog.example.ru']], 'actor:admin');
assert($resolver->resolve('a.example.ru', '/')->site->equals($site('fallback')));
assert($resolver->resolve('blog.example.ru', '/')->site->equals($site('blog')));
$connection->disableQueryLog();

// The exported file is read before the database and is removed by any write.
$store->writeFile();
assert(is_file($file) && SiteMap::fromArray(require $file, SiteEnvironment::Production) !== null);
$fresh = 0;
$fromFile = new SiteMapStore(static function () use (&$fresh): SiteMap {
    $fresh++;

    return SiteMap::empty(SiteEnvironment::Production);
}, SiteEnvironment::Production, null, $file);
assert((new SiteResolver($fromFile, $root))->resolve('blog.example.ru', '/')->site->equals($site('blog')) && $fresh === 0);
assert(SiteMap::fromArray(require $file, SiteEnvironment::Staging) === null, 'another environment ignores the file');
$bindings->setDefaultSite($site('main'), 'actor:admin');
assert(!file_exists($file), 'a write removes the exported map');
assert($store->clearFile() === false);
@rmdir(dirname($file));

// A map cannot be compiled (no database yet): the root site, and nothing kept.
$broken = new SiteMapStore(static fn (): SiteMap => throw new RuntimeException('no database'), SiteEnvironment::Production);
assert((new SiteResolver($broken, $root))->resolve('example.ru', '/')->site->equals($root));
assert(CurrentSite::byDefault($root)->pathInSite('/a') === '/a');

// Before the migration the map is empty.
$bare = new DatabaseSiteBindings(larena_core_scope_plane_connection_without_sites(), $scopes);
assert($bare->compileMap(SiteEnvironment::Production)->isEmpty());

echo "SiteResolverTest passed.\n";

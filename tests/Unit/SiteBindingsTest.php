<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\SiteBindingRole;
use Larena\Core\Enums\SiteEnvironment;
use Larena\Core\Exceptions\SiteBindingRejected;
use Larena\Core\Providers\Registrars\SiteRegistrar;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;
use Larena\Core\Site\DatabaseSiteBindings;
use Larena\Core\Site\SiteBindingRules;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$bindings = new DatabaseSiteBindings($connection, $scopes);
$main = ScopeRef::of(ScopeKind::Site, 'main');
$shop = ScopeRef::of(ScopeKind::Site, 'shop');
$old = ScopeRef::of(ScopeKind::Site, 'old');
$scopes->create($main, 'Main site', 'actor:installer');
$scopes->create($shop, 'Shop', 'actor:installer');
$scopes->create($old, 'Old', 'actor:installer');
$scopes->archive($old, 'actor:admin');

$code = static function (callable $call): ?string {
    try {
        $call();
    } catch (SiteBindingRejected $rejected) {
        return $rejected->reasonCode;
    }

    return null;
};
$rule = static fn (array $entries): ?string => $code(static fn () => SiteBindingRules::normalize($main, $entries));

// Hosts: lowercase DNS names or one leading `*.` label, punycode for international names; no scheme, port or path.
foreach (['example.ru', 'Example.RU', 'a-b.example.ru', '*.example.ru', 'xn--80aswg.xn--p1ai', 'localhost', '*.larena.test'] as $host) {
    assert($rule([['host' => $host]]) === null, $host);
}
foreach (['', 'https://example.ru', 'example.ru:8080', 'example.ru/shop', 'сайт.рф', '-a.example.ru', 'a-.example.ru', 'a..ru',
    'example.ru.', '*example.ru', 'a.*.example.ru', '*.*.example.ru', '*.', str_repeat('a', 64).'.ru', 'exa mple.ru', 'a_b.ru'] as $host) {
    assert($rule([['host' => $host]]) === SiteBindingRejected::HOST_INVALID, $host);
}
assert($rule([['host' => 42]]) === SiteBindingRejected::HOST_INVALID && $rule([[]]) === SiteBindingRejected::HOST_INVALID);
assert(SiteBindingRules::normalize($main, [['host' => ' Example.RU ']])[0]->host === 'example.ru');

// Folders: empty, or lowercase segments without a trailing slash.
foreach (['', null, '/shop', '/shop/en', '/a_b-1'] as $folder) {
    assert($rule([['host' => 'example.ru', 'folder' => $folder]]) === null);
}
foreach (['shop', '/shop/', '/', '/Shop', '/shop//en', '/sh op', '/../x', '/shop?x'] as $folder) {
    assert($rule([['host' => 'example.ru', 'folder' => $folder]]) === SiteBindingRejected::FOLDER_INVALID, $folder);
}
assert($rule([['host' => 'example.ru', 'locale' => 'EN']]) === SiteBindingRejected::LOCALE_INVALID);
assert($rule([['host' => 'example.ru', 'locale' => 'pt-br']]) === null);
assert($rule([['host' => 'a.ru'], ['host' => 'b.ru', 'role' => 'primary']]) === SiteBindingRejected::ROLE_INVALID);
assert($rule([['host' => 'a.ru', 'environment' => 'prod']]) === SiteBindingRejected::ENVIRONMENT_INVALID);
assert($rule(['host' => 'a.ru']) === SiteBindingRejected::LIST_INVALID && $rule(['not an entry']) === SiteBindingRejected::LIST_INVALID);

// The first entry is the main one whatever it says; the rest default to alias in production.
$normalized = SiteBindingRules::normalize($main, [['host' => 'a.ru', 'role' => 'redirect'], ['host' => 'b.ru']]);
assert($normalized[0]->role === SiteBindingRole::Main && $normalized[1]->role === SiteBindingRole::Alias);
assert($normalized[1]->environment === SiteEnvironment::Production && $normalized[1]->position === 1);

// One main per language and environment; an entry without a language stands for every language.
assert($rule([['host' => 'a.ru'], ['host' => 'b.ru', 'role' => 'main']]) === SiteBindingRejected::MAIN_DUPLICATE);
assert($rule([['host' => 'a.ru'], ['host' => 'a.com', 'locale' => 'en', 'role' => 'main']]) === null);
assert($rule([['host' => 'a.ru'], ['host' => 'a.com', 'locale' => 'en', 'role' => 'main'], ['host' => 'b.com', 'locale' => 'en', 'role' => 'main']])
    === SiteBindingRejected::MAIN_DUPLICATE);
assert($rule([['host' => 'a.ru'], ['host' => 'a.com', 'locale' => 'en', 'role' => 'alias']]) === null, 'every-language main covers en');
assert($rule([['host' => 'a.com', 'locale' => 'en'], ['host' => 'a.ru', 'role' => 'alias']]) === SiteBindingRejected::MAIN_MISSING);
assert($rule([['host' => 'a.ru'], ['host' => 'a.ru', 'environment' => 'staging', 'role' => 'main']]) === null, 'each environment its main');
assert($rule([['host' => 'a.ru'], ['host' => 'b.ru', 'environment' => 'staging']]) === SiteBindingRejected::MAIN_MISSING);
assert($rule([['host' => 'a.ru'], ['host' => 'A.ru']]) === SiteBindingRejected::DUPLICATE);
assert($rule([['host' => 'a.ru'], ['host' => 'a.ru', 'folder' => '/shop']]) === null);
assert($rule([['host' => 'a.ru'], ['host' => 'a.ru', 'environment' => 'development', 'role' => 'main']]) === null);

// The site must be a live site scope.
assert($code(static fn () => $bindings->replace(ScopeRef::of(ScopeKind::Site, 'nope'), [['host' => 'x.ru']], 'actor:admin')) === SiteBindingRejected::SITE_UNKNOWN);
assert($code(static fn () => $bindings->replace(ScopeRef::of(ScopeKind::Organization, 'main'), [['host' => 'x.ru']], 'actor:admin')) === SiteBindingRejected::SITE_UNKNOWN);
assert($code(static fn () => $bindings->replace($old, [['host' => 'x.ru']], 'actor:admin')) === SiteBindingRejected::SITE_ARCHIVED);

// The whole list is written at once, in its order; a host and folder belong to one site per environment.
$saved = $bindings->replace($main, [
    ['host' => 'example.ru'],
    ['host' => 'www.example.ru', 'role' => 'redirect'],
    ['host' => 'example.com', 'locale' => 'en', 'role' => 'main'],
    ['host' => 'example.test', 'environment' => 'development', 'role' => 'main'],
], 'actor:admin');
assert(array_map(static fn ($binding): string => $binding->host, $saved) === ['example.ru', 'www.example.ru', 'example.com', 'example.test']);
assert($saved[0]->folder === null && $saved[2]->locale === 'en' && $saved[3]->environment === SiteEnvironment::Development);
assert($code(static fn () => $bindings->replace($shop, [['host' => 'example.ru']], 'actor:admin')) === SiteBindingRejected::TAKEN);
assert($code(static fn () => $bindings->replace($shop, [['host' => 'shop.ru'], ['host' => 'www.example.ru']], 'actor:admin')) === SiteBindingRejected::TAKEN);
assert($bindings->list($shop) === [], 'a refused list writes nothing');
$bindings->replace($shop, [['host' => 'example.ru', 'folder' => '/shop'], ['host' => 'example.ru', 'environment' => 'staging', 'role' => 'main']], 'actor:admin');
assert(count($bindings->list($shop)) === 2 && count($bindings->all(SiteEnvironment::Production)) === 4 && count($bindings->all()) === 6);

// Saving again keeps who created a domain; dropping a domain frees it for another site.
$connection->table(DatabaseSiteBindings::TABLE)->where('host', 'example.ru')->where('site_ref', 'site:main')->update(['created_by' => 'actor:first']);
$bindings->replace($main, [['host' => 'example.ru'], ['host' => 'example.org']], 'actor:editor');
$row = $connection->table(DatabaseSiteBindings::TABLE)->where('host', 'example.ru')->where('site_ref', 'site:main')->first();
assert($row->created_by === 'actor:first' && $row->updated_by === 'actor:editor' && $row->folder === '');
assert($code(static fn () => $bindings->replace($shop, [['host' => 'www.example.ru']], 'actor:admin')) === null);
assert($bindings->replace($shop, [], 'actor:admin') === [] && $bindings->list($shop) === []);

// The default site is marked once and must be a live site.
assert($bindings->defaultSite() === null);
$bindings->setDefaultSite($shop, 'actor:admin');
$bindings->setDefaultSite($main, 'actor:admin');
assert($bindings->defaultSite()?->equals($main) === true && $connection->table(DatabaseSiteBindings::DEFAULT_TABLE)->count() === 1);
assert($code(static fn () => $bindings->setDefaultSite($old, 'actor:admin')) === SiteBindingRejected::SITE_ARCHIVED);

// The environment: configured, else APP_ENV mapped.
assert(SiteRegistrar::environment(null, 'production') === SiteEnvironment::Production);
assert(SiteRegistrar::environment('', 'local') === SiteEnvironment::Development);
assert(SiteRegistrar::environment(null, 'testing') === SiteEnvironment::Development);
assert(SiteRegistrar::environment(null, 'stage') === SiteEnvironment::Staging);
assert(SiteRegistrar::environment('Staging', 'production') === SiteEnvironment::Staging);
$refused = false;
try {
    SiteRegistrar::environment('prod', 'production');
} catch (LogicException) {
    $refused = true;
}
assert($refused);

echo "SiteBindingsTest passed.\n";

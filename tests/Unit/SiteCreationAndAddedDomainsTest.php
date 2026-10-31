<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Contracts\SiteBinding;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\SiteBindingRole;
use Larena\Core\Enums\SiteEnvironment;
use Larena\Core\Exceptions\SiteBindingRejected;
use Larena\Core\Exceptions\SiteCreationRejected;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;
use Larena\Core\Site\DatabaseSiteBindings;
use Larena\Core\Site\SiteBindingsArtifact;
use Larena\Core\Site\SiteScopes;
use Larena\Core\SitePack\SitePackRejected;

// Sites plan steps 2.8 and 2.9: a new site is `site:<key>` with the data scope `scope:<key>`; domains are added to a
// site's list by the rules of the whole list, never half written, and one another site holds is refused by name.

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$sites = new SiteScopes($scopes, $connection);
$bindings = new DatabaseSiteBindings($connection, $scopes);
$refused = static function (Closure $call): ?string {
    try {
        $call();
    } catch (SiteCreationRejected|SiteBindingRejected $refusal) {
        return $refusal->reasonCode;
    }

    return null;
};
$hosts = static fn (array $list): array => array_map(static fn (SiteBinding $binding): string => $binding->host, $list);

// A new site and its data scope.
$shop = $sites->create('shop', ' Shop ', 'actor:admin');
assert($shop->ref->toString() === 'site:shop' && $shop->name === 'Shop' && $shop->dataRef() === 'scope:shop');
assert($scopes->resolve('scope:shop')?->ref->toString() === 'site:shop');

// What may not be a site, nothing written.
$scopes->create(ScopeRef::of(ScopeKind::Site, 'main'), 'Main', 'actor:installer');
$scopes->assignLegacyRef(ScopeRef::of(ScopeKind::Site, 'main'), 'scope:site-main');
$before = count($scopes->list(ScopeKind::Site));
foreach (['1bad', 'x', 'Bad', 'a b', 'site'] as $key) {
    assert($refused(static fn () => $sites->create($key, 'Name', 'actor:admin')) === SiteCreationRejected::KEY_INVALID, $key);
}
assert($refused(static fn () => $sites->create('shop', 'Again', 'actor:admin')) === SiteCreationRejected::KEY_TAKEN);
assert($refused(static fn () => $sites->create('site-main', 'Alias', 'actor:admin')) === SiteCreationRejected::KEY_TAKEN, 'another site holds the data scope');
assert($refused(static fn () => $sites->create('blank', '  ', 'actor:admin')) === SiteCreationRejected::NAME_INVALID);
assert($refused(static fn () => $sites->create('long', str_repeat('n', 121), 'actor:admin')) === SiteCreationRejected::NAME_INVALID);
assert(count($scopes->list(ScopeKind::Site)) === $before);

// Into an empty list the pack's order and roles stay.
$main = ScopeRef::of(ScopeKind::Site, 'main');
$pack = [['host' => 'example.ru'], ['host' => 'www.example.ru', 'role' => 'redirect'], ['host' => 'example.test', 'role' => 'main', 'environment' => 'development']];
$planned = $bindings->planAdd($shop->ref, $pack);
assert($hosts($planned) === ['example.ru', 'www.example.ru', 'example.test']);
assert($bindings->list($shop->ref) === [], 'a plan writes nothing');
$added = $bindings->add($shop->ref, $pack, 'actor:admin');
assert($hosts($added) === ['example.ru', 'www.example.ru', 'example.test']);
assert($added[0]->role === SiteBindingRole::Main && $added[1]->role === SiteBindingRole::Redirect && $added[2]->role === SiteBindingRole::Main);

// Taken by another site: refused by name, and neither site's list changes.
$bindings->replace($main, [['host' => 'main.ru']], 'actor:admin');
try {
    $bindings->planAdd($main, [['host' => 'new.ru', 'role' => 'alias'], ['host' => 'www.example.ru', 'role' => 'alias']]);
    throw new LogicException('a domain another site holds is refused');
} catch (SiteBindingRejected $refusal) {
    assert($refusal->reasonCode === SiteBindingRejected::TAKEN && $refusal->host === 'www.example.ru');
}
assert($refused(static fn () => $bindings->add($main, [['host' => 'new.ru', 'role' => 'alias'], ['host' => 'www.example.ru', 'role' => 'alias']], 'actor:admin')) === SiteBindingRejected::TAKEN);
assert($hosts($bindings->list($main)) === ['main.ru'] && count($bindings->list($shop->ref)) === 3);

// What the site lists stays: its main stays main, a pack's main comes as an alias, a domain it has is left out.
$list = $bindings->add($main, [['host' => 'second.ru'], ['host' => 'main.ru'], ['host' => 'de.second.ru', 'locale' => 'de', 'role' => 'main']], 'actor:admin');
assert($hosts($list) === ['main.ru', 'second.ru', 'de.second.ru']);
assert($list[0]->role === SiteBindingRole::Main && $list[1]->role === SiteBindingRole::Alias && $list[2]->role === SiteBindingRole::Main, 'a language has its own main');

// A list that breaks the rules is refused whole; a site that does not exist is refused only on writing.
assert($refused(static fn () => $bindings->add($main, [['host' => 'not a host']], 'actor:admin')) === SiteBindingRejected::HOST_INVALID);
$nowhere = ScopeRef::of(ScopeKind::Site, 'nowhere');
assert($hosts($bindings->planAdd($nowhere, [['host' => 'free.ru']])) === ['free.ru']);
assert($refused(static fn () => $bindings->add($nowhere, [['host' => 'free.ru']], 'actor:admin')) === SiteBindingRejected::SITE_UNKNOWN);

// Inside a caller's transaction a failure leaves nothing.
try {
    $connection->transaction(static function () use ($bindings, $main): void {
        $bindings->add($main, [['host' => 'rolled-back.ru', 'role' => 'alias']], 'actor:admin');
        throw new RuntimeException('later step failed');
    });
} catch (RuntimeException) {
}
assert(!in_array('rolled-back.ru', $hosts($bindings->list($main)), true));

// The artifact's line and back.
$line = SiteBindingsArtifact::line($added[1]);
assert($line === ['host' => 'www.example.ru', 'folder' => null, 'locale' => null, 'role' => 'redirect', 'environment' => SiteEnvironment::Production->value]);
assert(SiteBindingsArtifact::entry($line + ['extra' => 'ignored']) === $line);
foreach ([['folder' => '/a'], ['host' => 1], ['host' => 'a.ru', 'role' => ['main']]] as $bad) {
    try {
        SiteBindingsArtifact::entry($bad);
        throw new LogicException('a line of the wrong shape is refused');
    } catch (SitePackRejected $rejected) {
        assert($rejected->reasonCode === 'sitepack_site_bindings_invalid');
    }
}

fwrite(STDOUT, "SiteCreationAndAddedDomainsTest passed\n");

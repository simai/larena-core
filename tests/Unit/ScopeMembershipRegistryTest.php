<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Enums\ScopeKind;
use Larena\Core\Exceptions\ScopeBoundaryViolation;
use Larena\Core\Scope\DatabaseScopeMembershipRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\DatabaseScopeResourceBindings;
use Larena\Core\Scope\ScopeRef;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$memberships = new DatabaseScopeMembershipRegistry($connection, $scopes);
$resources = new DatabaseScopeResourceBindings($connection, $scopes);
$fails = static function (callable $call): bool {
    try {
        $call();
    } catch (ScopeBoundaryViolation|InvalidArgumentException) {
        return true;
    }

    return false;
};

$main = ScopeRef::of(ScopeKind::Site, 'main');
$shop = ScopeRef::of(ScopeKind::Site, 'shop');
$blog = ScopeRef::of(ScopeKind::Site, 'blog');
$scopes->create($main, 'Main site', 'actor:installer');
$scopes->create($shop, 'Shop', 'actor:installer');
$scopes->create($blog, 'Blog', 'actor:installer');

// A subject works in several sites; the first it joins is its default.
$person = 'user:admin_identity:7';
$memberships->assign($shop, $person, 'actor:admin');
$memberships->assign($main, $person, 'actor:admin');
assert($memberships->isMember($shop, $person) && $memberships->isMember($main, $person) && !$memberships->isMember($blog, $person));
assert($memberships->countScopesOf($person) === 2);
assert($memberships->defaultScopeOf($person)?->ref->equals($shop) === true);
assert(array_map(static fn ($record): string => $record->ref->toString(), $memberships->scopesOf($person)['items']) === ['site:main', 'site:shop']);

// Sites are asked a page at a time and by what was typed.
$page = $memberships->scopesOf($person, null, null, 1);
assert(count($page['items']) === 1 && $page['next_cursor'] === '1');
assert($memberships->scopesOf($person, null, $page['next_cursor'], 1)['items'][0]->ref->equals($shop));
assert(array_map(static fn ($record): string => $record->name, $memberships->scopesOf($person, 'SHO')['items']) === ['Shop']);

// The default is chosen among the subject's own scopes only.
$memberships->setDefault($person, $main);
assert($memberships->defaultScopeOf($person)?->ref->equals($main) === true);
assert($fails(static fn () => $memberships->setDefault($person, $blog)));

// Revoking the default moves it to the subject's next scope; revoking the last leaves none.
$memberships->revoke($main, $person, 'actor:admin');
assert(!$memberships->isMember($main, $person));
assert($memberships->defaultScopeOf($person)?->ref->equals($shop) === true);
$memberships->revoke($shop, $person, 'actor:admin');
assert($memberships->defaultScopeOf($person) === null && $memberships->countScopesOf($person) === 0);
$memberships->assign($shop, $person, 'actor:admin');
assert($memberships->isMember($shop, $person));

// An archived scope stops counting and takes no new members; a malformed subject fails closed.
$scopes->archive($blog, 'actor:admin');
assert($fails(static fn () => $memberships->assign($blog, $person, 'actor:admin')));
assert($fails(static fn () => $memberships->assign($main, 'not a subject', 'actor:admin')));

// Stored data keeps its older reference as an alias: it resolves to the site, and one alias names one site.
$scopes->assignLegacyRef($main, 'scope:main');
assert($scopes->resolve('scope:main')?->ref->equals($main) === true);
assert($scopes->read($main)?->dataRef() === 'scope:main');
assert($scopes->read($shop)?->dataRef() === 'site:shop');
assert($fails(static fn () => $scopes->assignLegacyRef($shop, 'scope:main')));
assert($scopes->resolve('scope:unknown') === null && $scopes->resolve('scope:') === null);

// A logical file belongs to one site; binding it elsewhere fails, listing pages by reference.
$resources->bind('filesystem.logical_file', 'file-b', $main);
$resources->bind('filesystem.logical_file', 'file-a', $main);
$resources->bind('filesystem.logical_file', 'file-a', $main);
$resources->bind('filesystem.logical_file', 'file-c', $shop);
assert($resources->belongsTo('filesystem.logical_file', 'file-a', $main) && !$resources->belongsTo('filesystem.logical_file', 'file-a', $shop));
assert($resources->scopeOf('filesystem.logical_file', 'file-c')?->ref->equals($shop) === true);
assert($fails(static fn () => $resources->bind('filesystem.logical_file', 'file-a', $shop)));
$first = $resources->listRefs($main, 'filesystem.logical_file', null, 1);
assert($first === ['items' => ['file-a'], 'next_cursor' => 'file-a']);
assert($resources->listRefs($main, 'filesystem.logical_file', $first['next_cursor'], 1) === ['items' => ['file-b'], 'next_cursor' => null]);
assert($fails(static fn () => $resources->bind('Bad Type', 'x', $main)));

// An older reference gets its site: the one given (an alias already held resolves to it), else one of its own.
assert($scopes->siteForLegacyRef('scope:main', 'actor:test', $shop)->ref->equals($main));
$made = $scopes->siteForLegacyRef('scope:tenant.alpha', 'actor:test');
assert($made->ref->toString() === 'site:tenant-alpha' && $made->dataRef() === 'scope:tenant.alpha');
assert($scopes->siteForLegacyRef('scope:tenant.alpha', 'actor:test')->ref->equals($made->ref));
assert($fails(static fn () => $scopes->siteForLegacyRef('site:other', 'actor:test')));

echo "ScopeMembershipRegistryTest passed.\n";

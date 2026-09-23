<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\ScopeStatus;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);

// A site exists without an organization parent: Minimal CMS installs without
// the notion of a company.
$site = ScopeRef::of(ScopeKind::Site, 'main');
$record = $scopes->create($site, 'Main site', 'actor:installer', null, 'corr-1');
assert($record->status === ScopeStatus::Active);
assert($record->parentRef === null);
assert($scopes->read($site)?->name === 'Main site');

// An organization owns two sites.
$organization = ScopeRef::of(ScopeKind::Organization, 'acme');
$scopes->create($organization, 'Acme', 'actor:installer');
$first = ScopeRef::of(ScopeKind::Site, 'acme-ru');
$second = ScopeRef::of(ScopeKind::Site, 'acme-en');
$scopes->create($first, 'Acme RU', 'actor:installer', $organization);
$scopes->create($second, 'Acme EN', 'actor:installer', $organization);
assert($scopes->read($first)?->parentRef?->equals($organization) === true);
assert(count($scopes->list(ScopeKind::Site)) === 3);
assert(count($scopes->list(ScopeKind::Organization)) === 1);

// Resolution by raw reference never joins across packages.
assert($scopes->resolve('site:acme-ru') !== null);
assert($scopes->resolve('site:missing') === null);
assert($scopes->resolve('not a reference') === null);
assert($scopes->exists('organization:acme'));

// Archive keeps reads and blocks writes bound to the archived scope.
$archived = $scopes->archive($organization, 'actor:admin', 'corr-2');
assert($archived->status === ScopeStatus::Archived);
assert($scopes->read($organization)?->status === ScopeStatus::Archived);
assert(count($scopes->list(ScopeKind::Organization)) === 0);
assert(count($scopes->list(ScopeKind::Organization, true)) === 1);

$explain = $scopes->explain($organization);
assert($explain['exists'] === true);
assert($explain['accepts_write'] === false);

echo "Scope registry contract passed.\n";

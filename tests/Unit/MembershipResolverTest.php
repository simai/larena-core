<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Contracts\MembershipRecord;
use Larena\Core\Enums\MembershipStatus;
use Larena\Core\Enums\PlaneKind;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Exceptions\ScopeBoundaryViolation;
use Larena\Core\Plane\DatabaseMembershipResolver;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$planes = new DatabasePlaneRegistry($connection, $scopes);
$memberships = new DatabaseMembershipResolver($connection, $planes);

$site = ScopeRef::of(ScopeKind::Site, 'main');
$scopes->create($site, 'Main site', 'actor:installer');
$groups = $planes->createPlane($site, 'groups', PlaneKind::Flat, 'Groups', 'actor:installer');
$staff = $planes->createPlane($site, 'staff', PlaneKind::Tree, 'Staff', 'actor:admin');

$administrators = $planes->createNode($groups->planeId, 'administrators', 'Administrators', 'actor:installer');
$company = $planes->createNode($staff->planeId, 'company', 'Company', 'actor:admin');
$sales = $planes->createNode($staff->planeId, 'sales', 'Sales', 'actor:admin', $company->nodeId);

// A subject reference is an opaque Auth EntryObject identifier: a human, a
// service and an AI actor are the same kind of subject.
$human = 'auth:user:1';
$bot = 'auth:ai_actor:assistant';

$record = $memberships->assign($administrators->nodeId, $human, 'actor:installer', 'owner', 'corr-1');
assert($record->membershipId === MembershipRecord::identity($administrators->nodeId, $human));
assert($record->status === MembershipStatus::Active);
assert($record->roleTag === 'owner');

// Assign is idempotent and re-activates a revoked membership.
$again = $memberships->assign($administrators->nodeId, $human, 'actor:installer');
assert($again->membershipId === $record->membershipId);
$revoked = $memberships->revoke($administrators->nodeId, $human, 'actor:admin');
assert($revoked->status === MembershipStatus::Revoked);
assert($memberships->membersOf($administrators->nodeId)->count() === 0);
$reassigned = $memberships->assign($administrators->nodeId, $human, 'actor:admin');
assert($reassigned->status === MembershipStatus::Active);

// One subject holds membership in nodes of two planes at once.
$memberships->assign($sales->nodeId, $human, 'actor:admin');
$memberships->assign($sales->nodeId, $bot, 'actor:admin');
$nodes = $memberships->nodesOf($human);
assert(count($nodes) === 2);
$planeIds = array_map(static fn ($node): string => $node->planeId, $nodes);
sort($planeIds);
assert($planeIds === [$groups->planeId, $staff->planeId]);
assert(count($memberships->nodesOf($human, $staff->planeId)) === 1);
assert(count($memberships->nodesOf($bot)) === 1);

// Ancestry is root first, the node itself last.
$ancestry = $memberships->ancestryOf($sales->nodeId);
assert(count($ancestry) === 2);
assert($ancestry[0]->nodeKey === 'company' && $ancestry[1]->nodeKey === 'sales');

// An unknown node fails closed on assign and on revoke.
$denied = 0;
foreach ([
    static fn (): mixed => $memberships->assign('site:main/staff#missing', $human, 'actor:admin'),
    static fn (): mixed => $memberships->revoke($sales->nodeId, 'auth:user:absent', 'actor:admin'),
    static fn (): mixed => $memberships->assign($sales->nodeId, '  ', 'actor:admin'),
] as $callback) {
    try {
        $callback();
    } catch (ScopeBoundaryViolation) {
        ++$denied;
    }
}
assert($denied === 3, 'membership boundaries must fail closed');

echo "Membership resolver contract passed.\n";

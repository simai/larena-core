<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Enums\PlaneKind;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Exceptions\ScopeBoundaryViolation;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Plane\NodePath;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$planes = new DatabasePlaneRegistry($connection, $scopes);

function larena_core_plane_denied(callable $callback, string $expectedReason): void
{
    try {
        $callback();
    } catch (ScopeBoundaryViolation $violation) {
        assert($violation->reasonCode === $expectedReason, sprintf('expected %s, got %s', $expectedReason, $violation->reasonCode));

        return;
    }

    throw new RuntimeException(sprintf('Boundary "%s" did not fail closed.', $expectedReason));
}

$site = ScopeRef::of(ScopeKind::Site, 'main');
$scopes->create($site, 'Main site', 'actor:installer');
$staff = $planes->createPlane($site, 'staff', PlaneKind::Tree, 'Staff', 'actor:admin');
$teams = $planes->createPlane($site, 'teams', PlaneKind::Tree, 'Teams', 'actor:admin');
$groups = $planes->createPlane($site, 'groups', PlaneKind::Flat, 'Groups', 'actor:admin');

$company = $planes->createNode($staff->planeId, 'company', 'Company', 'actor:admin');
$sales = $planes->createNode($staff->planeId, 'sales', 'Sales', 'actor:admin', $company->nodeId);
$field = $planes->createNode($staff->planeId, 'field', 'Field', 'actor:admin', $sales->nodeId);
$other = $planes->createNode($teams->planeId, 'other', 'Other', 'actor:admin');

// A node is never moved into itself or into its own subtree.
larena_core_plane_denied(static fn (): mixed => $planes->moveNode($sales->nodeId, $sales->nodeId, 'actor:admin'), 'cycle_detected');
larena_core_plane_denied(static fn (): mixed => $planes->moveNode($sales->nodeId, $field->nodeId, 'actor:admin'), 'cycle_detected');

// A node never leaves its plane.
larena_core_plane_denied(static fn (): mixed => $planes->moveNode($sales->nodeId, $other->nodeId, 'actor:admin'), 'cross_plane_parent');

// A flat plane rejects nesting on create and on move.
$admins = $planes->createNode($groups->planeId, 'administrators', 'Administrators', 'actor:admin');
$editors = $planes->createNode($groups->planeId, 'editors', 'Editors', 'actor:admin');
larena_core_plane_denied(static fn (): mixed => $planes->createNode($groups->planeId, 'nested', 'Nested', 'actor:admin', $admins->nodeId), 'nesting_denied');
larena_core_plane_denied(static fn (): mixed => $planes->moveNode($editors->nodeId, $admins->nodeId, 'actor:admin'), 'nesting_denied');

// Unknown identities fail closed.
larena_core_plane_denied(static fn (): mixed => $planes->moveNode('site:main/staff#missing', null, 'actor:admin'), 'unknown_node');
larena_core_plane_denied(static fn (): mixed => $planes->createNode('site:main/missing', 'node', 'Node', 'actor:admin'), 'unknown_plane');
larena_core_plane_denied(static fn (): mixed => $planes->createNode($staff->planeId, 'company', 'Duplicate', 'actor:admin'), 'duplicate_identity');

// Archived plane, node and scope reject writes.
$planes->archiveNode($field->nodeId, 'actor:admin');
larena_core_plane_denied(static fn (): mixed => $planes->moveNode($field->nodeId, null, 'actor:admin'), 'archived_write_denied');
$planes->archivePlane($teams->planeId, 'actor:admin');
larena_core_plane_denied(static fn (): mixed => $planes->createNode($teams->planeId, 'late', 'Late', 'actor:admin'), 'archived_write_denied');
$scopes->archive($site, 'actor:admin');
larena_core_plane_denied(static fn (): mixed => $planes->createPlane($site, 'late', PlaneKind::Flat, 'Late', 'actor:admin'), 'archived_write_denied');

// The frozen depth guard holds.
$deepConnection = larena_core_scope_plane_connection();
$deepScopes = new DatabaseScopeRegistry($deepConnection);
$deepPlanes = new DatabasePlaneRegistry($deepConnection, $deepScopes);
$deepScopes->create($site, 'Main site', 'actor:installer');
$deepPlane = $deepPlanes->createPlane($site, 'deep', PlaneKind::Tree, 'Deep', 'actor:admin');
$parent = null;
for ($level = 0; $level < NodePath::MAX_DEPTH; ++$level) {
    $parent = $deepPlanes->createNode($deepPlane->planeId, 'level' . $level, 'Level ' . $level, 'actor:admin', $parent?->nodeId);
}
assert($parent->depth() === NodePath::MAX_DEPTH - 1);
$deepest = $parent;
larena_core_plane_denied(
    static fn (): mixed => $deepPlanes->createNode($deepPlane->planeId, 'overflow', 'Overflow', 'actor:admin', $deepest->nodeId),
    'depth_exceeded',
);

echo "Plane node move fail-closed boundaries passed.\n";

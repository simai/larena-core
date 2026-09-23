<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Enums\PlaneKind;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$planes = new DatabasePlaneRegistry($connection, $scopes);

$site = ScopeRef::of(ScopeKind::Site, 'main');
$scopes->create($site, 'Main site', 'actor:installer');
$plane = $planes->createPlane($site, 'staff', PlaneKind::Tree, 'Staff structure', 'actor:admin');

$company = $planes->createNode($plane->planeId, 'company', 'Company', 'actor:admin');
$sales = $planes->createNode($plane->planeId, 'sales', 'Sales', 'actor:admin', $company->nodeId);
$field = $planes->createNode($plane->planeId, 'field', 'Field', 'actor:admin', $sales->nodeId);
$deep = $planes->createNode($plane->planeId, 'deep', 'Deep', 'actor:admin', $field->nodeId);
$support = $planes->createNode($plane->planeId, 'support', 'Support', 'actor:admin', $company->nodeId);

// Moving a subtree rewrites path and depth for every descendant in one go.
$moved = $planes->moveNode($sales->nodeId, $support->nodeId, 'actor:admin');
assert($moved->path->toString() === 'company/support/sales');
assert($moved->depth() === 2);
assert($planes->readNode($field->nodeId)?->path->toString() === 'company/support/sales/field');
assert($planes->readNode($field->nodeId)?->depth() === 3);
assert($planes->readNode($deep->nodeId)?->path->toString() === 'company/support/sales/field/deep');
assert($planes->readNode($deep->nodeId)?->depth() === 4);

// Moving to the root resets the path and keeps the subtree consistent.
$root = $planes->moveNode($sales->nodeId, null, 'actor:admin');
assert($root->path->toString() === 'sales');
assert($root->parentNodeId === null);
assert($planes->readNode($deep->nodeId)?->path->toString() === 'sales/field/deep');

// Order index is recomputed against the new parent.
$again = $planes->moveNode($sales->nodeId, $company->nodeId, 'actor:admin');
assert($again->orderIndex >= 1, 'a moved node takes the next free order index under its parent');

echo "Plane node move rewrites the subtree.\n";

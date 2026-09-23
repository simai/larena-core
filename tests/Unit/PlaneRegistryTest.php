<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Contracts\PlaneNodeRecord;
use Larena\Core\Contracts\PlaneRecord;
use Larena\Core\Enums\NodeStatus;
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

// Minimal CMS creates one flat plane; a product adds a tree plane later.
$groups = $planes->createPlane($site, 'groups', PlaneKind::Flat, 'Groups', 'actor:installer');
assert($groups->planeId === PlaneRecord::identity($site, 'groups'));
$staff = $planes->createPlane($site, 'staff', PlaneKind::Tree, 'Staff structure', 'actor:admin');
assert(count($planes->listPlanes($site)) === 2);

// A flat plane holds root nodes only.
$administrators = $planes->createNode($groups->planeId, 'administrators', 'Administrators', 'actor:installer');
assert($administrators->nodeId === PlaneNodeRecord::identity($groups->planeId, 'administrators'));
assert($administrators->depth() === 0);
assert($administrators->path->toString() === 'administrators');

// A tree plane nests: company -> sales -> field, plus a sibling.
$company = $planes->createNode($staff->planeId, 'company', 'Company', 'actor:admin');
$sales = $planes->createNode($staff->planeId, 'sales', 'Sales', 'actor:admin', $company->nodeId);
$field = $planes->createNode($staff->planeId, 'field', 'Field sales', 'actor:admin', $sales->nodeId);
$support = $planes->createNode($staff->planeId, 'support', 'Support', 'actor:admin', $company->nodeId);

assert($sales->path->toString() === 'company/sales');
assert($field->path->toString() === 'company/sales/field');
assert($field->depth() === 2);
assert($field->path->isDescendantOf($company->path));
assert(!$company->path->isDescendantOf($field->path));

// Sibling order is deterministic and assigned per parent.
assert($sales->orderIndex === 0 && $support->orderIndex === 1);
assert(count($planes->listNodes($staff->planeId)) === 4);

// Archive hides a node from the active listing but keeps it readable.
$planes->archiveNode($support->nodeId, 'actor:admin');
assert($planes->readNode($support->nodeId)?->status === NodeStatus::Archived);
assert(count($planes->listNodes($staff->planeId)) === 3);
assert(count($planes->listNodes($staff->planeId, true)) === 4);

$explain = $planes->explain($staff->planeId);
assert($explain['exists'] === true && $explain['node_count'] === 3);
assert($explain['scope_ref'] === 'site:main');

echo "Plane registry contract passed.\n";

<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Enums\PlaneKind;
use Larena\Core\Enums\ScopeKind;
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
$plane = $planes->createPlane($site, 'staff', PlaneKind::Tree, 'Staff', 'actor:admin');

$company = $planes->createNode($plane->planeId, 'company', 'Company', 'actor:admin');
$sales = $planes->createNode($plane->planeId, 'sales', 'Sales', 'actor:admin', $company->nodeId);
$field = $planes->createNode($plane->planeId, 'field', 'Field', 'actor:admin', $sales->nodeId);
$support = $planes->createNode($plane->planeId, 'support', 'Support', 'actor:admin', $company->nodeId);

$memberships->assign($company->nodeId, 'auth:user:ceo', 'actor:admin');
$memberships->assign($sales->nodeId, 'auth:user:head', 'actor:admin');
$memberships->assign($field->nodeId, 'auth:user:agent', 'actor:admin');
$memberships->assign($support->nodeId, 'auth:user:helper', 'actor:admin');

// Without descendants only the node itself counts.
$direct = $memberships->membersOf($sales->nodeId);
assert($direct->subjectRefs === ['auth:user:head']);
assert($direct->includeDescendants === false);
assert($direct->nodeIds === [$sales->nodeId]);

// With descendants the whole subtree counts, deterministically ordered and
// de-duplicated.
$withDescendants = $memberships->membersOf($sales->nodeId, true);
assert($withDescendants->subjectRefs === ['auth:user:agent', 'auth:user:head']);
assert($withDescendants->contains('auth:user:agent'));
assert(!$withDescendants->contains('auth:user:ceo'));

$fromRoot = $memberships->membersOf($company->nodeId, true);
assert($fromRoot->subjectRefs === ['auth:user:agent', 'auth:user:ceo', 'auth:user:head', 'auth:user:helper']);
assert(count($fromRoot->nodeIds) === 4);

// A subject present in two nodes of the subtree appears once.
$memberships->assign($field->nodeId, 'auth:user:head', 'actor:admin');
assert($memberships->membersOf($company->nodeId, true)->count() === 4);

// Moving a subtree changes descendant resolution without touching membership.
$planes->moveNode($sales->nodeId, null, 'actor:admin');
assert($memberships->membersOf($company->nodeId, true)->subjectRefs === ['auth:user:ceo', 'auth:user:helper']);
assert($memberships->membersOf($sales->nodeId, true)->subjectRefs === ['auth:user:agent', 'auth:user:head']);

echo "Membership descendant resolution passed.\n";

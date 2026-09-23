<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Enums\PlaneKind;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Exceptions\ScopeBoundaryViolation;
use Larena\Core\Plane\DatabaseMembershipResolver;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Plane\PlaneAuditEventCatalog;
use Larena\Core\Runtime\PlaneOperationHandlers;
use Larena\Core\Runtime\ScopeOperationHandlers;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeAuditEventCatalog;
use Larena\Core\Scope\ScopeRef;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$planes = new DatabasePlaneRegistry($connection, $scopes);
$memberships = new DatabaseMembershipResolver($connection, $planes);

$scopeHandlers = new ScopeOperationHandlers($scopes);
$planeHandlers = new PlaneOperationHandlers($planes, $memberships);
$scopeDescriptors = ScopeOperationHandlers::descriptors();
$planeDescriptors = PlaneOperationHandlers::descriptors();

// Every declared audit event belongs to the package catalogs, so audit.yaml
// stays the single declaration surface.
foreach ($scopeDescriptors as $descriptor) {
    if ($descriptor->auditEvent !== null) {
        assert(in_array($descriptor->auditEvent, ScopeAuditEventCatalog::all(), true));
    }
}
foreach ($planeDescriptors as $descriptor) {
    if ($descriptor->auditEvent !== null) {
        assert(in_array($descriptor->auditEvent, PlaneAuditEventCatalog::all(), true));
    }
}

// The operation carries the actor and the correlation id into the record, so
// every mutation is attributable without exposing subject payloads.
$context = new OperationContext(
    actorId: 'auth:user:1',
    correlationId: 'corr-audit-1',
    metadata: ['scope_ref' => 'site:main', 'name' => 'Main site'],
);
$created = $scopeHandlers->handle($scopeDescriptors['core.scope.create'], $context);
assert($created['scope']['scope_ref'] === 'site:main');
assert($scopes->read(ScopeRef::of(ScopeKind::Site, 'main'))?->createdBy === 'auth:user:1');
assert($scopes->read(ScopeRef::of(ScopeKind::Site, 'main'))?->correlationId === 'corr-audit-1');

$planeCreated = $planeHandlers->handle($planeDescriptors['core.plane.create'], new OperationContext(
    actorId: 'auth:user:1',
    correlationId: 'corr-audit-2',
    metadata: ['scope_ref' => 'site:main', 'plane_key' => 'groups', 'kind' => PlaneKind::Flat->value, 'name' => 'Groups'],
));
assert($planeCreated['plane']['plane_id'] === 'site:main/groups');

$nodeCreated = $planeHandlers->handle($planeDescriptors['core.plane.node.create'], new OperationContext(
    actorId: 'auth:user:1',
    correlationId: 'corr-audit-3',
    metadata: ['plane_id' => 'site:main/groups', 'node_key' => 'administrators', 'name' => 'Administrators'],
));
assert($nodeCreated['node']['node_id'] === 'site:main/groups#administrators');

$assigned = $planeHandlers->handle($planeDescriptors['core.plane.membership.assign'], new OperationContext(
    actorId: 'auth:user:1',
    correlationId: 'corr-audit-4',
    metadata: ['node_id' => 'site:main/groups#administrators', 'subject_ref' => 'auth:user:2'],
));
assert($assigned['membership']['status'] === 'active');

$members = $planeHandlers->handle($planeDescriptors['core.plane.resolve_members'], new OperationContext(
    actorId: 'auth:user:1',
    correlationId: 'corr-audit-5',
    metadata: ['node_id' => 'site:main/groups#administrators'],
));
assert($members['subject_refs'] === ['auth:user:2']);

// Missing or malformed input fails closed at the operation boundary, and a
// foreign operation name is refused rather than silently handled.
$denied = 0;
foreach ([
    static fn (): mixed => $scopeHandlers->handle($scopeDescriptors['core.scope.read'], new OperationContext('auth:user:1', 'corr-x')),
    static fn (): mixed => $planeHandlers->handle($planeDescriptors['core.plane.explain'], new OperationContext('auth:user:1', 'corr-x')),
    static fn (): mixed => $scopeHandlers->handle($planeDescriptors['core.plane.create'], $context),
] as $callback) {
    try {
        $callback();
    } catch (ScopeBoundaryViolation) {
        ++$denied;
    }
}
assert($denied === 3, 'operation input and ownership boundaries must fail closed');

echo "Scope and plane operation attribution passed.\n";

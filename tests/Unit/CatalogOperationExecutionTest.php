<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';
require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Contracts\OperationApproval;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Enums\OperationDecisionStatus;
use Larena\Core\Enums\PlaneKind;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Plane\DatabaseMembershipResolver;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Registry\CoreOperationProvider;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Runtime\CatalogOperationHandler;
use Larena\Core\Runtime\ConnectionOperationTransactionBoundary;
use Larena\Core\Runtime\OperationHandlerCatalog;
use Larena\Core\Runtime\PlaneOperationHandlers;
use Larena\Core\Runtime\RegistryOperationRuntime;
use Larena\Core\Runtime\SyncOperationRuntime;
use Larena\Core\Runtime\UndeclaredCapabilityGate;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

/**
 * @param ArrayObject<string, int> $built
 * @phpstan-impure
 */
function catalog_handler_builds(ArrayObject $built): int
{
    return $built['count'];
}

// Registry operations executed inside the application: the registry names the
// handler reference, the catalog serves it, a real transaction wraps it.
$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$planes = new DatabasePlaneRegistry($connection, $scopes);
$memberships = new DatabaseMembershipResolver($connection, $planes);
$site = ScopeRef::of(ScopeKind::Site, 'main');
$scopes->create($site, 'Main site', 'actor:installer');
$plane = $planes->createPlane($site, 'staff', PlaneKind::Tree, 'Staff structure', 'actor:admin');

$registry = DeclaredOperationRegistry::fromProviders([new CoreOperationProvider()]);
$catalog = new OperationHandlerCatalog();
$built = new ArrayObject(['count' => 0]);
$catalog->register('core.handler.plane', static function () use ($planes, $memberships, $built): OperationHandler {
    $built['count']++;

    return new PlaneOperationHandlers($planes, $memberships);
});
assert(catalog_handler_builds($built) === 0, 'registering a handler builds nothing');

$handler = new CatalogOperationHandler($registry, $catalog);
$gate = new LarenaTestAllowGate(true);
$runtime = new RegistryOperationRuntime(
    $registry,
    new SyncOperationRuntime($gate, new UndeclaredCapabilityGate(), new LarenaTestAuditRecorder(), $handler, new ConnectionOperationTransactionBoundary($connection)),
    $handler,
);
$context = static fn (array $input): OperationContext => new OperationContext('actor:admin', 'correlation-' . bin2hex(random_bytes(4)), metadata: $input);

$company = $runtime->execute('core.plane.node.create', $context(['plane_id' => $plane->planeId, 'node_key' => 'company', 'name' => 'Company']));
assert($company->successful(), json_encode($company->runtimeTrace));
$companyId = $company->payload['node']['node_id'];
$sales = $runtime->execute('core.plane.node.create', $context(['plane_id' => $plane->planeId, 'node_key' => 'sales', 'name' => 'Sales']));
$salesId = $sales->payload['node']['node_id'];
assert(catalog_handler_builds($built) === 1, 'the handler is built once, on first use');

// A move is bulk: it runs only against an approved proposal of the same change.
$moveInput = ['node_id' => $salesId, 'parent_node_id' => $companyId];
$unapproved = $runtime->execute('core.plane.node.move', $context($moveInput));
assert($unapproved->decision->reasonCode === 'approval_required');

$proposal = $runtime->propose('core.plane.node.move', $context($moveInput));
assert($proposal->successful());
$receipt = $proposal->payload['receipt'];
assert($receipt['intended_change']['kind'] === 'move_node');
assert($planes->readNode($salesId)?->parentNodeId === null, 'a proposal changes nothing');

$moved = $runtime->execute('core.plane.node.move', $context($moveInput), new OperationApproval('core.plane.node.move', $receipt['proposal_digest'], 'actor:admin'));
assert($moved->successful(), json_encode($moved->runtimeTrace));
assert($planes->readNode($salesId)?->path->toString() === 'company/sales');

// A different change does not ride on that approval.
$other = $runtime->execute('core.plane.node.move', $context(['node_id' => $salesId]), new OperationApproval('core.plane.node.move', $receipt['proposal_digest'], 'actor:admin'));
assert($other->decision->reasonCode === 'approval_mismatch');

// A handler failure inside the transaction leaves nothing behind.
$duplicate = $runtime->execute('core.plane.node.create', $context(['plane_id' => $plane->planeId, 'node_key' => 'company', 'name' => 'Again']));
assert($duplicate->decision->status !== OperationDecisionStatus::Allowed || !$duplicate->successful());

// An unregistered handler reference fails closed rather than doing anything.
$empty = new CatalogOperationHandler($registry, new OperationHandlerCatalog());
$bare = new RegistryOperationRuntime($registry, new SyncOperationRuntime($gate, new UndeclaredCapabilityGate(), new LarenaTestAuditRecorder(), $empty, new ConnectionOperationTransactionBoundary($connection)), $empty);
$refused = $bare->execute('core.plane.node.create', $context(['plane_id' => $plane->planeId, 'node_key' => 'x', 'name' => 'X']));
assert(!$refused->successful());
assert($connection->table('larena_core_plane_nodes')->where('node_key', 'x')->doesntExist());

// No ambient transaction: an operation never joins one it did not open.
$connection->beginTransaction();
$refusal = null;
try {
    (new ConnectionOperationTransactionBoundary($connection))->run(static fn (): int => 1);
} catch (RuntimeException $exception) {
    $refusal = $exception->getMessage();
} finally {
    $connection->rollBack();
}
assert($refusal === 'ambient_operation_transaction_forbidden', 'an ambient transaction is refused');

// A composition without declared capabilities denies an operation that needs one.
assert((new UndeclaredCapabilityGate())->decideCapability($registry->descriptorFor('core.plane.node.create'), $context([]))->status === OperationDecisionStatus::Denied);

echo "Catalog operation execution passed.\n";

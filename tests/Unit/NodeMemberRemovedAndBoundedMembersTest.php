<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\Dispatcher;
use Larena\Core\Contracts\MembershipResolver;
use Larena\Core\Enums\MembershipStatus;
use Larena\Core\Enums\PlaneKind;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Events\NodeMemberRemoved;
use Larena\Core\Plane\DatabaseMembershipResolver;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

// (a) A subject leaving a plane node is told once, after commit, never for a rolled-back change or a membership that
//     was already revoked.
// (b) membersOf() is bounded: at most MEMBERS_MAX subjects, `complete` tells whether more follow, and pages read on.

function node_member_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$previousContainer = Container::getInstance();

try {
    $container = new Container();
    Container::setInstance($container);
    $events = new Dispatcher($container);
    $container->instance('events', $events);
    /** @var list<NodeMemberRemoved> $heard */
    $heard = [];
    $events->listen(NodeMemberRemoved::class, static function (NodeMemberRemoved $event) use (&$heard): void {
        $heard[] = $event;
    });
    $take = static function () use (&$heard): array {
        $got = $heard;
        $heard = [];

        return $got;
    };

    $connection = larena_core_scope_plane_connection();
    $connection->setTransactionManager(new DatabaseTransactionsManager());
    $scopes = new DatabaseScopeRegistry($connection);
    $planes = new DatabasePlaneRegistry($connection, $scopes);
    $memberships = new DatabaseMembershipResolver($connection, $planes);

    $site = ScopeRef::of(ScopeKind::Site, 'main');
    $scopes->create($site, 'Main site', 'actor:installer');
    $plane = $planes->createPlane($site, 'staff', PlaneKind::Tree, 'Staff', 'actor:admin');
    $company = $planes->createNode($plane->planeId, 'company', 'Company', 'actor:admin');
    $sales = $planes->createNode($plane->planeId, 'sales', 'Sales', 'actor:admin', $company->nodeId);
    $salesOps = $planes->createNode($plane->planeId, 'sales_ops', 'Sales ops', 'actor:admin', $company->nodeId);

    // (a) Outside a transaction the event goes at once, with node, subject and reason.
    $memberships->assign($sales->nodeId, 'auth:user:ann', 'actor:admin');
    node_member_check($take() === [], 'Assigning a member must not tell NodeMemberRemoved.');
    $memberships->revoke($sales->nodeId, 'auth:user:ann', 'actor:admin', 'corr-1');
    $got = $take();
    node_member_check(count($got) === 1, 'A revoke must tell NodeMemberRemoved once.');
    node_member_check($got[0]->toArray() === ['node_id' => $sales->nodeId, 'subject_ref' => 'auth:user:ann', 'reason' => 'revoked'], 'NodeMemberRemoved payload is wrong.');

    // Revoking a membership already revoked changes nothing and tells nothing.
    $memberships->revoke($sales->nodeId, 'auth:user:ann', 'actor:admin');
    node_member_check($take() === [], 'Revoking a revoked membership must not tell NodeMemberRemoved.');

    // Inside a transaction it waits for the outermost commit.
    $memberships->assign($sales->nodeId, 'auth:user:bob', 'actor:admin');
    $connection->transaction(function () use ($connection, $memberships, $sales, $take): void {
        $connection->transaction(function () use ($memberships, $sales): void {
            $memberships->revoke($sales->nodeId, 'auth:user:bob', 'actor:admin');
        });
        node_member_check($take() === [], 'NodeMemberRemoved must wait for the outermost commit.');
    });
    $got = $take();
    node_member_check(count($got) === 1 && $got[0]->subjectRef === 'auth:user:bob', 'A committed revoke must tell NodeMemberRemoved once.');

    // A rolled-back revoke tells nothing and the member stays.
    $memberships->assign($sales->nodeId, 'auth:user:cid', 'actor:admin');
    try {
        $connection->transaction(function () use ($memberships, $sales): void {
            $memberships->revoke($sales->nodeId, 'auth:user:cid', 'actor:admin');
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }
    node_member_check($take() === [], 'A rolled-back revoke must not tell NodeMemberRemoved.');
    node_member_check($memberships->membersOf($sales->nodeId)->contains('auth:user:cid'), 'A rolled-back revoke must keep the member.');

    // A listener that throws does not undo the change.
    $events->listen(NodeMemberRemoved::class, static function (): void {
        throw new RuntimeException('listener failed');
    });
    $memberships->revoke($sales->nodeId, 'auth:user:cid', 'actor:admin');
    node_member_check(count($take()) === 1, 'The event must still reach the other listener.');
    node_member_check(!$memberships->membersOf($sales->nodeId)->contains('auth:user:cid'), 'A failing listener must not undo the revoke.');
    $events->forget(NodeMemberRemoved::class);

    // (b) Pages of a node with descendants: sorted, de-duplicated, `complete` false until the last page.
    foreach (['auth:user:a1', 'auth:user:a2', 'auth:user:a3'] as $subject) {
        $memberships->assign($sales->nodeId, $subject, 'actor:admin');
    }
    $memberships->assign($salesOps->nodeId, 'auth:user:a2', 'actor:admin');
    $memberships->assign($salesOps->nodeId, 'auth:user:a4', 'actor:admin');
    $memberships->assign($company->nodeId, 'auth:user:a0', 'actor:admin');

    $first = $memberships->membersOf($company->nodeId, true, 2);
    node_member_check($first->subjectRefs === ['auth:user:a0', 'auth:user:a1'] && !$first->complete, 'The first page must stop at its limit and be incomplete.');
    node_member_check(count($first->nodeIds) === 3, 'The subtree must hold three nodes.');
    $second = $memberships->membersOf($company->nodeId, true, 2, $first->nextAfter());
    node_member_check($second->subjectRefs === ['auth:user:a2', 'auth:user:a3'] && !$second->complete, 'The second page must continue after the first.');
    $last = $memberships->membersOf($company->nodeId, true, 2, $second->nextAfter());
    node_member_check($last->subjectRefs === ['auth:user:a4'] && $last->complete && $last->nextAfter() === null, 'The last page must be complete.');
    $exact = $memberships->membersOf($company->nodeId, true, 5);
    node_member_check($exact->count() === 5 && $exact->complete, 'Exactly $limit members is complete.');

    // `sales_ops` shares the prefix `sales` but is no descendant of it.
    node_member_check($memberships->membersOf($sales->nodeId, true)->subjectRefs === ['auth:user:a1', 'auth:user:a2', 'auth:user:a3'], 'A sibling with a longer key must not count as a descendant.');

    // A limit below one is one; above MEMBERS_MAX it is MEMBERS_MAX, which is also the default.
    node_member_check($memberships->membersOf($sales->nodeId, false, 0)->count() === 1, 'A limit below one must be one.');
    $big = $planes->createNode($plane->planeId, 'big', 'Big', 'actor:admin');
    $now = gmdate('Y-m-d H:i:s');
    $rows = [];
    for ($i = 0; $i <= MembershipResolver::MEMBERS_MAX; $i++) {
        $subject = sprintf('auth:user:m%04d', $i);
        $rows[] = ['membership_id' => \Larena\Core\Contracts\MembershipRecord::identity($big->nodeId, $subject), 'node_id' => $big->nodeId,
            'subject_ref' => $subject, 'role_tag' => null, 'status' => MembershipStatus::Active->value, 'created_by' => 'actor:admin',
            'correlation_id' => null, 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        $connection->table(DatabaseMembershipResolver::TABLE)->insert($chunk);
    }
    $default = $memberships->membersOf($big->nodeId);
    node_member_check($default->count() === MembershipResolver::MEMBERS_MAX && !$default->complete, 'The default answer must stop at MEMBERS_MAX and be incomplete.');
    $clamped = $memberships->membersOf($big->nodeId, false, 5000);
    node_member_check($clamped->count() === MembershipResolver::MEMBERS_MAX && !$clamped->complete, 'A limit above MEMBERS_MAX must be MEMBERS_MAX.');
    $rest = $memberships->membersOf($big->nodeId, false, MembershipResolver::MEMBERS_MAX, $default->nextAfter());
    node_member_check($rest->subjectRefs === ['auth:user:m1000'] && $rest->complete, 'The next page must hold the rest and be complete.');
} finally {
    Container::setInstance($previousContainer);
}

echo "NodeMemberRemoved and bounded membersOf passed.\n";

# Node membership: the leaving event and bounded member reads

Core owns who belongs to a plane node (`Larena\Core\Contracts\MembershipResolver`). Access grants roles on nodes and
reads these memberships; it never writes them. This page sits beside Auth's person events
(`packages/auth/docs/developer/person-events.md`) and Access's role events
(`packages/access/docs/developer/role-events-and-holders.md`).

## Event

| Event | Payload (`toArray()`) | Sent from | Audit event (already written) |
| --- | --- | --- | --- |
| `Larena\Core\Events\NodeMemberRemoved` | `node_id`, `subject_ref`, `reason`: `revoked` | `DatabaseMembershipResolver::revoke()`, only when the membership was active | `core.plane.membership.revoked` (the `core.plane.membership.revoke` operation) |

Like the Auth and Access events it is a plain Laravel event object, dispatched through the application's event
dispatcher:

- once per change that takes effect (revoking a membership already revoked sends nothing);
- after the change is committed (inside a transaction, such as the operation runtime's, it waits for the outermost
  commit);
- never for a change that was rolled back;
- a listener that throws does not undo the change (the error goes to `report()`);
- without the application's dispatcher nothing is sent.

A subject that leaves a node loses what was granted on that node, and on its ancestors with descendants, unless
something else still gives it. Membership itself never grants a right.

Not signalled: a node archived or moved (memberships stay; Access already ignores archived nodes in a decision), and a
scope membership revoked (`DatabaseScopeMembershipRegistry::revoke()`).

### Listening (for example from env)

```php
if (class_exists(\Larena\Core\Events\NodeMemberRemoved::class)) {
    $events->listen(\Larena\Core\Events\NodeMemberRemoved::class, static function (\Larena\Core\Events\NodeMemberRemoved $event) use ($recipients): void {
        $recipients()->sweep($event->subjectRef);
    });
}
```

The same shape as env's `RoleRevoked` listener in `EnvServiceProvider::listenToPeople()`. Today hosting rights
(`env.*`) never come through a node grant, so env loses nothing when a person leaves a node; it needs this listener
only if that rule changes.

## Bounded members

```php
$set = app(MembershipResolver::class)->membersOf($nodeId, includeDescendants: true, limit: 200);
// $set->subjectRefs (sorted, unique), $set->nodeIds, $set->complete
while (!$set->complete) {
    $set = app(MembershipResolver::class)->membersOf($nodeId, true, 200, $set->nextAfter());
}
```

`membersOf(string $nodeId, bool $includeDescendants = false, int $limit = MEMBERS_MAX, ?string $after = null): ResolvedMemberSet`

- Active members of the node, and with `$includeDescendants` of every active node below it, sorted by subject
  reference, each once.
- At most `$limit` subjects, clamped to 1..`MembershipResolver::MEMBERS_MAX` (1000); the default is the maximum.
- `complete` is false when more members follow. `nextAfter()` is the last listed subject; pass it as `$after` to read
  the next page. A complete answer's `nextAfter()` is null.
- With descendants only the node's subtree is read (a path prefix on the plane's nodes), not every node of the plane.
  `nodeIds` still lists the whole subtree.
- The `core.plane.resolve_members` operation takes `limit` (1..1000) and `after`, and answers `complete`.

A caller that needs every member reads on with `nextAfter()` until `complete`.

No migration was added: the read uses the unique `(node_id, subject_ref)` index.

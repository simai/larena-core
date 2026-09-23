<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Larena\Core\Enums\ScopeKind;
use Larena\Core\Exceptions\ScopeBoundaryViolation;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);

function larena_core_scope_denied(callable $callback, string $expectedReason): void
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
$organization = ScopeRef::of(ScopeKind::Organization, 'acme');
$scopes->create($site, 'Main site', 'actor:installer');
$scopes->create($organization, 'Acme', 'actor:installer');

// An identity is never reused, not even after archive.
larena_core_scope_denied(static fn (): mixed => $scopes->create($site, 'Duplicate', 'actor:admin'), 'duplicate_identity');
$scopes->archive($site, 'actor:admin');
larena_core_scope_denied(static fn (): mixed => $scopes->create($site, 'Reused', 'actor:admin'), 'duplicate_identity');

// A write bound to an archived scope fails closed.
larena_core_scope_denied(
    static fn (): mixed => $scopes->create(ScopeRef::of(ScopeKind::Site, 'child'), 'Child', 'actor:admin', ScopeRef::of(ScopeKind::Site, 'main')),
    'archived_write_denied',
);

// A site cannot be parented to an active site, and an organization has no parent.
$scopes->create(ScopeRef::of(ScopeKind::Site, 'other'), 'Other site', 'actor:installer');
larena_core_scope_denied(
    static fn (): mixed => $scopes->create(ScopeRef::of(ScopeKind::Site, 'nested-site'), 'Nested site', 'actor:admin', ScopeRef::of(ScopeKind::Site, 'other')),
    'invalid_parent',
);
larena_core_scope_denied(
    static fn (): mixed => $scopes->create(ScopeRef::of(ScopeKind::Organization, 'nested'), 'Nested', 'actor:admin', $organization),
    'invalid_parent',
);
larena_core_scope_denied(
    static fn (): mixed => $scopes->create(ScopeRef::of(ScopeKind::Site, 'orphan'), 'Orphan', 'actor:admin', ScopeRef::of(ScopeKind::Organization, 'missing')),
    'unknown_scope',
);

// Unknown scopes fail closed on archive and stay explainable.
larena_core_scope_denied(static fn (): mixed => $scopes->archive(ScopeRef::of(ScopeKind::Site, 'missing'), 'actor:admin'), 'unknown_scope');
$explain = $scopes->explain(ScopeRef::of(ScopeKind::Site, 'missing'));
assert($explain['exists'] === false && $explain['accepts_write'] === false);

// A writable-scope guard is the fail-closed entry point for other packages.
larena_core_scope_denied(static fn (): mixed => $scopes->requireWritableScope($site), 'archived_write_denied');
assert($scopes->requireWritableScope($organization)->ref->equals($organization));

echo "Scope registry fail-closed boundaries passed.\n";

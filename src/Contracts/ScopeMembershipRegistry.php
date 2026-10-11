<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Scope\ScopeRef;

/**
 * Which scopes a subject works in (core scope-model: a subject can belong to several scopes at once), and the one
 * it works in when nothing else is asked. Membership never grants a right; rights stay with access.
 */
interface ScopeMembershipRegistry
{
    /** Make the subject a member of an active scope; the first scope a subject joins becomes its default. */
    public function assign(ScopeRef $scope, string $subjectRef, string $actorId, ?string $correlationId = null): void;

    /** End a membership; a default that pointed at it falls to the subject's next scope, if any. */
    public function revoke(ScopeRef $scope, string $subjectRef, string $actorId, ?string $correlationId = null): void;

    public function isMember(ScopeRef $scope, string $subjectRef): bool;

    /**
     * The active scopes the subject is an active member of, by name, a page at a time.
     *
     * @return array{items:list<ScopeRecord>,next_cursor:?string}
     */
    public function scopesOf(string $subjectRef, ?string $search = null, ?string $cursor = null, int $limit = 50): array;

    public function countScopesOf(string $subjectRef): int;

    /** The subject's default scope while it is still a member of it, else null. */
    public function defaultScopeOf(string $subjectRef): ?ScopeRecord;

    /** Choose the default among the subject's scopes. */
    public function setDefault(string $subjectRef, ScopeRef $scope): void;
}

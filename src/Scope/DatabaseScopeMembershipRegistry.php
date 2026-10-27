<?php

declare(strict_types=1);

namespace Larena\Core\Scope;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Larena\Core\Contracts\ScopeMembershipRegistry;
use Larena\Core\Contracts\ScopeRecord;
use Larena\Core\Exceptions\ScopeBoundaryViolation;

/** Core-owned subject memberships in scopes, with each subject's default scope. */
final class DatabaseScopeMembershipRegistry implements ScopeMembershipRegistry
{
    public const TABLE = 'larena_core_scope_memberships';
    public const DEFAULTS = 'larena_core_subject_default_scopes';
    private const SUBJECT = '/\A[a-z][a-z0-9_]{0,31}:[A-Za-z0-9_.:-]{1,150}\z/D';

    /**
     * Answers given in the last second: membership by scope and subject, and each subject's default scope. The
     * registry lives as long as the process, so they are forgotten after a second and on assigning, revoking or
     * choosing a default.
     *
     * @var array<string,bool|string|null>
     */
    private array $answers = [];

    private int $answeredAt = 0;

    private function fresh(): void
    {
        if (hrtime(true) - $this->answeredAt > 1_000_000_000) {
            $this->answers = [];
            $this->answeredAt = hrtime(true);
        }
    }

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly DatabaseScopeRegistry $scopes,
    ) {}

    /** @phpstan-impure */
    public function assign(ScopeRef $scope, string $subjectRef, string $actorId, ?string $correlationId = null): void
    {
        $this->answers = [];
        $subjectRef = self::subject($subjectRef);
        $this->scopes->requireWritableScope($scope);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->transaction(function () use ($scope, $subjectRef, $actorId, $correlationId, $now): void {
            $existing = $this->connection->table(self::TABLE)->where('scope_ref', $scope->toString())->where('subject_ref', $subjectRef)->first();
            if (is_object($existing)) {
                $this->connection->table(self::TABLE)->where('scope_ref', $scope->toString())->where('subject_ref', $subjectRef)
                    ->update(['status' => 'active', 'correlation_id' => $correlationId, 'updated_at' => $now]);
            } else {
                $this->connection->table(self::TABLE)->insert(['scope_ref' => $scope->toString(), 'subject_ref' => $subjectRef,
                    'status' => 'active', 'created_by' => $actorId, 'correlation_id' => $correlationId, 'created_at' => $now, 'updated_at' => $now]);
            }
            if ($this->connection->table(self::DEFAULTS)->where('subject_ref', $subjectRef)->doesntExist()) {
                $this->connection->table(self::DEFAULTS)->insert(['subject_ref' => $subjectRef, 'scope_ref' => $scope->toString(), 'created_at' => $now, 'updated_at' => $now]);
            }
        });
        $this->answers = [];
    }

    /** @phpstan-impure */
    public function revoke(ScopeRef $scope, string $subjectRef, string $actorId, ?string $correlationId = null): void
    {
        $this->answers = [];
        $subjectRef = self::subject($subjectRef);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->transaction(function () use ($scope, $subjectRef, $correlationId, $now): void {
            $this->connection->table(self::TABLE)->where('scope_ref', $scope->toString())->where('subject_ref', $subjectRef)
                ->update(['status' => 'revoked', 'correlation_id' => $correlationId, 'updated_at' => $now]);
            $default = $this->connection->table(self::DEFAULTS)->where('subject_ref', $subjectRef)->value('scope_ref');
            if ($default === $scope->toString()) {
                $next = $this->connection->table(self::TABLE)->where('subject_ref', $subjectRef)->where('status', 'active')
                    ->orderBy('scope_ref')->value('scope_ref');
                if (is_string($next)) {
                    $this->connection->table(self::DEFAULTS)->where('subject_ref', $subjectRef)->update(['scope_ref' => $next, 'updated_at' => $now]);
                } else {
                    $this->connection->table(self::DEFAULTS)->where('subject_ref', $subjectRef)->delete();
                }
            }
        });
        $this->answers = [];
    }

    /** @phpstan-impure */
    public function isMember(ScopeRef $scope, string $subjectRef): bool
    {
        $record = $this->scopes->read($scope);

        $subjectRef = self::subject($subjectRef);
        $this->fresh();

        return $record !== null && $record->status->acceptsWrite()
            && ($this->answers['member:'.$scope->toString()."\n".$subjectRef] ??= $this->connection->table(self::TABLE)
                ->where('scope_ref', $scope->toString())->where('subject_ref', $subjectRef)->where('status', 'active')->exists());
    }

    /** @phpstan-impure */
    public function scopesOf(string $subjectRef, ?string $search = null, ?string $cursor = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $offset = $cursor === null ? 0 : (ctype_digit($cursor) ? (int) $cursor : throw new InvalidArgumentException('core_scope_cursor_invalid'));
        $query = $this->connection->table(DatabaseScopeRegistry::TABLE.' as s')
            ->join(self::TABLE.' as m', 'm.scope_ref', '=', 's.scope_ref')
            ->where('m.subject_ref', self::subject($subjectRef))->where('m.status', 'active')->where('s.status', 'active');
        $search = $search === null ? '' : trim($search);
        if ($search !== '') {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(static function ($inner) use ($needle): void {
                $inner->whereRaw('LOWER(s.name) LIKE ?', [$needle])->orWhereRaw('LOWER(s.scope_ref) LIKE ?', [$needle]);
            });
        }
        $rows = $query->orderBy('s.name')->orderBy('s.scope_ref')->offset($offset)->limit($limit + 1)->get(['s.scope_ref']);
        $refs = [];
        foreach ($rows as $row) {
            $refs[] = (string) $row->scope_ref;
        }
        $more = count($refs) > $limit;
        $items = [];
        foreach (array_slice($refs, 0, $limit) as $ref) {
            $record = $this->scopes->resolve($ref);
            if ($record !== null) {
                $items[] = $record;
            }
        }

        return ['items' => $items, 'next_cursor' => $more ? (string) ($offset + $limit) : null];
    }

    /** @phpstan-impure */
    public function countScopesOf(string $subjectRef): int
    {
        return $this->connection->table(DatabaseScopeRegistry::TABLE.' as s')
            ->join(self::TABLE.' as m', 'm.scope_ref', '=', 's.scope_ref')
            ->where('m.subject_ref', self::subject($subjectRef))->where('m.status', 'active')->where('s.status', 'active')->count();
    }

    /** @phpstan-impure */
    public function defaultScopeOf(string $subjectRef): ?ScopeRecord
    {
        $subjectRef = self::subject($subjectRef);
        $key = 'default:'.$subjectRef;
        $this->fresh();
        $ref = array_key_exists($key, $this->answers) ? $this->answers[$key]
            : $this->answers[$key] = $this->connection->table(self::DEFAULTS)->where('subject_ref', $subjectRef)->value('scope_ref');
        if (!is_string($ref)) {
            return null;
        }
        $parsed = ScopeRef::tryParse($ref);

        return $parsed !== null && $this->isMember($parsed, $subjectRef) ? $this->scopes->read($parsed) : null;
    }

    /** @phpstan-impure */
    public function setDefault(string $subjectRef, ScopeRef $scope): void
    {
        $this->answers = [];
        $subjectRef = self::subject($subjectRef);
        if (!$this->isMember($scope, $subjectRef)) {
            throw ScopeBoundaryViolation::unknownScope($scope->toString());
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($this->connection->table(self::DEFAULTS)->where('subject_ref', $subjectRef)->exists()) {
            $this->connection->table(self::DEFAULTS)->where('subject_ref', $subjectRef)->update(['scope_ref' => $scope->toString(), 'updated_at' => $now]);
        } else {
            $this->connection->table(self::DEFAULTS)->insert(['subject_ref' => $subjectRef, 'scope_ref' => $scope->toString(), 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->answers = [];
    }

    private static function subject(string $subjectRef): string
    {
        $subjectRef = trim($subjectRef);
        if (preg_match(self::SUBJECT, $subjectRef) !== 1) {
            throw new InvalidArgumentException('core_scope_subject_invalid');
        }

        return $subjectRef;
    }
}

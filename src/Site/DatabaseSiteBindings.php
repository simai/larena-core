<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Larena\Core\Contracts\SiteBinding;
use Larena\Core\Contracts\SiteBindings;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\SiteBindingRole;
use Larena\Core\Enums\SiteEnvironment;
use Larena\Core\Exceptions\SiteBindingRejected;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

/** Core-owned site domains and the default-site mark; every write clears the compiled site map. */
final class DatabaseSiteBindings implements SiteBindings
{
    public const TABLE = 'larena_core_site_bindings';
    public const DEFAULT_TABLE = 'larena_core_default_site';
    private const DEFAULT_SLOT = 'default';

    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseScopeRegistry $scopes,
        private readonly ?SiteMapStore $map = null,
    ) {
    }

    /** @phpstan-impure */
    public function list(ScopeRef $site): array
    {
        return $this->hydrate($this->connection->table(self::TABLE)->where('site_ref', $site->toString())->orderBy('position')->get());
    }

    /** @phpstan-impure */
    public function all(?SiteEnvironment $environment = null): array
    {
        $query = $this->connection->table(self::TABLE);
        if ($environment !== null) {
            $query->where('environment', $environment->value);
        }

        return $this->hydrate($query->orderBy('site_ref')->orderBy('position')->get());
    }

    /** @phpstan-impure */
    public function replace(ScopeRef $site, array $bindings, string $actorId): array
    {
        $this->requireSite($site);
        $normalized = SiteBindingRules::normalize($site, $bindings);
        try {
            $this->connection->transaction(function () use ($site, $normalized, $actorId): void {
                $this->write($site, $normalized, $actorId);
            });
        } catch (UniqueConstraintViolationException) {
            // Another site took the address between the check and the write.
            throw new SiteBindingRejected(SiteBindingRejected::TAKEN);
        }
        $this->map?->forget();

        return $this->list($site);
    }

    /** @phpstan-impure */
    public function planAdd(ScopeRef $site, array $entries): array
    {
        $merged = SiteBindingRules::normalize($site, $this->merged($site, $entries));
        $this->assertFree($site, $merged);

        return $merged;
    }

    /** @phpstan-impure */
    public function add(ScopeRef $site, array $entries, string $actorId): array
    {
        $this->requireSite($site);
        $merged = SiteBindingRules::normalize($site, $this->merged($site, $entries));
        try {
            $this->connection->transaction(function () use ($site, $merged, $actorId): void {
                $this->write($site, $merged, $actorId);
            });
        } catch (UniqueConstraintViolationException) {
            throw new SiteBindingRejected(SiteBindingRejected::TAKEN);
        }
        $map = $this->map;
        if ($map !== null && $this->connection->transactionLevel() > 0) {
            // The map is compiled again only from what the caller's transaction committed.
            $this->connection->afterCommit(static fn () => $map->forget());
        } else {
            $map?->forget();
        }

        return $this->list($site);
    }

    /** @phpstan-impure */
    public function defaultSite(): ?ScopeRef
    {
        $ref = $this->connection->table(self::DEFAULT_TABLE)->where('slot', self::DEFAULT_SLOT)->value('site_ref');

        return is_string($ref) ? ScopeRef::tryParse($ref) : null;
    }

    /** @phpstan-impure */
    public function setDefaultSite(ScopeRef $site, string $actorId): void
    {
        $this->requireSite($site);
        $now = gmdate('Y-m-d H:i:s');
        $table = $this->connection->table(self::DEFAULT_TABLE);
        if ($table->where('slot', self::DEFAULT_SLOT)->exists()) {
            $this->connection->table(self::DEFAULT_TABLE)->where('slot', self::DEFAULT_SLOT)
                ->update(['site_ref' => $site->toString(), 'updated_by' => $actorId, 'updated_at' => $now]);
        } else {
            $this->connection->table(self::DEFAULT_TABLE)->insert(['slot' => self::DEFAULT_SLOT, 'site_ref' => $site->toString(),
                'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->map?->forget();
    }

    /**
     * The map of one environment, straight from the tables; before the migration runs it is empty.
     *
     * @phpstan-impure
     */
    public function compileMap(SiteEnvironment $environment): SiteMap
    {
        $schema = $this->connection->getSchemaBuilder();
        if (!$schema->hasTable(self::TABLE)) {
            return SiteMap::empty($environment);
        }

        return SiteMap::compile($environment, $this->all($environment), $schema->hasTable(self::DEFAULT_TABLE) ? $this->defaultSite() : null);
    }

    /**
     * The site's list with the new entries after it, as entries for SiteBindingRules::normalize().
     *
     * @param array<mixed> $entries
     * @return list<array<string, string|null>>
     */
    private function merged(ScopeRef $site, array $entries): array
    {
        $merged = [];
        $addresses = [];
        $mains = [];
        foreach ($this->list($site) as $binding) {
            $merged[] = self::entry($binding);
            $addresses[SiteBindingRules::address($binding)] = true;
            if ($binding->role === SiteBindingRole::Main) {
                $mains[$binding->environment->value.' '.($binding->locale ?? '')] = true;
            }
        }
        foreach (SiteBindingRules::normalize($site, $entries) as $binding) {
            if (isset($addresses[SiteBindingRules::address($binding)])) {
                continue;
            }
            $entry = self::entry($binding);
            if ($binding->role === SiteBindingRole::Main && isset($mains[$binding->environment->value.' '.($binding->locale ?? '')])) {
                $entry['role'] = SiteBindingRole::Alias->value;
            }
            $merged[] = $entry;
        }

        return $merged;
    }

    /** @return array<string, string|null> */
    private static function entry(SiteBinding $binding): array
    {
        return ['host' => $binding->host, 'folder' => $binding->folder, 'locale' => $binding->locale,
            'role' => $binding->role->value, 'environment' => $binding->environment->value];
    }

    /**
     * Refuses the first domain another site holds.
     *
     * @param list<SiteBinding> $bindings
     */
    private function assertFree(ScopeRef $site, array $bindings): void
    {
        $this->addressesHeld($site, $bindings);
    }

    /**
     * Who created each address the site holds already; refuses the first domain another site holds.
     *
     * @param list<SiteBinding> $bindings
     * @return array<string, array{0: string, 1: mixed}>
     */
    private function addressesHeld(ScopeRef $site, array $bindings): array
    {
        $hosts = array_values(array_unique(array_map(static fn (SiteBinding $binding): string => $binding->host, $bindings)));
        $created = [];
        $taken = [];
        foreach ($hosts === [] ? [] : $this->connection->table(self::TABLE)->whereIn('host', $hosts)->get() as $row) {
            $address = (string) $row->environment.' '.(string) $row->host.(string) $row->folder;
            if ((string) $row->site_ref === $site->toString()) {
                $created[$address] = [(string) $row->created_by, $row->created_at];
            } else {
                $taken[$address] = true;
            }
        }
        foreach ($bindings as $binding) {
            if (isset($taken[SiteBindingRules::address($binding)])) {
                throw new SiteBindingRejected(SiteBindingRejected::TAKEN, $binding->position, $binding->host);
            }
        }

        return $created;
    }

    /** @param list<SiteBinding> $bindings */
    private function write(ScopeRef $site, array $bindings, string $actorId): void
    {
        $created = $this->addressesHeld($site, $bindings);
        $now = gmdate('Y-m-d H:i:s');
        $rows = [];
        foreach ($bindings as $binding) {
            $address = SiteBindingRules::address($binding);
            $rows[] = [
                'site_ref' => $site->toString(),
                'position' => $binding->position,
                'host' => $binding->host,
                'folder' => $binding->folder ?? '',
                'locale' => $binding->locale,
                'role' => $binding->role->value,
                'environment' => $binding->environment->value,
                'created_by' => $created[$address][0] ?? $actorId,
                'updated_by' => $actorId,
                'created_at' => $created[$address][1] ?? $now,
                'updated_at' => $now,
            ];
        }
        $this->connection->table(self::TABLE)->where('site_ref', $site->toString())->delete();
        if ($rows !== []) {
            $this->connection->table(self::TABLE)->insert($rows);
        }
    }

    private function requireSite(ScopeRef $site): void
    {
        $record = $site->kind === ScopeKind::Site ? $this->scopes->read($site) : null;
        if ($record === null) {
            throw new SiteBindingRejected(SiteBindingRejected::SITE_UNKNOWN);
        }
        if (!$record->status->acceptsWrite()) {
            throw new SiteBindingRejected(SiteBindingRejected::SITE_ARCHIVED);
        }
    }

    /**
     * @param iterable<object> $rows
     * @return list<SiteBinding>
     */
    private function hydrate(iterable $rows): array
    {
        $bindings = [];
        foreach ($rows as $row) {
            $site = ScopeRef::tryParse((string) $row->site_ref);
            $role = SiteBindingRole::tryFrom((string) $row->role);
            $environment = SiteEnvironment::tryFrom((string) $row->environment);
            if ($site === null || $role === null || $environment === null) {
                continue;
            }
            $folder = (string) $row->folder;
            $bindings[] = new SiteBinding($site, (int) $row->position, (string) $row->host, $folder === '' ? null : $folder,
                $row->locale === null ? null : (string) $row->locale, $role, $environment);
        }

        return $bindings;
    }
}

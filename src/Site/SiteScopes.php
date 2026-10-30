<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Illuminate\Database\ConnectionInterface;
use Larena\Core\Contracts\ScopeRecord;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Exceptions\SiteCreationRejected;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

/**
 * Makes a new site (sites plan, step 2.9): the scope `site:<key>` and its data reference `scope:<key>`.
 *
 * A site has two names, as `site:main` and `scope:minimal-cms` have: `site:<key>` is the site (its role bindings,
 * publications, settings, domains, membership), `scope:<key>` is what its stored data carries (storages, files, layout
 * pages: ScopeRecord::dataRef()). Everything that holds a site's data today speaks `scope:<id>`, so a new site works
 * like the main one from its first request, and when the alias goes (owner decision 2026-10-12: once the data uses the
 * scope's own reference) it goes for every site by the same move.
 *
 * `scope:site` is the record ports' name for «the site's own screens», so `site` is no site's key.
 */
final readonly class SiteScopes
{
    public const KEY_PATTERN = '/\A[a-z][a-z0-9_-]{1,62}\z/D';

    private const RESERVED = ['site'];

    private const NAME_LENGTH = 120;

    public function __construct(
        private DatabaseScopeRegistry $scopes,
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * The site's scope reference for a free key; nothing written.
     *
     * @throws SiteCreationRejected
     */
    public function check(string $key, string $name): ScopeRef
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || in_array($key, self::RESERVED, true)) {
            throw new SiteCreationRejected(SiteCreationRejected::KEY_INVALID);
        }
        if (trim($name) === '' || mb_strlen($name) > self::NAME_LENGTH) {
            throw new SiteCreationRejected(SiteCreationRejected::NAME_INVALID);
        }
        $ref = ScopeRef::of(ScopeKind::Site, $key);
        if ($this->scopes->read($ref) !== null || $this->scopes->resolveLegacy('scope:'.$key) !== null) {
            throw new SiteCreationRejected(SiteCreationRejected::KEY_TAKEN);
        }

        return $ref;
    }

    /**
     * @phpstan-impure
     *
     * @throws SiteCreationRejected
     */
    public function create(string $key, string $name, string $actorId): ScopeRecord
    {
        $ref = $this->check($key, $name);

        return $this->connection->transaction(function () use ($ref, $key, $name, $actorId): ScopeRecord {
            $this->scopes->create($ref, trim($name), $actorId);

            return $this->scopes->assignLegacyRef($ref, 'scope:'.$key);
        });
    }
}

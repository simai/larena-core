<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use Illuminate\Database\Connection;
use Larena\Core\Contracts\PlaneNodeRecord;
use Larena\Core\Contracts\PlaneRecord;
use Larena\Core\Enums\PlaneKind;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

/**
 * Creates the Minimal CMS scope and plane baseline.
 *
 * This is an idempotent install step, not a first-run contributor:
 * FirstRunCoordinator accepts the exact contributor list auth, setting and
 * content and throws on any other composition, so the baseline is created on
 * the install/starter path instead. The contributor composition changes in the
 * Content retirement batch.
 */
final class ScopeBaselineInstaller
{
    public const DEFAULT_SITE_IDENTIFIER = 'main';
    public const GROUPS_PLANE_KEY = 'groups';
    public const ADMINISTRATORS_NODE_KEY = 'administrators';
    public const INSTALLER_ACTOR = 'core.install';

    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseScopeRegistry $scopes,
        private readonly DatabasePlaneRegistry $planes,
    ) {
    }

    /** @phpstan-impure */
    public function isApplied(): bool
    {
        if (!$this->schemaReady()) {
            return false;
        }

        return $this->planes->readNode($this->administratorsNodeId()) !== null;
    }

    /**
     * Idempotent: a second run on a populated installation changes nothing.
     *
     * @return array<string, mixed>
          * @phpstan-impure
     */
    public function apply(?string $correlationId = null): array
    {
        if (!$this->schemaReady()) {
            return ['status' => 'schema_missing', 'created' => []];
        }

        $created = [];
        $siteRef = $this->defaultSiteRef();

        if ($this->scopes->read($siteRef) === null) {
            $this->scopes->create($siteRef, 'Main site', self::INSTALLER_ACTOR, null, $correlationId);
            $created[] = $siteRef->toString();
        }

        $planeId = $this->groupsPlaneId();
        if ($this->planes->readPlane($planeId) === null) {
            $this->planes->createPlane(
                $siteRef,
                self::GROUPS_PLANE_KEY,
                PlaneKind::Flat,
                'Groups',
                self::INSTALLER_ACTOR,
                $correlationId,
            );
            $created[] = $planeId;
        }

        $nodeId = $this->administratorsNodeId();
        if ($this->planes->readNode($nodeId) === null) {
            $this->planes->createNode(
                $planeId,
                self::ADMINISTRATORS_NODE_KEY,
                'Administrators',
                self::INSTALLER_ACTOR,
                null,
                0,
                $correlationId,
            );
            $created[] = $nodeId;
        }

        return [
            'status' => $created === [] ? 'already_applied' : 'applied',
            'created' => $created,
            'site_scope_ref' => $siteRef->toString(),
            'groups_plane_id' => $planeId,
            'administrators_node_id' => $nodeId,
        ];
    }

    public function defaultSiteRef(): ScopeRef
    {
        return ScopeRef::of(ScopeKind::Site, self::DEFAULT_SITE_IDENTIFIER);
    }

    public function groupsPlaneId(): string
    {
        return PlaneRecord::identity($this->defaultSiteRef(), self::GROUPS_PLANE_KEY);
    }

    public function administratorsNodeId(): string
    {
        return PlaneNodeRecord::identity($this->groupsPlaneId(), self::ADMINISTRATORS_NODE_KEY);
    }

    private function schemaReady(): bool
    {
        $builder = $this->connection->getSchemaBuilder();

        return $builder->hasTable(DatabaseScopeRegistry::TABLE)
            && $builder->hasTable(DatabasePlaneRegistry::PLANE_TABLE)
            && $builder->hasTable(DatabasePlaneRegistry::NODE_TABLE);
    }
}

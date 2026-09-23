<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\NodeStatus;
use Larena\Core\Plane\NodePath;

final readonly class PlaneNodeRecord
{
    public function __construct(
        public string $nodeId,
        public string $planeId,
        public ?string $parentNodeId,
        public string $nodeKey,
        public NodePath $path,
        public int $orderIndex,
        public string $name,
        public NodeStatus $status,
        public string $createdBy,
        public ?string $correlationId = null,
    ) {
    }

    public static function identity(string $planeId, string $nodeKey): string
    {
        return $planeId . '#' . $nodeKey;
    }

    public function depth(): int
    {
        return $this->path->depth();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'plane_id' => $this->planeId,
            'parent_node_id' => $this->parentNodeId,
            'node_key' => $this->nodeKey,
            'path' => $this->path->toString(),
            'depth' => $this->path->depth(),
            'order_index' => $this->orderIndex,
            'name' => $this->name,
            'status' => $this->status->value,
        ];
    }
}

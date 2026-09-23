<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use RuntimeException;

/**
 * Fail-closed signal for scope, plane, node and membership boundaries.
 *
 * The reason code is stable and safe to surface in diagnostics; the message
 * explains the boundary without exposing records the caller may not read.
 */
final class ScopeBoundaryViolation extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownScope(string $reference): self
    {
        return new self('unknown_scope', sprintf('Scope "%s" does not exist.', $reference));
    }

    public static function unknownPlane(string $planeId): self
    {
        return new self('unknown_plane', sprintf('Plane "%s" does not exist.', $planeId));
    }

    public static function unknownNode(string $nodeId): self
    {
        return new self('unknown_node', sprintf('Plane node "%s" does not exist.', $nodeId));
    }

    public static function archivedWrite(string $kind, string $identity): self
    {
        return new self('archived_write_denied', sprintf('Archived %s "%s" does not accept writes.', $kind, $identity));
    }

    public static function duplicate(string $kind, string $identity): self
    {
        return new self('duplicate_identity', sprintf('%s "%s" already exists and identities are never reused.', ucfirst($kind), $identity));
    }

    public static function invalidParent(string $message): self
    {
        return new self('invalid_parent', $message);
    }

    public static function cycleDetected(string $nodeId): self
    {
        return new self('cycle_detected', sprintf('Moving node "%s" into its own subtree is rejected.', $nodeId));
    }

    public static function crossPlane(string $nodeId): self
    {
        return new self('cross_plane_parent', sprintf('Node "%s" cannot be parented outside its plane.', $nodeId));
    }

    public static function nestingDenied(string $planeId): self
    {
        return new self('nesting_denied', sprintf('Flat plane "%s" accepts only root nodes.', $planeId));
    }

    public static function depthExceeded(int $depth, int $maximum): self
    {
        return new self('depth_exceeded', sprintf('Node depth %d exceeds the maximum of %d.', $depth, $maximum));
    }
}

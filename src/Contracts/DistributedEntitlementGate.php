<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * Whether this installation may operate across nodes.
 *
 * An unbound gate counts as no entitlement for the network transport, and is
 * irrelevant to the local one: the local transport is free.
 */
interface DistributedEntitlementGate
{
    public const REMOTE_OPERATIONS = 'distributed.remote_operations';

    public function allows(string $capability): bool;
}

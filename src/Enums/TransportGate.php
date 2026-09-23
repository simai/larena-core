<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

/**
 * The gates a network call must pass, in the order a diagnostic reports them.
 *
 * Every gate is evaluated and every missing one is listed, because a single
 * opaque denial is not evidence: an operator needs to know which gate to fix.
 */
enum TransportGate: string
{
    case DistributedEntitlement = 'distributed_entitlement';
    case NodeTrust = 'node_trust';
    case NetworkTransportImplementation = 'network_transport_implementation';

    /** @return list<self> */
    public static function ordered(): array
    {
        return [self::DistributedEntitlement, self::NodeTrust, self::NetworkTransportImplementation];
    }

    public function reasonCode(): string
    {
        return match ($this) {
            self::DistributedEntitlement => 'entitlement_missing',
            self::NodeTrust => 'node_trust_failed',
            self::NetworkTransportImplementation => 'transport_not_implemented',
        };
    }
}

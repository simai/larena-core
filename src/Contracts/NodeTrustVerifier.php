<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

interface NodeTrustVerifier
{
    public function isTrusted(string $nodeId): bool;
}

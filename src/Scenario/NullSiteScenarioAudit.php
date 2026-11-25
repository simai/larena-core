<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

use Larena\Core\Contracts\SiteScenarioAudit;

/** Core alone has no audit store; larena/admin binds the audit pipeline. */
final class NullSiteScenarioAudit implements SiteScenarioAudit
{
    public function record(string $operation, string $actor, array $payload): void
    {
    }
}

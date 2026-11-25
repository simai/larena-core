<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

use Larena\Core\Contracts\SiteCapabilityLicence;

/** No licensing composed: nothing is allowed, as SolutionPlanner treats a free installation. */
final class DeniedSiteCapabilityLicence implements SiteCapabilityLicence
{
    public function allows(string $capability): bool
    {
        return false;
    }
}

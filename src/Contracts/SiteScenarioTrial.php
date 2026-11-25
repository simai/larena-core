<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Scenario\SiteScenarioTrialResult;

/**
 * «Попробовать бесплатно»: asks the registration server for a trial of the paid capabilities a scenario needs. A trial
 * is a licence of kind `trial` there; the site gets it as a fresh signed entitlement snapshot and then allows() says
 * yes. Until the server has a trial API the seam answers `trial_unavailable` (UnavailableSiteScenarioTrial).
 */
interface SiteScenarioTrial
{
    public function available(): bool;

    /** @param list<string> $capabilities */
    public function request(string $scenarioId, array $capabilities, string $actor): SiteScenarioTrialResult;
}

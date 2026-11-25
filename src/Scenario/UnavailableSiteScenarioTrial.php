<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

use Larena\Core\Contracts\SiteScenarioTrial;

/**
 * The seam of «Попробовать бесплатно» until the registration server has a trial API: the request is answered
 * `trial_unavailable` and the site is made without the paid capabilities, which a later licence or trial switches on
 * when the scenario is applied again.
 */
final class UnavailableSiteScenarioTrial implements SiteScenarioTrial
{
    public function available(): bool
    {
        return false;
    }

    public function request(string $scenarioId, array $capabilities, string $actor): SiteScenarioTrialResult
    {
        return new SiteScenarioTrialResult(SiteScenarioTrialResult::UNAVAILABLE, [], null, 'registration_trial_api_missing');
    }
}

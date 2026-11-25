<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

use Larena\Core\Contracts\SiteScenarioSettings;

/** No settings store composed: starter settings are reported unavailable, nothing is written. */
final class UnavailableSiteScenarioSettings implements SiteScenarioSettings
{
    public function available(): bool
    {
        return false;
    }

    public function putMissing(array $values, string $actor): array
    {
        return [];
    }
}

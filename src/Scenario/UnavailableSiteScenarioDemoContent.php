<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

use Larena\Core\Contracts\SiteScenarioDemoContent;

/** No SitePack importer composed (larena/storage binds the real one): demo content is reported unavailable. */
final class UnavailableSiteScenarioDemoContent implements SiteScenarioDemoContent
{
    public function available(): bool
    {
        return false;
    }

    public function import(SiteScenario $scenario, string $sitePackPath, string $actor): string
    {
        return self::UNAVAILABLE;
    }
}

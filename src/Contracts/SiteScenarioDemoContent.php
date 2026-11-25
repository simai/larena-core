<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Scenario\SiteScenario;

/**
 * Imports a scenario's demo content: its SitePack archive, through the SitePack import (receive, plan as a dry run,
 * apply). The owner of the archive format binds it (larena/storage); unbound, demo content is reported unavailable.
 */
interface SiteScenarioDemoContent
{
    public const IMPORTED = 'imported';
    public const UNAVAILABLE = 'unavailable';
    public const FAILED = 'failed';

    public function available(): bool;

    /** @return string IMPORTED, or FAILED when the pack was refused (its plan had errors) */
    public function import(SiteScenario $scenario, string $sitePackPath, string $actor): string;
}

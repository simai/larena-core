<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Larena\Core\Contracts\SiteLanguages;

/** The same languages for every site, as an application with no site settings configures them. */
final readonly class ConfiguredSiteLanguages implements SiteLanguages
{
    /** @param list<string> $languages */
    public function __construct(private array $languages, private string $main)
    {
    }

    public function languages(string $siteRef): array
    {
        return array_values(array_unique([$this->main, ...$this->languages]));
    }

    public function main(string $siteRef): string
    {
        return $this->main;
    }

    public function mainInFolder(string $siteRef): bool
    {
        return false;
    }

    public function ownStructure(string $siteRef): bool
    {
        return false;
    }
}

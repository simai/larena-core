<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * A site's languages (sites plan, steps 4.2 and 4.4). Core asks, and whoever keeps a site's settings answers: core
 * cannot read settings. Without that the configuration answers (ConfiguredSiteLanguages).
 */
interface SiteLanguages
{
    /**
     * The site's languages, its main language among them.
     *
     * @return list<string>
     */
    public function languages(string $siteRef): array;

    /** The language a site's shared values are written in, the one with no folder. */
    public function main(string $siteRef): string;

    /** Whether the main language has its folder too («Основной язык тоже в папке», step 4.9). */
    public function mainInFolder(string $siteRef): bool;

    /**
     * Whether a language shows only the pages filled in on it («своя структура», step 4.8); otherwise a page out in the
     * main language is seen on every language with its source text.
     */
    public function ownStructure(string $siteRef): bool;
}

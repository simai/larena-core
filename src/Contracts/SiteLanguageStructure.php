<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Closure;

/**
 * Changing how a site's languages stand (sites plan, steps 4.8 and 4.9): the common or own structure, and «Основной язык
 * тоже в папке». Whoever keeps the site's pages answers what a change would do and carries it out; whoever keeps the
 * settings writes them, so a change is previewed first and made once, with its redirects.
 */
interface SiteLanguageStructure
{
    public const COMMON = 'common';

    public const OWN = 'own';

    /**
     * What the change would do, read now: how many pages appear and hide in other languages, a few of those addresses,
     * and how many redirect rules move.
     *
     * @return array{appear: int, hide: int, samples: list<string>, rules: int, folder: ?string}
     */
    public function preview(string $siteRef, string $scheme, bool $mainInFolder): array;

    /**
     * Makes the change: its rules, $settings (which writes the settings), then what reads the addresses.
     *
     * @param Closure(): void $settings
     */
    public function apply(string $siteRef, string $scheme, bool $mainInFolder, string $actor, Closure $settings): void;

    /** Whether pages are out in the site's main language: its shared values then already speak it. */
    public function mainLanguageInUse(string $siteRef): bool;
}

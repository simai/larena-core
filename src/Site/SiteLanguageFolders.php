<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Larena\Core\Contracts\SiteLanguages;

/**
 * Where a language's addresses stand in a site (larena-specs docs/architecture/2026-10-29-sites-pages-languages.md §4.4):
 * the main language at the site's root, every other in the folder of its code; the main language too when the site says
 * so. Whatever writes an address a visitor will open asks here, so they all agree.
 */
final readonly class SiteLanguageFolders
{
    public function __construct(private SiteLanguages $languages)
    {
    }

    /** `/en` for a language in a folder, nothing for one at the root. */
    public function prefix(string $siteRef, string $locale): string
    {
        return $locale === $this->languages->main($siteRef) && !$this->languages->mainInFolder($siteRef) ? '' : '/' . $locale;
    }

    /** A page's address in the site as a visitor opens it in a language: `/about` is `/en/about`, `/` is `/en`. */
    public function address(string $siteRef, string $locale, string $address): string
    {
        return self::under($this->prefix($siteRef, $locale), $address);
    }

    public static function under(string $prefix, string $address): string
    {
        return $prefix === '' ? $address : $prefix . ($address === '/' ? '' : $address);
    }
}

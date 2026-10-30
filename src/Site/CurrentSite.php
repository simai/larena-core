<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Larena\Core\Contracts\SiteBinding;
use Larena\Core\Enums\SiteMatch;
use Larena\Core\Scope\ScopeRef;

/**
 * The site of the current request: its scope, the domain it came by (null for the default site), that domain's
 * language (null is every language), its folder (empty when none) and how it was found.
 */
final readonly class CurrentSite
{
    /** The request attribute that carries it, beside the container binding. */
    public const ATTRIBUTE = 'larena.site';

    public function __construct(
        public ScopeRef $site,
        public ?SiteBinding $binding,
        public ?string $locale,
        public string $folder,
        public SiteMatch $matched,
    ) {
    }

    public static function byDefault(ScopeRef $site): self
    {
        return new self($site, null, null, '', SiteMatch::Default);
    }

    /** The request path inside the site's folder: `/shop/a` on a `/shop` domain is `/a`. */
    public function pathInSite(string $path): string
    {
        if ($this->folder === '' || !SiteMap::inFolder($this->folder, $path)) {
            return $path;
        }
        $rest = substr($path, strlen($this->folder));

        return $rest === '' ? '/' : $rest;
    }
}

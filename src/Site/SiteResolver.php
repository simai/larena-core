<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Larena\Core\Enums\SiteBindingRole;
use Larena\Core\Enums\SiteMatch;
use Larena\Core\Scope\ScopeRef;

/**
 * Finds the site of a request from the compiled map (sites plan, stage 2.2): exact host with its longest folder,
 * then a `*.` host with its longest folder, then the default site; the product's root scope stands in when no site
 * is marked default. Redirect domains point at the main domain built from stored domains only, never from the
 * request's Host header.
 */
final class SiteResolver
{
    /** @param 'http'|'https'|null $scheme the scheme of redirect targets; null follows the request */
    public function __construct(
        private readonly SiteMapStore $store,
        private readonly ScopeRef $fallbackSite,
        private readonly ?string $scheme = null,
    ) {
    }

    /** @phpstan-impure */
    public function resolve(string $host, string $path): CurrentSite
    {
        $map = $this->store->get();
        $found = $map->isEmpty() ? null : $map->match($host, self::path($path));
        if ($found === null) {
            return CurrentSite::byDefault($this->defaultSite());
        }
        [$binding, $matched] = $found;

        return new CurrentSite($binding->site, $binding, $binding->locale, $binding->folder ?? '', $matched);
    }

    /** @phpstan-impure */
    public function defaultSite(): ScopeRef
    {
        return $this->store->get()->defaultSite() ?? $this->fallbackSite;
    }

    /** @phpstan-impure the site marked default, null while none is: then defaultSite() is the product's root scope */
    public function markedDefault(): ?ScopeRef
    {
        return $this->store->get()->defaultSite();
    }

    /**
     * Where a request on a redirect domain goes: the site's main domain for the same language (or for every
     * language) in this environment, the folder swapped, the rest of the path and the query kept. Null when the
     * domain is not a redirect one or no concrete main domain exists.
     *
     * @phpstan-impure
     */
    public function redirectTarget(CurrentSite $current, string $path, ?string $query, bool $secure): ?string
    {
        $binding = $current->binding;
        if ($binding === null || $binding->role !== SiteBindingRole::Redirect || $current->matched === SiteMatch::Default) {
            return null;
        }
        $main = $this->store->get()->mainOf($binding->site, $binding->locale);
        if ($main === null || $main->isWildcard()) {
            return null;
        }
        $rest = $current->pathInSite(self::path($path));
        $target = ($main->folder ?? '').($rest === '/' && $main->folder !== null ? '' : $rest);
        $scheme = $this->scheme ?? ($secure ? 'https' : 'http');
        $query = $query === null || $query === '' ? '' : '?'.self::safe($query);

        return $scheme.'://'.$main->host.self::safe($target === '' ? '/' : $target).$query;
    }

    private static function path(string $path): string
    {
        return '/'.ltrim($path, '/');
    }

    /** Bytes a URL may not carry raw (controls, spaces, non-ASCII) are percent-encoded; nothing else is touched. */
    private static function safe(string $part): string
    {
        return (string) preg_replace_callback('/[^\x21-\x7E]/', static fn (array $byte): string => rawurlencode($byte[0]), $part);
    }
}

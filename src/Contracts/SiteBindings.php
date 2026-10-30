<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\SiteEnvironment;
use Larena\Core\Scope\ScopeRef;

/**
 * The domains of each site and the default site (sites plan, stage 2.1). Core keeps them, so a request finds its
 * site without the admin or storage packages (Larena Headless).
 */
interface SiteBindings
{
    /** @return list<SiteBinding> the site's domains in its order */
    public function list(ScopeRef $site): array;

    /** @return list<SiteBinding> every site's domains in one environment (all environments when null) */
    public function all(?SiteEnvironment $environment = null): array;

    /**
     * Replace the site's whole list in one validated write, the way an editor saves it. Each entry has `host` and
     * may have `folder`, `locale`, `role` (default alias; the first entry is always main) and `environment`
     * (default production). Refusals carry a `site_binding_*` code.
     *
     * @param array<mixed> $bindings a list of entries; anything else is refused with site_binding_list_invalid
     * @return list<SiteBinding>
     *
     * @throws \Larena\Core\Exceptions\SiteBindingRejected
     */
    public function replace(ScopeRef $site, array $bindings, string $actorId): array;

    /** The site marked default, or null when none is marked. */
    public function defaultSite(): ?ScopeRef;

    /** @throws \Larena\Core\Exceptions\SiteBindingRejected */
    public function setDefaultSite(ScopeRef $site, string $actorId): void;
}

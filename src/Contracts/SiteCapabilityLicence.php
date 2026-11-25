<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * Whether the site may switch a capability on (owner decisions 2026-11-25): code asks for a right to a function, never
 * for a plan; paid or free is catalog data. The composition binds the site's licensing (larena/licensing
 * `SiteLicensing::allows`); unbound, nothing is allowed — a free installation holds nothing it was not given.
 */
interface SiteCapabilityLicence
{
    public function allows(string $capability): bool;
}

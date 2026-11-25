<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * The capabilities switched on for the site: what its scenarios turned on (and, later, the «Возможности сайта» screen).
 * A switch is on only when the licence allowed it at the time; a package asks here whether its function is wanted.
 */
interface SiteCapabilitySwitches
{
    /** @return list<string> the capabilities switched on, sorted */
    public function enabled(): array;

    public function on(string $capability): bool;
}

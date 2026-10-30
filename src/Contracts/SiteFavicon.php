<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * The address of a site's icon, for the pages of that site. Who keeps it (the admin's site settings) binds this; an
 * application without one has no icon.
 */
interface SiteFavicon
{
    public function href(?string $site): ?string;
}

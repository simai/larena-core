<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

/** What a site's domain does: shows the site as its main address, shows it as an alias, or redirects to the main one. */
enum SiteBindingRole: string
{
    case Main = 'main';
    case Alias = 'alias';
    case Redirect = 'redirect';
}

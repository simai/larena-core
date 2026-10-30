<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

/** How a request found its site: by its exact host, by a `*.` host, or as the default site. */
enum SiteMatch: string
{
    case Exact = 'exact';
    case Wildcard = 'wildcard';
    case Default = 'default';
}

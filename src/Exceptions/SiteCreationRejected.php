<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use InvalidArgumentException;

/** A new site was refused. The code (`site_*`) is stable and safe to show. */
final class SiteCreationRejected extends InvalidArgumentException
{
    public const KEY_INVALID = 'site_key_invalid';
    public const KEY_TAKEN = 'site_key_taken';
    public const NAME_INVALID = 'site_name_invalid';

    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}

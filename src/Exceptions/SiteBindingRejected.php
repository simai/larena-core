<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use InvalidArgumentException;

/**
 * A site's domain list was refused. The code (`site_binding_*`) is stable and safe to show; `position` names the
 * entry at fault when there is one.
 */
final class SiteBindingRejected extends InvalidArgumentException
{
    public const SITE_UNKNOWN = 'site_binding_site_unknown';
    public const SITE_ARCHIVED = 'site_binding_site_archived';
    public const LIST_INVALID = 'site_binding_list_invalid';
    public const HOST_INVALID = 'site_binding_host_invalid';
    public const FOLDER_INVALID = 'site_binding_folder_invalid';
    public const LOCALE_INVALID = 'site_binding_locale_invalid';
    public const ROLE_INVALID = 'site_binding_role_invalid';
    public const ENVIRONMENT_INVALID = 'site_binding_environment_invalid';
    public const DUPLICATE = 'site_binding_duplicate';
    public const TAKEN = 'site_binding_taken';
    public const MAIN_DUPLICATE = 'site_binding_main_duplicate';
    public const MAIN_MISSING = 'site_binding_main_missing';

    public function __construct(
        public readonly string $reasonCode,
        public readonly ?int $position = null,
    ) {
        parent::__construct($position === null ? $reasonCode : $reasonCode.' at '.$position);
    }
}

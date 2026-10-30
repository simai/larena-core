<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

/** Where an installation runs; a site's domains are kept per environment. */
enum SiteEnvironment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';

    /** Laravel's APP_ENV: production is production, local and testing are development, anything else is staging. */
    public static function fromAppEnv(string $appEnv): self
    {
        return match (strtolower(trim($appEnv))) {
            'production' => self::Production,
            'local', 'testing' => self::Development,
            default => self::Staging,
        };
    }
}

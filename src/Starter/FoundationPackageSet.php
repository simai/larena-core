<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

final class FoundationPackageSet
{
    /**
     * @return list<string>
     */
    public static function runtimeSecurity(): array
    {
        return [
            'larena/core',
            'larena/access',
            'larena/audit',
            'larena/licensing',
        ];
    }

    /**
     * @return list<string>
     */
    public static function dataContent(): array
    {
        return [
            'larena/storage',
            'larena/filesystem',
            'larena/lang',
            'larena/search',
            'larena/link',
            'larena/backup',
            'larena/file-manager',
        ];
    }

    /**
     * @return list<string>
     */
    public static function frontendCompositionAdmin(): array
    {
        return [
            'larena/setting',
            'larena/property',
            'larena/admin',
        ];
    }

    /**
     * @return list<string>
     */
    public static function foundationPreview(): array
    {
        return [
            ...self::runtimeSecurity(),
            ...self::dataContent(),
            ...self::frontendCompositionAdmin(),
        ];
    }
}

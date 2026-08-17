<?php

declare(strict_types=1);

namespace Larena\Core\WebInstall;

final readonly class NullWebInstallPostMigrationHook implements WebInstallPostMigrationHook
{
    public function afterMigrations(): void
    {
    }
}

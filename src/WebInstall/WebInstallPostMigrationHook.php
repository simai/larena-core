<?php

declare(strict_types=1);

namespace Larena\Core\WebInstall;

interface WebInstallPostMigrationHook
{
    public function afterMigrations(): void;
}

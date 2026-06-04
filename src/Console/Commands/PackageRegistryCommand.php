<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Starter\StarterScenario;

final class PackageRegistryCommand extends Command
{
    protected $signature = 'larena:packages';

    protected $description = 'Inspect the local Larena package registry without mutating state.';

    public function handle(Application $app): int
    {
        $report = StarterScenario::packageRegistryDiagnostics(StarterScenario::contextFromApplication($app));

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return in_array($report['status'] ?? null, ['passed', 'degraded', 'missing'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}

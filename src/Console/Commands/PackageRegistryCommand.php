<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Console\Support\CommandReportPresenter;
use Larena\Core\Starter\StarterScenario;

final class PackageRegistryCommand extends Command
{
    protected $signature = 'larena:packages
        {--json : Print machine-readable JSON only}
        {--full : Print human summary and full JSON}';

    protected $description = 'Inspect the local Larena package registry without mutating state.';

    public function handle(Application $app): int
    {
        $report = StarterScenario::packageRegistryDiagnostics(StarterScenario::contextFromApplication($app));

        CommandReportPresenter::render($this, 'Larena package registry diagnostics', $report);

        return in_array($report['status'] ?? null, ['passed', 'degraded', 'missing'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}

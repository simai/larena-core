<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Starter\StarterScenario;

final class InstallCommand extends Command
{
    protected $signature = 'larena:install {--dry-run : Build the install plan without mutating application state}';

    protected $description = 'Build the Larena starter install plan.';

    public function handle(Application $app): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $outputPath = $app->basePath($dryRun
            ? 'docs/project-management/evidence/starter-cli/install-dry-run-output.json'
            : 'docs/project-management/evidence/starter-cli/install-blocked-output.json');
        $report = StarterScenario::installPlan($outputPath, $dryRun, StarterScenario::contextFromApplication($app));

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}

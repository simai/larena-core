<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Starter\RuntimeSecurityClusterSmoke;
use Larena\Core\Starter\StarterScenario;

final class ClusterSmokeCommand extends Command
{
    protected $signature = 'larena:cluster-smoke';

    protected $description = 'Run the read-only Larena runtime/security cluster smoke report.';

    public function handle(Application $app): int
    {
        $outputPath = $app->basePath(
            'docs/project-management/evidence/starter-cli/runtime-security-cluster-smoke/cluster-smoke-output.json',
        );

        $report = RuntimeSecurityClusterSmoke::run($outputPath, StarterScenario::contextFromApplication($app));

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($report['status'] ?? null) === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}

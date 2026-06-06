<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Console\Support\CommandReportPresenter;
use Larena\Core\Starter\DataContentClusterSmoke;
use Larena\Core\Starter\StarterEvidencePath;
use Larena\Core\Starter\StarterScenario;

final class DataContentSmokeCommand extends Command
{
    protected $signature = 'larena:data-content-smoke
        {--json : Print machine-readable JSON only}
        {--full : Print human summary and full JSON}';

    protected $description = 'Run the read-only Larena data/content foundation diagnostics smoke report.';

    public function handle(Application $app): int
    {
        $context = StarterScenario::contextFromApplication($app);
        $outputPath = StarterEvidencePath::path(
            $context,
            'foundation-developer-preview/data-content-smoke/data-content-smoke-output.json',
        );

        $report = DataContentClusterSmoke::run($outputPath, $context);

        CommandReportPresenter::render($this, 'Larena data/content foundation smoke', $report);

        return ($report['status'] ?? null) === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}

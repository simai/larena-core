<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Console\Support\CommandReportPresenter;
use Larena\Core\Starter\StarterEvidencePath;
use Larena\Core\Starter\StarterScenario;

final class DoctorCommand extends Command
{
    protected $signature = 'larena:doctor
        {--json : Print machine-readable JSON only}
        {--full : Print human summary and full JSON}';

    protected $description = 'Run Larena starter environment and package diagnostics.';

    public function handle(Application $app): int
    {
        $context = StarterScenario::contextFromApplication($app);
        $outputPath = StarterEvidencePath::path($context, 'starter-cli/doctor-output.json');
        $report = StarterScenario::doctor($outputPath, $context);

        CommandReportPresenter::render($this, 'Larena starter diagnostics', $report);

        return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}

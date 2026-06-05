<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Console\Support\CommandReportPresenter;
use Larena\Core\Starter\StarterEvidencePath;
use Larena\Core\Starter\StarterScenario;

final class InstallCommand extends Command
{
    protected $signature = 'larena:install
        {--dry-run : Build the install plan without mutating application state}
        {--launch-record= : Apply a guarded install launch record}
        {--confirm= : Explicit confirmation for the guarded mutation}
        {--json : Print machine-readable JSON only}
        {--full : Print human summary and full JSON}';

    protected $description = 'Build the Larena starter install plan.';

    public function handle(Application $app): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $launchRecord = $this->option('launch-record');
        $confirmation = $this->option('confirm');
        $context = StarterScenario::contextFromApplication($app);

        if (is_string($launchRecord) && $launchRecord !== '') {
            $report = StarterScenario::applyInstallLaunchRecord(
                $launchRecord,
                is_string($confirmation) ? $confirmation : '',
                $context,
            );

            CommandReportPresenter::render($this, 'Larena guarded install apply', $report);

            return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $outputPath = StarterEvidencePath::path(
            $context,
            $dryRun ? 'starter-cli/install-dry-run-output.json' : 'starter-cli/install-blocked-output.json',
        );
        $report = StarterScenario::installPlan($outputPath, $dryRun, $context);

        CommandReportPresenter::render($this, $dryRun ? 'Larena install dry-run' : 'Larena install guard', $report);

        return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}

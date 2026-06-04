<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Starter\StarterScenario;

final class DoctorCommand extends Command
{
    protected $signature = 'larena:doctor';

    protected $description = 'Run Larena starter environment and package diagnostics.';

    public function handle(Application $app): int
    {
        $outputPath = $app->basePath('docs/project-management/evidence/starter-cli/doctor-output.json');
        $report = StarterScenario::doctor($outputPath, StarterScenario::contextFromApplication($app));

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}

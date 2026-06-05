<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Console\Support\CommandReportPresenter;
use Larena\Core\Diagnostics\RuntimeSecuritySmoke;
use Larena\Core\Starter\StarterScenario;

final class RuntimeSecuritySmokeCommand extends Command
{
    protected $signature = 'larena:runtime-security-smoke
        {--json : Print machine-readable JSON only}
        {--full : Print human summary and full JSON}';

    protected $description = 'Run the Larena runtime/security Laravel-level smoke scenario.';

    public function handle(Application $app): int
    {
        $outputPath = $app->basePath('docs/project-management/evidence/runtime-security/laravel-smoke/smoke-output.json');
        $report = RuntimeSecuritySmoke::run($outputPath, StarterScenario::contextFromApplication($app));

        CommandReportPresenter::render($this, 'Larena runtime/security smoke', [
            'schema' => 'larena.runtime_security_laravel_smoke.command.v1',
            'status' => $report['status'],
            'evidence_path' => $outputPath,
            'generated_at' => gmdate('c'),
        ]);

        return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}

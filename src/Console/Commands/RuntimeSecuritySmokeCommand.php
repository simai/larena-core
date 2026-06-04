<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Diagnostics\RuntimeSecuritySmoke;
use Larena\Core\Starter\StarterScenario;

final class RuntimeSecuritySmokeCommand extends Command
{
    protected $signature = 'larena:runtime-security-smoke';

    protected $description = 'Run the Larena runtime/security Laravel-level smoke scenario.';

    public function handle(Application $app): int
    {
        $outputPath = $app->basePath('docs/project-management/evidence/runtime-security/laravel-smoke/smoke-output.json');
        $report = RuntimeSecuritySmoke::run($outputPath, StarterScenario::contextFromApplication($app));

        $this->line(json_encode([
            'schema' => 'larena.runtime_security_laravel_smoke.command.v1',
            'status' => $report['status'],
            'evidence_path' => $outputPath,
            'generated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}

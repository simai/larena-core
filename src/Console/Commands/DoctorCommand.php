<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Console\Support\CommandReportPresenter;
use Larena\Core\Contracts\EnvironmentProfile;
use Larena\Core\Runtime\EnvironmentFingerprint;
use Larena\Core\Runtime\HostEnvironmentDetector;
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

        // The environment profile belongs in the doctor's report rather than in a
        // command of its own: an operator asking "is this host all right" should not
        // have to know that host capabilities are a separate subject. A mismatch is
        // reported, never repaired, and it does not fail the doctor — a declaration
        // that disagrees with a probe is a question for a human, not a broken
        // installation.
        $report['environment'] = $this->environmentSection($app);

        CommandReportPresenter::render($this, 'Larena starter diagnostics', $report);

        return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function environmentSection(Application $app): array
    {
        if (!$app->bound(EnvironmentProfile::class)) {
            return [
                'declared' => false,
                'note' => 'no environment profile is declared; every host capability reads as unknown, which is absent',
            ];
        }

        $profile = $app->make(EnvironmentProfile::class);
        $detector = HostEnvironmentDetector::forHost($app->storagePath());

        return [
            'declared' => true,
            'profile' => $profile->toArray(),
            'fingerprint' => EnvironmentFingerprint::of($profile),
            'detection' => $detector->report($profile),
        ];
    }
}

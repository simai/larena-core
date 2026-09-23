<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Console\Support\CommandReportPresenter;
use Larena\Core\Registry\OperationCoverageReport;

/**
 * Prints the operation coverage report and fails on anything fixable today.
 *
 * The report itself lives in the registry so that it can be tested without a
 * console; this command only finds the installed packages and presents it.
 */
final class OperationCoverageCommand extends Command
{
    protected $signature = 'core:operations:coverage
        {--json : Print machine-readable JSON only}
        {--full : Print human summary and full JSON}';

    protected $description = 'Report operation registry coverage and validate every package descriptor file.';

    public function handle(OperationCoverageReport $report, Application $app): int
    {
        $built = $report->build($this->installedPackages(), $this->revision($app));

        CommandReportPresenter::render($this, 'Larena operation registry coverage', $built);

        return $built['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string> package name => install path
     */
    private function installedPackages(): array
    {
        if (!class_exists(InstalledVersions::class)) {
            return [];
        }

        $packages = [];
        $names = InstalledVersions::getInstalledPackages();
        sort($names);

        foreach ($names as $name) {
            if (!str_starts_with($name, 'larena/')) {
                continue;
            }

            $path = InstalledVersions::getInstallPath($name);
            if (is_string($path) && $path !== '') {
                $packages[$name] = $path;
            }
        }

        return $packages;
    }

    private function revision(Application $app): string
    {
        $head = @file_get_contents($app->basePath('.git/HEAD'));

        return is_string($head) ? trim($head) : 'unknown';
    }
}

<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Contracts\InstalledReleaseSeal;
use Throwable;

/**
 * `php artisan larena:install-seal --from=<folder>`: the installer's step after a release was unpacked from its signed
 * archive ({@see InstalledReleaseSeal}). The folder holds the four files downloaded with the release. Exit 0 only
 * when the release was checked and sealed; the answer names a stable reason otherwise.
 */
final class InstallSealCommand extends Command
{
    protected $signature = 'larena:install-seal
        {--from= : Folder with release.json, release.json.sig, files.json and the release archive}
        {--json : Print machine-readable JSON only}';

    protected $description = 'Check a freshly installed release against its signed file list and keep the signed files with it.';

    public function handle(Application $app, InstalledReleaseSeal $seal): int
    {
        $from = $this->option('from');
        if (!is_string($from) || $from === '' || $from[0] !== '/') {
            return $this->answer(['sealed' => false, 'reason' => 'release_seal_source_invalid', 'version' => null, 'files' => 0]);
        }
        try {
            $result = $seal->seal($app->basePath(), $from);
        } catch (Throwable $failure) {
            $message = $failure->getMessage();
            $result = ['sealed' => false, 'reason' => preg_match('/\A[a-z][a-z0-9_]{0,80}\z/D', $message) === 1 ? $message : 'release_seal_failed',
                'version' => null, 'files' => 0];
        }

        return $this->answer($result);
    }

    /** @param array{sealed: bool, reason: ?string, version: ?string, files: int} $result */
    private function answer(array $result): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        } elseif ($result['sealed']) {
            $this->info('Release ' . (string) $result['version'] . ' checked (' . $result['files'] . ' files) and sealed.');
        } else {
            $this->error('Release not sealed: ' . (string) $result['reason']);
        }

        return $result['sealed'] ? self::SUCCESS : self::FAILURE;
    }
}

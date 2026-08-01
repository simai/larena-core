<?php

declare(strict_types=1);

namespace Larena\Core\WebInstall;

use Closure;
use Throwable;

final readonly class WebInstallStateStore
{
    public function __construct(private string $directory, private string $signingKey)
    {
        if ($directory === '' || $signingKey === '') {
            throw new WebInstallException('web_install_state_store_invalid');
        }
    }

    public function statePath(): string
    {
        return $this->directory.'/state.json';
    }

    public function candidatePath(): string
    {
        return $this->directory.'/database.candidate.json';
    }

    public function configurationPath(): string
    {
        return $this->directory.'/database.json';
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        $path = $this->statePath();
        if (!is_file($path) || is_link($path)) {
            return null;
        }

        try {
            $state = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new WebInstallException('web_install_state_tampered');
        }
        if (!is_array($state) || ($state['schema'] ?? null) !== 'larena.web_install_state') {
            throw new WebInstallException('web_install_state_tampered');
        }
        $signature = $state['signature'] ?? null;
        unset($state['signature']);
        if (!is_string($signature) || !hash_equals($signature, $this->signature($state))) {
            throw new WebInstallException('web_install_state_tampered');
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    public function write(array $state): void
    {
        $this->ensureDirectory();
        $state = ['schema' => 'larena.web_install_state', ...$state];
        unset($state['signature']);
        $state['signature'] = $this->signature($state);
        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $temporary = $this->directory.'/.state-'.bin2hex(random_bytes(8)).'.json';
        if (!is_string($encoded)
            || file_put_contents($temporary, $encoded.PHP_EOL, LOCK_EX) === false
            || !chmod($temporary, 0600)
            || !rename($temporary, $this->statePath())) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new WebInstallException('web_install_state_write_failed');
        }
    }

    /** @param array<string, mixed> $configuration */
    public function writeCandidate(array $configuration): void
    {
        $this->ensureDirectory();
        if (is_link($this->candidatePath()) || file_exists($this->configurationPath())) {
            throw new WebInstallException('web_install_configuration_write_failed');
        }
        $configuration = ['schema' => 'larena.web_install_database', ...$configuration];
        unset($configuration['signature']);
        $configuration['signature'] = $this->signature($configuration);
        $encoded = json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $temporary = $this->directory.'/.database-candidate-'.bin2hex(random_bytes(8)).'.json';
        if (!is_string($encoded)
            || file_put_contents($temporary, $encoded.PHP_EOL, LOCK_EX) === false
            || !chmod($temporary, 0600)
            || !rename($temporary, $this->candidatePath())) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new WebInstallException('web_install_configuration_write_failed');
        }
    }

    /** @return array<string, mixed> */
    public function readCandidate(): array
    {
        $path = $this->candidatePath();
        try {
            $configuration = is_file($path) && !is_link($path)
                ? json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR)
                : null;
        } catch (Throwable) {
            throw new WebInstallException('web_install_configuration_tampered');
        }
        if (!is_array($configuration) || ($configuration['schema'] ?? null) !== 'larena.web_install_database') {
            throw new WebInstallException('web_install_configuration_tampered');
        }
        $signature = $configuration['signature'] ?? null;
        unset($configuration['signature']);
        if (!is_string($signature) || !hash_equals($signature, $this->signature($configuration))) {
            throw new WebInstallException('web_install_configuration_tampered');
        }

        return $configuration;
    }

    public function activateCandidate(): void
    {
        if (!is_file($this->candidatePath()) || is_link($this->candidatePath())
            || file_exists($this->configurationPath()) || is_link($this->configurationPath())
            || !rename($this->candidatePath(), $this->configurationPath())) {
            throw new WebInstallException('web_install_configuration_activate_failed');
        }
    }

    public function discardCandidate(): void
    {
        if (is_file($this->candidatePath()) && !unlink($this->candidatePath())) {
            throw new WebInstallException('web_install_configuration_cleanup_failed');
        }
    }

    public function discardConfiguration(): void
    {
        if (is_file($this->configurationPath()) && !unlink($this->configurationPath())) {
            throw new WebInstallException('web_install_configuration_cleanup_failed');
        }
    }

    /** @param Closure():mixed $callback */
    public function withLock(Closure $callback): mixed
    {
        $this->ensureDirectory();
        $handle = fopen($this->directory.'/install.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new WebInstallException('web_install_concurrent_apply');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        if ((!is_dir($this->directory) && !mkdir($this->directory, 0700, true))
            || is_link($this->directory)
            || !chmod($this->directory, 0700)) {
            throw new WebInstallException('web_install_state_directory_unavailable');
        }
    }

    /** @param array<string, mixed> $value */
    private function signature(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new WebInstallException('web_install_state_encode_failed');
        }

        return hash_hmac('sha256', $encoded, $this->signingKey);
    }
}

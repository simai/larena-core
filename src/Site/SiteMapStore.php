<?php

declare(strict_types=1);

namespace Larena\Core\Site;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Larena\Core\Enums\SiteEnvironment;

/**
 * Where the compiled site map lives between requests: the process for a few seconds, the exported file under
 * bootstrap/cache when `larena:sites:cache` wrote one, the configured cache, and only then the database. A write of
 * site domains forgets all of them, the exported file included, so nothing serves a stale map.
 */
final class SiteMapStore
{
    public const CACHE_KEY = 'larena-core.sites.map.';
    private const PROCESS_SECONDS = 5;

    private ?SiteMap $map = null;
    private int $readAt = 0;

    /** @param Closure(): SiteMap $compile reads the database */
    public function __construct(
        private readonly Closure $compile,
        private readonly SiteEnvironment $environment,
        private readonly ?Repository $cache = null,
        private readonly ?string $file = null,
    ) {
    }

    public function environment(): SiteEnvironment
    {
        return $this->environment;
    }

    /** @phpstan-impure */
    public function get(): SiteMap
    {
        if ($this->map !== null && time() - $this->readAt < self::PROCESS_SECONDS) {
            return $this->map;
        }
        $map = $this->fromFile() ?? $this->fromCache();
        if ($map === null) {
            try {
                $map = ($this->compile)();
            } catch (\Exception) {
                // No database yet (the installer) or it is down (programming errors still surface): every request gets the default site, as before
                // domains existed, and nothing is kept so the next request asks again.
                return SiteMap::empty($this->environment);
            }
            $this->cache?->forever($this->key(), $map->toArray());
        }
        $this->readAt = time();

        return $this->map = $map;
    }

    /** @phpstan-impure */
    public function forget(): void
    {
        $this->map = null;
        foreach (SiteEnvironment::cases() as $environment) {
            $this->cache?->forget(self::CACHE_KEY.$environment->value);
        }
        $this->clearFile();
    }

    /**
     * Export the map of this environment to the PHP file; returns the file written.
     *
     * @phpstan-impure
     */
    public function writeFile(): string
    {
        if ($this->file === null) {
            throw new \LogicException('No site map file is configured.');
        }
        $map = ($this->compile)();
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The site map directory cannot be created.');
        }
        $temporary = $this->file.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temporary, '<?php return '.var_export($map->toArray(), true).';'.PHP_EOL, LOCK_EX) === false
            || !rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new \RuntimeException('The site map file cannot be written.');
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->file, true);
        }
        $this->map = $map;
        $this->readAt = time();

        return $this->file;
    }

    /** @phpstan-impure */
    public function clearFile(): bool
    {
        if ($this->file === null || !is_file($this->file)) {
            return false;
        }

        return unlink($this->file);
    }

    private function fromFile(): ?SiteMap
    {
        if ($this->file === null || !is_file($this->file)) {
            return null;
        }

        return SiteMap::fromArray(require $this->file, $this->environment);
    }

    private function fromCache(): ?SiteMap
    {
        return $this->cache === null ? null : SiteMap::fromArray($this->cache->get($this->key()), $this->environment);
    }

    private function key(): string
    {
        return self::CACHE_KEY.$this->environment->value;
    }
}

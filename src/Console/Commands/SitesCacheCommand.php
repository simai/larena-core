<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Larena\Core\Site\SiteMapStore;
use Illuminate\Console\Command;

/** Exports the site map of this environment to bootstrap/cache, so requests read a file instead of the cache. */
final class SitesCacheCommand extends Command
{
    protected $signature = 'larena:sites:cache
        {--clear : Remove the exported site map and the cached one}';

    protected $description = 'Export the compiled site domain map to bootstrap/cache, or clear it.';

    public function handle(SiteMapStore $store): int
    {
        if ($this->option('clear') === true) {
            $store->forget();
            $this->info('Site map cleared.');

            return self::SUCCESS;
        }
        $file = $store->writeFile();
        $this->info(sprintf('Site map for %s written to %s.', $store->environment()->value, $file));

        return self::SUCCESS;
    }
}

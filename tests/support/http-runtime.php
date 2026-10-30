<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

/**
 * The HTTP request, response and cache classes the site middleware tests need. The package vendor tree carries the
 * database and console parts of Laravel only, so the entry application's tree fills the gaps; the package's own
 * loader stays first, so every class it has still comes from it.
 */
function larena_core_require_http_runtime(): void
{
    if (class_exists(\Illuminate\Http\Request::class) && class_exists(\Illuminate\Cache\Repository::class)) {
        return;
    }
    $packageRoot = dirname(__DIR__, 2);
    $entryAppRoot = getenv('LARENA_ENTRY_APP_ROOT');
    $candidates = [
        dirname($packageRoot, 2) . '/app/vendor/autoload.php',
        dirname($packageRoot, 3) . '/larena/vendor/autoload.php',
    ];
    if (is_string($entryAppRoot) && $entryAppRoot !== '') {
        array_unshift($candidates, rtrim($entryAppRoot, '/') . '/vendor/autoload.php');
    }
    $own = ClassLoader::getRegisteredLoaders()[$packageRoot . '/vendor'] ?? null;
    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            if ($own !== null) {
                $own->unregister();
                $own->register(true);
            }

            return;
        }
    }

    fwrite(STDERR, "The site middleware tests need illuminate/http and illuminate/cache: install the entry app or set LARENA_ENTRY_APP_ROOT.\n");
    exit(1);
}

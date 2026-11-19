<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Providers\Registrars\SiteRegistrar;
use Larena\Core\Site\ResolveSite;
use Larena\Core\Site\ResolveSiteLanguage;

// Global site middleware is the application's assembly choice `larena-core.sites.middleware`: on by default (the
// CMS and the panel rely on it), off with the flag or LARENA_SITES_MIDDLEWARE=false.

function siteChoiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$both = [ResolveSite::class, ResolveSiteLanguage::class];

// The shipped config reads the switch from the environment with on as its default (the package's own vendor has no
// .env loader, so the line itself is checked; Laravel's env() turns "false" into false).
$config = (string) file_get_contents(dirname(__DIR__, 2) . '/config/larena-core.php');
siteChoiceAssert(
    str_contains($config, "'middleware' => (bool) env('LARENA_SITES_MIDDLEWARE', true),"),
    'the shipped config keeps site middleware on unless LARENA_SITES_MIDDLEWARE turns it off',
);

siteChoiceAssert(SiteRegistrar::globalMiddleware(true) === $both, 'on adds the site, then its language');
siteChoiceAssert(SiteRegistrar::globalMiddleware(false) === [], 'off adds nothing');
siteChoiceAssert(SiteRegistrar::globalMiddleware(null) === [], 'a missing value inside the config adds nothing');
siteChoiceAssert(SiteRegistrar::globalMiddleware('yes') === [], 'only an explicit true turns it on');

echo "SiteMiddlewareChoiceTest passed\n";

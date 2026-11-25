<?php

declare(strict_types=1);

namespace Larena\Core\Providers\Registrars;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Larena\Core\Contracts\SiteCapabilityLicence;
use Larena\Core\Contracts\SiteCapabilitySwitches;
use Larena\Core\Contracts\SiteScenarioAudit;
use Larena\Core\Contracts\SiteScenarioDemoContent;
use Larena\Core\Contracts\SiteScenarioSettings;
use Larena\Core\Contracts\SiteScenarioTrial;
use Larena\Core\Scenario\DatabaseSiteScenarioState;
use Larena\Core\Scenario\DeniedSiteCapabilityLicence;
use Larena\Core\Scenario\NullSiteScenarioAudit;
use Larena\Core\Scenario\SiteScenarioApplier;
use Larena\Core\Scenario\SiteScenarioCatalog;
use Larena\Core\Scenario\UnavailableSiteScenarioDemoContent;
use Larena\Core\Scenario\UnavailableSiteScenarioSettings;
use Larena\Core\Scenario\UnavailableSiteScenarioTrial;

/**
 * Site scenarios (owner decision 2026-11-25): the catalog (core's five and the folders packages name in
 * `larena-core.scenarios.paths`), the state of what the site's scenarios did, and the ports the composition binds — the
 * licence (larena/licensing through larena/admin), the settings and the audit (larena/admin), the demo content
 * (larena/storage) and the trial (the registration server, once it has a trial API). Each port's default here is the
 * honest «not there».
 */
final class ScenarioRegistrar
{
    public const CORE_SCENARIOS = __DIR__ . '/../../../resources/scenarios';

    public static function register(Application $app): void
    {
        $app->singleton(SiteScenarioCatalog::class, static function (Application $app): SiteScenarioCatalog {
            $paths = $app->make('config')->get('larena-core.scenarios.paths', []);
            $directories = ['larena/core' => self::CORE_SCENARIOS];
            foreach (is_array($paths) ? $paths : [] as $source => $path) {
                if (is_string($source) && is_string($path) && $path !== '') {
                    $directories[$source] = $path;
                }
            }

            return SiteScenarioCatalog::fromDirectories($directories);
        });
        $app->bind(DatabaseSiteScenarioState::class, static fn (Application $app): DatabaseSiteScenarioState
            => new DatabaseSiteScenarioState($app->make(DatabaseManager::class)->connection()));
        $app->bindIf(SiteCapabilitySwitches::class, DatabaseSiteScenarioState::class);
        $app->bindIf(SiteCapabilityLicence::class, DeniedSiteCapabilityLicence::class);
        $app->bindIf(SiteScenarioSettings::class, UnavailableSiteScenarioSettings::class);
        $app->bindIf(SiteScenarioDemoContent::class, UnavailableSiteScenarioDemoContent::class);
        $app->bindIf(SiteScenarioAudit::class, NullSiteScenarioAudit::class);
        $app->bindIf(SiteScenarioTrial::class, UnavailableSiteScenarioTrial::class);
        $app->bind(SiteScenarioApplier::class, static fn (Application $app): SiteScenarioApplier => new SiteScenarioApplier(
            $app->make(SiteScenarioCatalog::class),
            $app->make(DatabaseSiteScenarioState::class),
            $app->make(SiteCapabilityLicence::class),
            $app->make(SiteScenarioSettings::class),
            $app->make(SiteScenarioDemoContent::class),
            $app->make(SiteScenarioAudit::class),
        ));
    }
}

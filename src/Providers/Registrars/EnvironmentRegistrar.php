<?php

declare(strict_types=1);

namespace Larena\Core\Providers\Registrars;

use Illuminate\Contracts\Foundation\Application;
use Larena\Core\Contracts\EnvironmentProfile;
use Larena\Core\Runtime\DeclaredEnvironmentProfile;
use Larena\Core\Runtime\EnvironmentOperationHandlers;
use Larena\Core\Runtime\HostEnvironmentDetector;
use Larena\Core\Runtime\SolutionManifestValidator;
use Larena\Core\Runtime\SolutionOperationHandlers;
use Larena\Core\Runtime\SolutionPlanner;

/**
 * The environment profile, the host detector and solution planning.
 */
final class EnvironmentRegistrar
{
    public static function register(Application $app): void
    {
        // Ordinary hosting is the default because it is the profile this platform
        // targets: no worker, no Redis, no search engine. An installation that has
        // more says so by declaring its own profile, rather than the platform
        // assuming capabilities it cannot see.
        $app->bindIf(
            EnvironmentProfile::class,
            static fn (): EnvironmentProfile => DeclaredEnvironmentProfile::ordinaryHosting(),
        );

        $app->singleton(HostEnvironmentDetector::class, static function (Application $app): HostEnvironmentDetector {
            return HostEnvironmentDetector::forHost($app->storagePath());
        });

        $app->singleton(EnvironmentOperationHandlers::class, static function (Application $app): EnvironmentOperationHandlers {
            return new EnvironmentOperationHandlers(
                $app->make(EnvironmentProfile::class),
                $app->make(HostEnvironmentDetector::class),
            );
        });
        $app->singleton(SolutionManifestValidator::class);

        // No entitlement resolver is bound here, and that is the fail-closed
        // default: a topology with more than one node is refused until something
        // outside core says the installation holds the distributed capability.
        // Planning itself stays free — a single-node plan never asks.
        $app->bindIf(SolutionPlanner::class, static function (Application $app): SolutionPlanner {
            return new SolutionPlanner(null, $app->make(EnvironmentProfile::class));
        });

        $app->singleton(SolutionOperationHandlers::class, static function (Application $app): SolutionOperationHandlers {
            return new SolutionOperationHandlers(
                $app->make(SolutionManifestValidator::class),
                $app->make(SolutionPlanner::class),
            );
        });
    }
}

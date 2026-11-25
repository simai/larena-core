<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

use Larena\Core\Contracts\SiteCapabilityLicence;
use Larena\Core\Contracts\SiteScenarioAudit;
use Larena\Core\Contracts\SiteScenarioDemoContent;
use Larena\Core\Contracts\SiteScenarioSettings;
use Larena\Core\Exceptions\SiteScenarioRejected;

/**
 * Applies a scenario to the site: switches its capabilities on, writes its starter settings and imports its demo
 * SitePack (owner decision 2026-11-25, variant A).
 *
 * - Licensing decides, nothing else: a capability the licence does not allow stays off and is reported as needing a
 *   licence. Applying the scenario again once a licence (or a trial) allows it switches it on.
 * - Idempotent: a capability already on, a setting the site already has and demo content already imported are left
 *   as they are, so applying twice changes nothing the second time.
 * - A second scenario merges with the first: it adds its capabilities and the settings the site does not have; it
 *   never switches anything off or overwrites a value.
 * - «Пустой сайт» (SiteScenarioCatalog::EMPTY) applies nothing and writes nothing.
 * - Audited: every apply that changed something is recorded with ids and keys, never setting values.
 */
final readonly class SiteScenarioApplier
{
    public function __construct(
        private SiteScenarioCatalog $catalog,
        private DatabaseSiteScenarioState $state,
        private SiteCapabilityLicence $licence,
        private SiteScenarioSettings $settings,
        private SiteScenarioDemoContent $demo,
        private SiteScenarioAudit $audit,
    ) {
    }

    /**
     * The scenario's capabilities the licence does not allow now: what the wizard says needs a licence (or a trial).
     *
     * @return list<string>
     */
    public function needsLicence(SiteScenario $scenario): array
    {
        return array_values(array_filter($scenario->capabilities, fn (string $capability): bool => !$this->licence->allows($capability)));
    }

    public function apply(string $scenarioId, string $actor): SiteScenarioOutcome
    {
        if ($scenarioId === SiteScenarioCatalog::EMPTY) {
            return SiteScenarioOutcome::empty();
        }
        $actor = trim($actor);
        if ($actor === '') {
            throw new SiteScenarioRejected('scenario_actor_missing', 'Applying a scenario needs the person or process doing it.');
        }
        $scenario = $this->catalog->get($scenarioId);

        // 1. The capabilities, under the state's lock: on when the licence allows, otherwise reported.
        $switchedOn = $alreadyOn = $needsLicence = [];
        $before = $this->state->update(function (array $state) use ($scenario, &$switchedOn, &$alreadyOn, &$needsLicence): array {
            foreach ($scenario->capabilities as $capability) {
                if (isset($state['capabilities'][$capability])) {
                    $alreadyOn[] = $capability;
                } elseif ($this->licence->allows($capability)) {
                    $state['capabilities'][$capability] = $scenario->id;
                    $switchedOn[] = $capability;
                } else {
                    $needsLicence[] = $capability;
                }
            }

            return $state;
        });
        $known = $before['scenarios'][$scenario->id] ?? null;

        // 2. The starter settings the site does not have yet.
        $settingsUnavailable = $scenario->settings !== [] && !$this->settings->available();
        $written = $settingsUnavailable || $scenario->settings === [] ? [] : $this->settings->putMissing($scenario->settings, $actor);
        $written = array_values(array_intersect(array_keys($scenario->settings), $written));
        $kept = $settingsUnavailable ? [] : array_values(array_diff(array_keys($scenario->settings), $written));

        // 3. The demo content, once.
        $demo = SiteScenarioOutcome::DEMO_NONE;
        if ($scenario->sitePack !== null) {
            if (is_array($known) && ($known['demo'] ?? null) === SiteScenarioDemoContent::IMPORTED) {
                $demo = SiteScenarioOutcome::DEMO_KEPT;
            } elseif (!$this->demo->available()) {
                $demo = SiteScenarioDemoContent::UNAVAILABLE;
            } else {
                $demo = $this->demo->import($scenario, $scenario->sitePack, $actor) === SiteScenarioDemoContent::IMPORTED
                    ? SiteScenarioDemoContent::IMPORTED : SiteScenarioDemoContent::FAILED;
            }
        }

        $outcome = new SiteScenarioOutcome(
            scenarioId: $scenario->id,
            switchedOn: $switchedOn,
            alreadyOn: $alreadyOn,
            needsLicence: $needsLicence,
            settingsWritten: $written,
            settingsKept: $kept,
            settingsUnavailable: $settingsUnavailable,
            demo: $demo,
            firstTime: !is_array($known),
        );

        // 4. The scenario's record: kept from the first apply, refreshed when something changed.
        if ($outcome->changed() || ($known['needs_licence'] ?? null) !== $needsLicence) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $this->state->update(static function (array $state) use ($scenario, $outcome, $now): array {
                $record = $state['scenarios'][$scenario->id] ?? [];
                $state['scenarios'][$scenario->id] = [
                    'first_applied_at' => is_string($record['first_applied_at'] ?? null) ? $record['first_applied_at'] : $now,
                    'applied_at' => $now,
                    'needs_licence' => $outcome->needsLicence,
                    'demo' => ($record['demo'] ?? null) === SiteScenarioDemoContent::IMPORTED ? SiteScenarioDemoContent::IMPORTED : $outcome->demo,
                ];

                return $state;
            });
        }

        if ($outcome->changed()) {
            $this->audit->record($outcome->firstTime ? 'applied' : 'reapplied', $actor, [
                'scenario_id' => $scenario->id,
                'source' => $scenario->source,
                'switched_on' => $outcome->switchedOn,
                'needs_licence' => $outcome->needsLicence,
                'settings_written' => $outcome->settingsWritten,
                'settings_kept_count' => count($outcome->settingsKept),
                'demo' => $outcome->demo,
            ]);
        }

        return $outcome;
    }
}

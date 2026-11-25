<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

/** What applying a scenario did. Nothing in it was removed: a scenario only adds. */
final readonly class SiteScenarioOutcome
{
    public const DEMO_NONE = 'none';
    public const DEMO_KEPT = 'kept';

    /**
     * @param list<string> $switchedOn capabilities this apply switched on
     * @param list<string> $alreadyOn capabilities that were on already
     * @param list<string> $needsLicence capabilities left off: the licence does not allow them
     * @param list<string> $settingsWritten starter settings written (the site had none of them)
     * @param list<string> $settingsKept starter settings the site already had, left as they are
     * @param string $demo none, kept (imported before), or SiteScenarioDemoContent::IMPORTED|UNAVAILABLE|FAILED
     */
    public function __construct(
        public string $scenarioId,
        public array $switchedOn = [],
        public array $alreadyOn = [],
        public array $needsLicence = [],
        public array $settingsWritten = [],
        public array $settingsKept = [],
        public bool $settingsUnavailable = false,
        public string $demo = self::DEMO_NONE,
        public bool $firstTime = false,
    ) {
    }

    public static function empty(): self
    {
        return new self(SiteScenarioCatalog::EMPTY);
    }

    public function isEmpty(): bool
    {
        return $this->scenarioId === SiteScenarioCatalog::EMPTY;
    }

    /** Whether the apply changed anything: a capability, a setting, the demo content or the scenario's first record. */
    public function changed(): bool
    {
        return $this->firstTime || $this->switchedOn !== [] || $this->settingsWritten !== [] || $this->demo === \Larena\Core\Contracts\SiteScenarioDemoContent::IMPORTED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scenario_id' => $this->scenarioId,
            'changed' => $this->changed(),
            'first_time' => $this->firstTime,
            'switched_on' => $this->switchedOn,
            'already_on' => $this->alreadyOn,
            'needs_licence' => $this->needsLicence,
            'settings_written' => $this->settingsWritten,
            'settings_kept' => $this->settingsKept,
            'settings_unavailable' => $this->settingsUnavailable,
            'demo' => $this->demo,
        ];
    }
}

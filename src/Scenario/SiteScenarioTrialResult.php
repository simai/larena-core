<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

/** What the registration server said to «Попробовать бесплатно». */
final readonly class SiteScenarioTrialResult
{
    public const STARTED = 'trial_started';
    public const UNAVAILABLE = 'trial_unavailable';
    public const REFUSED = 'trial_refused';

    /** @param list<string> $capabilities the capabilities the trial gives */
    public function __construct(
        public string $status,
        public array $capabilities = [],
        public ?string $endsAt = null,
        public string $reasonCode = '',
    ) {
    }

    public function started(): bool
    {
        return $this->status === self::STARTED;
    }
}

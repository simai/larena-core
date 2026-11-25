<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

/**
 * A site scenario (owner decision 2026-11-25, variant A: one build, the person chooses what the site is for): a data
 * record of the capabilities to switch on, the starter settings and an optional SitePack of demo content. It installs
 * no package — the product is one build with all open code — so, unlike a solution manifest, two scenarios never
 * conflict: a second one adds to the first.
 *
 * Arriving as one of these means SiteScenarioValidator accepted the document.
 */
final readonly class SiteScenario
{
    /**
     * @param list<string> $capabilities
     * @param array<string, string|int|float|bool> $settings
     * @param string|null $sitePack the demo SitePack archive, an absolute path inside the declaring package
     */
    public function __construct(
        public string $id,
        public string $titleKey,
        public string $descriptionKey,
        public array $capabilities,
        public array $settings = [],
        public ?string $sitePack = null,
        public int $position = SiteScenarioValidator::DEFAULT_POSITION,
        public string $source = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scenario_id' => $this->id,
            'title' => $this->titleKey,
            'description' => $this->descriptionKey,
            'capabilities' => $this->capabilities,
            'settings' => array_keys($this->settings),
            'sitepack' => $this->sitePack !== null,
            'position' => $this->position,
            'source' => $this->source,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

use JsonException;
use Larena\Core\Exceptions\SiteScenarioRejected;

/**
 * The scenarios the installation offers: core's five (resources/scenarios) and any a package ships in a folder of its
 * own, named in `larena-core.scenarios.paths`. Each file is one scenario document (`*.json`); a scenario id is offered
 * once, by one package. Ordered by position, then id.
 */
final class SiteScenarioCatalog
{
    /** «Пустой сайт»: the wizard's choice of no scenario. It applies nothing and is never a record. */
    public const EMPTY = 'empty';

    /** @var array<string, SiteScenario> */
    private array $scenarios = [];

    /** @param iterable<SiteScenario> $scenarios */
    public function __construct(iterable $scenarios = [])
    {
        foreach ($scenarios as $scenario) {
            $this->add($scenario);
        }
    }

    /**
     * @param array<string, string> $directories source (a package name) => folder of scenario documents
     */
    public static function fromDirectories(array $directories, SiteScenarioValidator $validator = new SiteScenarioValidator()): self
    {
        $catalog = new self();
        foreach ($directories as $source => $directory) {
            if (!is_dir($directory)) {
                throw new SiteScenarioRejected('scenario_directory_missing', 'Not a folder of scenarios: ' . $directory);
            }
            $files = glob(rtrim($directory, '/') . '/*.json') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                try {
                    $document = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new SiteScenarioRejected('scenario_not_json', 'A scenario file is not JSON: ' . basename($file));
                }
                if (!is_array($document)) {
                    throw new SiteScenarioRejected('scenario_not_json', 'A scenario file is not a JSON object: ' . basename($file));
                }
                $catalog->add($validator->validate($document, dirname($file), (string) $source));
            }
        }

        return $catalog;
    }

    public function add(SiteScenario $scenario): void
    {
        if (isset($this->scenarios[$scenario->id])) {
            throw new SiteScenarioRejected('duplicate_scenario_id', 'Two scenarios claim the id ' . $scenario->id . '.');
        }
        $this->scenarios[$scenario->id] = $scenario;
    }

    /** @return list<SiteScenario> */
    public function all(): array
    {
        $scenarios = array_values($this->scenarios);
        usort($scenarios, static fn (SiteScenario $left, SiteScenario $right): int => [$left->position, $left->id] <=> [$right->position, $right->id]);

        return $scenarios;
    }

    public function has(string $id): bool
    {
        return isset($this->scenarios[$id]);
    }

    public function get(string $id): SiteScenario
    {
        return $this->scenarios[$id] ?? throw new SiteScenarioRejected('scenario_unknown', 'No scenario has the id ' . $id . '.');
    }

    /** Whether the wizard may take this choice: a scenario of the catalog or «Пустой сайт». */
    public function offers(string $id): bool
    {
        return $id === self::EMPTY || $this->has($id);
    }
}

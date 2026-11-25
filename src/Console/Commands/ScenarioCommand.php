<?php

declare(strict_types=1);

namespace Larena\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Translation\Translator;
use Larena\Core\Exceptions\SiteScenarioRejected;
use Larena\Core\Scenario\DatabaseSiteScenarioState;
use Larena\Core\Scenario\SiteScenarioApplier;
use Larena\Core\Scenario\SiteScenarioCatalog;

/**
 * The site scenarios from the command line: the list with what each needs a licence for and which are applied, and
 * adding a scenario later (it merges with what the site has: nothing is switched off or overwritten).
 */
final class ScenarioCommand extends Command
{
    protected $signature = 'larena:scenario
        {action=list : list or apply}
        {scenario? : the scenario id to apply}
        {--actor=system:console : who applies it, for the audit trail}
        {--json : print JSON}';

    protected $description = 'List the site scenarios, or apply one to the site (idempotent, merges with the others).';

    public function handle(SiteScenarioCatalog $catalog, SiteScenarioApplier $applier, DatabaseSiteScenarioState $state, Translator $translator): int
    {
        $action = (string) $this->argument('action');
        if ($action === 'list') {
            $applied = $state->applied();
            $rows = [];
            foreach ($catalog->all() as $scenario) {
                $rows[] = [
                    'scenario_id' => $scenario->id,
                    'title' => (string) $translator->get($scenario->titleKey),
                    'applied' => in_array($scenario->id, $applied, true),
                    'needs_licence' => $applier->needsLicence($scenario),
                ];
            }
            if ($this->option('json') === true) {
                $this->line((string) json_encode(['scenarios' => $rows, 'capabilities_on' => $state->enabled()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            $this->table(['Scenario', 'Title', 'Applied', 'Needs a licence'], array_map(static fn (array $row): array => [
                $row['scenario_id'], $row['title'], $row['applied'] ? 'yes' : 'no', implode(', ', $row['needs_licence']),
            ], $rows));

            return self::SUCCESS;
        }
        if ($action !== 'apply' || !is_string($this->argument('scenario'))) {
            $this->error('Use: larena:scenario list | larena:scenario apply <scenario>');

            return self::INVALID;
        }
        try {
            $outcome = $applier->apply((string) $this->argument('scenario'), (string) $this->option('actor'));
        } catch (SiteScenarioRejected $exception) {
            $this->error($exception->reasonCode);

            return self::FAILURE;
        }
        $this->line((string) json_encode($outcome->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($this->option('json') === true ? 0 : JSON_PRETTY_PRINT)));

        return self::SUCCESS;
    }
}

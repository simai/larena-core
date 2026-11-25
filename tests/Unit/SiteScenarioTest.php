<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Larena\Core\Contracts\SiteCapabilityLicence;
use Larena\Core\Contracts\SiteScenarioAudit;
use Larena\Core\Contracts\SiteScenarioDemoContent;
use Larena\Core\Contracts\SiteScenarioSettings;
use Larena\Core\Exceptions\SiteScenarioRejected;
use Larena\Core\Providers\Registrars\ScenarioRegistrar;
use Larena\Core\Scenario\DatabaseSiteScenarioState;
use Larena\Core\Scenario\SiteScenario;
use Larena\Core\Scenario\SiteScenarioApplier;
use Larena\Core\Scenario\SiteScenarioCatalog;
use Larena\Core\Scenario\SiteScenarioOutcome;
use Larena\Core\Scenario\SiteScenarioValidator;
use Larena\Core\Scenario\UnavailableSiteScenarioTrial;

// Site scenarios (owner decision 2026-11-25, variant A): five scenarios as data, applied idempotently, a second one
// merging with the first, a paid capability without a licence reported and left off, «Пустой сайт» applying nothing.

function scenario_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('SiteScenarioTest: ' . $message);
    }
}

function scenario_refused(callable $call, string $reason, string $message): void
{
    try {
        $call();
    } catch (SiteScenarioRejected $exception) {
        scenario_assert($exception->reasonCode === $reason, $message . ' (got ' . $exception->reasonCode . ')');

        return;
    }
    throw new RuntimeException('SiteScenarioTest: ' . $message . ' (not refused)');
}

/** @phpstan-impure */
function scenario_rows($connection): int
{
    return $connection->table('larena_install_state')->count();
}

// 1. The five shipped scenarios validate, in their order, with words in both languages.
$catalog = SiteScenarioCatalog::fromDirectories(['larena/core' => ScenarioRegistrar::CORE_SCENARIOS]);
$ids = array_map(static fn (SiteScenario $scenario): string => $scenario->id, $catalog->all());
scenario_assert($ids === ['company_site', 'online_store', 'blog', 'landing', 'staff_portal'], 'the five scenarios in order: ' . implode(',', $ids));
$words = [];
foreach (['en', 'ru'] as $locale) {
    $words[$locale] = require dirname(__DIR__, 2) . '/resources/lang/' . $locale . '/scenarios.php';
}
$word = static function (array $lines, string $key): ?string {
    foreach (explode('.', $key) as $part) {
        if (!is_array($lines) || !array_key_exists($part, $lines)) {
            return null;
        }
        $lines = $lines[$part];
    }

    return is_string($lines) ? $lines : null;
};
foreach ($catalog->all() as $scenario) {
    foreach (['en', 'ru'] as $locale) {
        foreach ([$scenario->titleKey, $scenario->descriptionKey] as $key) {
            scenario_assert($word($words[$locale], substr($key, strlen('larena-core::scenarios.'))) !== null, $locale . ' words for ' . $key);
        }
        foreach ($scenario->capabilities as $capability) {
            scenario_assert($word($words[$locale], 'capabilities.' . substr($capability, strlen('larena.capability.'))) !== null, $locale . ' name of ' . $capability);
        }
    }
    scenario_assert($scenario->settings === ['site.purpose' => $scenario->id] && $scenario->source === 'larena/core', 'starter settings of ' . $scenario->id);
}
// Every capability is one the registry declares (larena-specs, read when the checkout is beside the workspace).
$registry = getenv('LARENA_SPECS_ROOT') ?: dirname(__DIR__, 5) . '/larena-specs';
$registryFile = rtrim((string) $registry, '/') . '/specs/capabilities/registry.json';
if (is_file($registryFile)) {
    $declared = [];
    foreach (json_decode((string) file_get_contents($registryFile), true, 64, JSON_THROW_ON_ERROR)['capabilities'] as $entry) {
        $declared[$entry['id']] = $entry['default_tier'];
    }
    foreach ($catalog->all() as $scenario) {
        foreach ($scenario->capabilities as $capability) {
            scenario_assert(isset($declared[$capability]), 'the registry declares ' . $capability);
        }
    }
    $paid = static fn (string $id): array => array_values(array_filter($catalog->get($id)->capabilities, static fn (string $capability): bool => $declared[$capability] !== 'free'));
    scenario_assert($paid('staff_portal') === ['larena.capability.chat.base', 'larena.capability.workflow.base'] && $paid('online_store') === ['larena.capability.automation.base']
        && $paid('company_site') === [] && $paid('blog') === [] && $paid('landing') === [], 'the paid capabilities of the registry');
}

// 2. What a scenario document may not say.
$validator = new SiteScenarioValidator();
$base = ['schema' => 'larena.site_scenario', 'schema_version' => 1, 'scenario_id' => 'shop', 'title' => 'larena-core::scenarios.blog.title',
    'description' => 'larena-core::scenarios.blog.description', 'capabilities' => ['larena.capability.content.base']];
scenario_assert($validator->validate($base)->position === SiteScenarioValidator::DEFAULT_POSITION, 'a minimal scenario validates');
scenario_refused(static fn () => $validator->validate($base + ['packages' => ['larena/content']]), 'unknown_scenario_key', 'a scenario installs no package');
scenario_refused(static fn () => $validator->validate($base + ['sql' => 'INSERT']), 'raw_seed_forbidden', 'demo data enters through SitePack only');
scenario_refused(static fn () => $validator->validate(['scenario_id' => 'empty'] + $base), 'reserved_scenario_id', '«Пустой сайт» is not a record');
scenario_refused(static fn () => $validator->validate(['capabilities' => ['chat']] + $base), 'invalid_capability', 'a capability is a registry id');
scenario_refused(static fn () => $validator->validate(['capabilities' => []] + $base), 'invalid_capabilities', 'a scenario switches something on');
scenario_refused(static fn () => $validator->validate(['capabilities' => ['larena.capability.content.base', 'larena.capability.content.base']] + $base), 'duplicate_capability', 'twice');
scenario_refused(static fn () => $validator->validate(['title' => 'Блог'] + $base), 'invalid_lang_key', 'the title is a translation key');
scenario_refused(static fn () => $validator->validate($base + ['settings' => ['site.purpose' => ['a']]]), 'invalid_setting_value', 'a starter setting is one value');
scenario_refused(static fn () => $validator->validate($base + ['settings' => ['purpose' => 'x']]), 'invalid_setting_key', 'a setting key has a namespace');
scenario_refused(static fn () => $validator->validate($base + ['sitepack' => '../other.zip'], __DIR__), 'invalid_sitepack', 'a pack stays inside its package');
scenario_refused(static fn () => $validator->validate($base + ['sitepack' => 'demo/missing.zip'], __DIR__), 'sitepack_missing', 'a declared pack is there');
scenario_refused(static fn () => $validator->validate(['schema' => 'larena.solution_manifest'] + $base), 'schema_mismatch', 'a scenario is its own format');
scenario_refused(static fn () => new SiteScenarioCatalog([$validator->validate($base), $validator->validate($base)]), 'duplicate_scenario_id', 'an id is offered once');

// 3. Applying: the site's state, a licence that allows the free capabilities, settings, demo content and audit fakes.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$connection = $capsule->getConnection();
$connection->getSchemaBuilder()->create('larena_install_state', static function (Blueprint $table): void {
    $table->id();
    $table->string('state_key')->unique();
    $table->string('state_status');
    $table->string('launch_record_id')->nullable();
    $table->string('evidence_path')->nullable();
    $table->json('payload')->nullable();
    $table->timestamps();
});
$licence = new class implements SiteCapabilityLicence {
    /** @var list<string> */
    public array $paidHeld = [];

    public function allows(string $capability): bool
    {
        $paid = ['larena.capability.chat.base', 'larena.capability.workflow.base', 'larena.capability.automation.base'];

        return !in_array($capability, $paid, true) || in_array($capability, $this->paidHeld, true);
    }
};
$settings = new class implements SiteScenarioSettings {
    /** @var array<string, mixed> */
    public array $values = ['site.purpose.note' => 'set by the person'];

    public function available(): bool
    {
        return true;
    }

    public function putMissing(array $values, string $actor): array
    {
        $written = [];
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->values)) {
                $this->values[$key] = $value;
                $written[] = $key;
            }
        }

        return $written;
    }
};
$demo = new class implements SiteScenarioDemoContent {
    public int $imports = 0;

    public function available(): bool
    {
        return true;
    }

    public function import(SiteScenario $scenario, string $sitePackPath, string $actor): string
    {
        $this->imports++;

        return is_file($sitePackPath) ? self::IMPORTED : self::FAILED;
    }
};
$audit = new class implements SiteScenarioAudit {
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $records = [];

    public function record(string $operation, string $actor, array $payload): void
    {
        $this->records[] = [$operation, $actor, $payload];
    }
};
$packDirectory = sys_get_temp_dir() . '/larena-scenario-test-' . bin2hex(random_bytes(4));
mkdir($packDirectory . '/demo', 0700, true);
file_put_contents($packDirectory . '/demo/club.zip', 'pack');
$catalog->add($validator->validate(['scenario_id' => 'club', 'sitepack' => 'demo/club.zip', 'settings' => ['site.purpose' => 'club']] + $base, $packDirectory, 'larena/test'));
$state = new DatabaseSiteScenarioState($connection);
$applier = new SiteScenarioApplier($catalog, $state, $licence, $settings, $demo, $audit);

// «Пустой сайт» applies nothing: no row, no audit.
$empty = $applier->apply(SiteScenarioCatalog::EMPTY, 'user:1');
scenario_assert($empty->isEmpty() && !$empty->changed() && scenario_rows($connection) === 0 && $audit->records === [] && $settings->values === ['site.purpose.note' => 'set by the person'],
    '«Пустой сайт» writes nothing');
scenario_refused(static fn () => $applier->apply('shop_unknown', 'user:1'), 'scenario_unknown', 'an unknown scenario is refused');
scenario_refused(static fn () => $applier->apply('blog', ' '), 'scenario_actor_missing', 'an apply names who does it');

// The first scenario: its capabilities on, its starter setting written, audited once.
$first = $applier->apply('company_site', 'user:1');
scenario_assert($first->firstTime && $first->switchedOn === $catalog->get('company_site')->capabilities && $first->needsLicence === []
    && $first->settingsWritten === ['site.purpose'] && $settings->values['site.purpose'] === 'company_site', 'company_site applied');
scenario_assert(count($audit->records) === 1 && $audit->records[0][0] === 'applied' && $audit->records[0][1] === 'user:1'
    && !array_key_exists('values', $audit->records[0][2]), 'the apply is audited with keys, not values');
// Idempotent: the same scenario again changes nothing and is not audited again.
$snapshot = $state->read();
$again = $applier->apply('company_site', 'user:1');
scenario_assert(!$again->changed() && $again->switchedOn === [] && $again->alreadyOn === $catalog->get('company_site')->capabilities
    && $again->settingsWritten === [] && $again->settingsKept === ['site.purpose'] && $state->read() === $snapshot && count($audit->records) === 1, 'applying twice changes nothing');

// A second scenario merges: it adds its capabilities, keeps the first one's setting, switches nothing off.
$second = $applier->apply('blog', 'user:1');
scenario_assert($second->switchedOn === ['larena.capability.notification.base'] && $second->settingsKept === ['site.purpose']
    && $settings->values['site.purpose'] === 'company_site', 'blog adds notifications and keeps the purpose');
foreach ($catalog->get('company_site')->capabilities as $capability) {
    scenario_assert($state->on($capability), 'the first scenario\'s ' . $capability . ' stays on');
}
scenario_assert($state->applied() === ['blog', 'company_site'] && count($audit->records) === 2, 'both scenarios are recorded');

// A paid capability without a licence: reported, left off; once the licence allows it, applying again switches it on.
$portal = $applier->apply('staff_portal', 'user:1');
scenario_assert($portal->needsLicence === ['larena.capability.chat.base', 'larena.capability.workflow.base'] && !$state->on('larena.capability.chat.base')
    && !in_array('larena.capability.chat.base', $state->enabled(), true) && $portal->switchedOn === ['larena.capability.auth.base', 'larena.capability.access.base'],
    'chat and workflow need a licence and stay off');
scenario_assert($applier->needsLicence($catalog->get('staff_portal')) === $portal->needsLicence
    && $state->read()['scenarios']['staff_portal']['needs_licence'] === $portal->needsLicence, 'the wizard and the record say the same');
$licence->paidHeld = ['larena.capability.chat.base'];
$licensed = $applier->apply('staff_portal', 'user:1');
scenario_assert($licensed->switchedOn === ['larena.capability.chat.base'] && $licensed->needsLicence === ['larena.capability.workflow.base']
    && $state->on('larena.capability.chat.base') && $audit->records[count($audit->records) - 1][0] === 'reapplied', 'a licence switches the paid capability on');

// Demo content comes in once.
$club = $applier->apply('club', 'user:2');
$clubAgain = $applier->apply('club', 'user:2');
scenario_assert($club->demo === SiteScenarioDemoContent::IMPORTED && $clubAgain->demo === SiteScenarioOutcome::DEMO_KEPT && $demo->imports === 1, 'the demo SitePack is imported once');
// No importer composed: reported unavailable, never pretended.
$bare = new SiteScenarioApplier($catalog, $state, $licence, $settings, new Larena\Core\Scenario\UnavailableSiteScenarioDemoContent(), $audit);
scenario_assert($bare->apply('club', 'user:2')->demo === SiteScenarioOutcome::DEMO_KEPT, 'already imported stays kept');
// One row of the install state holds it all.
scenario_assert(scenario_rows($connection) === 1, 'one install state row');
// The trial seam answers honestly until the registration server has a trial API.
$trial = (new UnavailableSiteScenarioTrial())->request('staff_portal', ['larena.capability.chat.base'], 'user:1');
scenario_assert(!$trial->started() && $trial->status === 'trial_unavailable', 'the trial seam');

unlink($packDirectory . '/demo/club.zip');
rmdir($packDirectory . '/demo');
rmdir($packDirectory);

echo "SiteScenarioTest passed.\n";

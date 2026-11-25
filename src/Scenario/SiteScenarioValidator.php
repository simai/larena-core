<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

use Larena\Core\Exceptions\SiteScenarioRejected;
use Larena\Core\Support\SchemaName;

/**
 * Turns a scenario document into a SiteScenario, or refuses it. Offline: it reads no licence, because the wizard shows
 * every scenario and only applying one asks what the site may switch on.
 *
 * The key set is closed, as in a solution manifest: a key this version does not know would be half-understood.
 * Demo data enters through SitePack only (the solution manifest's seed rule): a key that would write rows is refused.
 */
final class SiteScenarioValidator
{
    public const SCHEMA = 'larena.site_scenario';
    public const SCHEMA_VERSION = 1;

    public const KEYS = ['schema', 'schema_version', 'scenario_id', 'title', 'description', 'capabilities', 'settings', 'sitepack', 'position'];

    public const REQUIRED_KEYS = ['schema', 'scenario_id', 'title', 'description', 'capabilities'];

    public const ID_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    /** A translation key with its package namespace: `larena-core::scenarios.blog.title`. */
    public const LANG_KEY_PATTERN = '/^[a-z][a-z0-9-]*::[a-z0-9_]+(\.[a-z0-9_]+)*$/';

    /** A capability id of the registry: `larena.capability.<package>.<name>`. */
    public const CAPABILITY_PATTERN = '/^larena\.capability\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    public const SETTING_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/';

    public const DEFAULT_POSITION = 100;

    public const MAX_SETTINGS = 100;

    private const RAW_SEED_KEYS = ['sql', 'raw_sql', 'statements', 'tables', 'table_writes', 'inserts', 'seed', 'records'];

    /**
     * @param array<mixed> $document
     * @param string $baseDirectory where a relative `sitepack` path is read from (the declaring package's folder)
     */
    public function validate(array $document, string $baseDirectory = '', string $source = ''): SiteScenario
    {
        foreach (array_keys($document) as $key) {
            $key = (string) $key;
            if (in_array($key, self::RAW_SEED_KEYS, true)) {
                throw new SiteScenarioRejected('raw_seed_forbidden', 'Demo data enters through SitePack; "' . $key . '" would write directly.');
            }
            if (!in_array($key, self::KEYS, true)) {
                throw new SiteScenarioRejected('unknown_scenario_key', 'Unknown key in a scenario: ' . $key);
            }
        }
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $document)) {
                throw new SiteScenarioRejected('missing_scenario_key', 'A scenario requires "' . $key . '".');
            }
        }
        if (!SchemaName::is($document, self::SCHEMA, self::SCHEMA_VERSION)) {
            throw new SiteScenarioRejected('schema_mismatch', 'A scenario must declare ' . self::SCHEMA . ' version ' . self::SCHEMA_VERSION . '.');
        }
        $id = $document['scenario_id'];
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new SiteScenarioRejected('invalid_scenario_id', 'Not a valid scenario id.');
        }
        if ($id === SiteScenarioCatalog::EMPTY) {
            // «Пустой сайт» is the wizard's own choice of nothing, never a record.
            throw new SiteScenarioRejected('reserved_scenario_id', 'The scenario id "' . $id . '" is reserved.');
        }

        return new SiteScenario(
            id: $id,
            titleKey: $this->langKey($document, 'title'),
            descriptionKey: $this->langKey($document, 'description'),
            capabilities: $this->capabilities($document),
            settings: $this->settings($document),
            sitePack: $this->sitePack($document, $baseDirectory),
            position: $this->position($document),
            source: $source,
        );
    }

    /** @param array<mixed> $document */
    private function langKey(array $document, string $key): string
    {
        $value = $document[$key];
        if (!is_string($value) || preg_match(self::LANG_KEY_PATTERN, $value) !== 1) {
            throw new SiteScenarioRejected('invalid_lang_key', '"' . $key . '" must be a translation key with its package namespace.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $document
     * @return list<string>
     */
    private function capabilities(array $document): array
    {
        $value = $document['capabilities'];
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new SiteScenarioRejected('invalid_capabilities', '"capabilities" must be a non-empty list.');
        }
        $capabilities = [];
        foreach ($value as $capability) {
            if (!is_string($capability) || preg_match(self::CAPABILITY_PATTERN, $capability) !== 1) {
                throw new SiteScenarioRejected('invalid_capability', 'Not a capability id: ' . (is_string($capability) ? $capability : gettype($capability)));
            }
            if (in_array($capability, $capabilities, true)) {
                throw new SiteScenarioRejected('duplicate_capability', 'A capability is named twice: ' . $capability);
            }
            $capabilities[] = $capability;
        }

        return $capabilities;
    }

    /**
     * @param array<mixed> $document
     * @return array<string, string|int|float|bool>
     */
    private function settings(array $document): array
    {
        $value = $document['settings'] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > self::MAX_SETTINGS) {
            throw new SiteScenarioRejected('invalid_settings', '"settings" must map at most ' . self::MAX_SETTINGS . ' setting keys to values.');
        }
        $settings = [];
        foreach ($value as $key => $setting) {
            if (!is_string($key) || preg_match(self::SETTING_PATTERN, $key) !== 1) {
                throw new SiteScenarioRejected('invalid_setting_key', 'Not a setting key: ' . (string) $key);
            }
            if (!is_string($setting) && !is_int($setting) && !is_float($setting) && !is_bool($setting)) {
                // A starter setting is one plain value; anything structured belongs in a settings SitePack.
                throw new SiteScenarioRejected('invalid_setting_value', 'A starter setting is a string, a number or a boolean: ' . $key);
            }
            $settings[$key] = $setting;
        }
        ksort($settings, SORT_STRING);

        return $settings;
    }

    /** @param array<mixed> $document */
    private function sitePack(array $document, string $baseDirectory): ?string
    {
        $value = $document['sitepack'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '' || str_starts_with($value, '/') || str_contains($value, '\\')
            || in_array('..', explode('/', $value), true) || preg_match('/\.(zip|sitepack)$/', $value) !== 1) {
            throw new SiteScenarioRejected('invalid_sitepack', '"sitepack" is a relative path to a .zip or .sitepack archive inside the package.');
        }
        $path = rtrim($baseDirectory, '/') . '/' . $value;
        if ($baseDirectory === '' || !is_file($path)) {
            throw new SiteScenarioRejected('sitepack_missing', 'The scenario\'s SitePack is not there: ' . $value);
        }

        return $path;
    }

    /** @param array<mixed> $document */
    private function position(array $document): int
    {
        $value = $document['position'] ?? self::DEFAULT_POSITION;
        if (!is_int($value) || $value < 0 || $value > 10000) {
            throw new SiteScenarioRejected('invalid_position', '"position" is a whole number from 0 to 10000.');
        }

        return $value;
    }
}

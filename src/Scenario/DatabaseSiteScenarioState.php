<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

use Closure;
use Illuminate\Database\Connection;
use Larena\Core\Contracts\SiteCapabilitySwitches;

/**
 * What the site's scenarios did, kept as one row of the install state (`larena_install_state`, key `site_scenarios`),
 * so no migration is needed: the capabilities switched on (each with the scenario that switched it on) and the
 * scenarios applied (when first and last, what still needs a licence, whether the demo content came in), and the
 * capabilities a person switched off by hand.
 *
 * Every change runs in a transaction that locks the row. A scenario only adds or updates, but «Возможности сайта»
 * (larena/admin, /admin/settings/capabilities) switches a function off too: its switch is removed here, while the
 * function's data (its tables and records) is never deleted.
 *
 * Switched off by hand (coordinator decision 2026-11-26): a capability that leaves `capabilities` in a change is
 * remembered in `switched_off` (capability => when), whoever's change it was, so reapplying a scenario or adding a
 * second one never switches it back on (SiteScenarioApplier skips it and reports it «выключено вручную»). A change
 * that switches it on again (the person, on that screen) forgets the mark.
 *
 * @phpstan-type State array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>, switched_off: array<string, string>}
 */
final class DatabaseSiteScenarioState implements SiteCapabilitySwitches
{
    public const TABLE = 'larena_install_state';

    public const STATE_KEY = 'site_scenarios';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>, switched_off: array<string, string>} */
    public function read(): array
    {
        if (!$this->connection->getSchemaBuilder()->hasTable(self::TABLE)) {
            return self::blank();
        }
        $row = $this->connection->table(self::TABLE)->where('state_key', self::STATE_KEY)->first();

        return $row === null ? self::blank() : self::decode($row->payload ?? null);
    }

    /**
     * Changes the state under a lock: `$change` gets the current state and returns the new one.
     *
     * @param Closure(array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>, switched_off: array<string, string>}): array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>, switched_off: array<string, string>} $change
     * @return array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>, switched_off: array<string, string>}
     */
    public function update(Closure $change): array
    {
        return $this->connection->transaction(function () use ($change): array {
            $table = $this->connection->table(self::TABLE);
            $row = $table->where('state_key', self::STATE_KEY)->lockForUpdate()->first();
            $current = $row === null ? self::blank() : self::decode($row->payload ?? null);
            $next = $change($current);
            foreach (array_diff_key($current['capabilities'], $next['capabilities']) as $capability => $scenario) {
                $next['switched_off'][$capability] ??= gmdate('Y-m-d\TH:i:s\Z');
            }
            foreach (array_keys($next['capabilities']) as $capability) {
                unset($next['switched_off'][$capability]);
            }
            ksort($next['capabilities'], SORT_STRING);
            ksort($next['scenarios'], SORT_STRING);
            ksort($next['switched_off'], SORT_STRING);
            if ($next === $current && $row !== null) {
                return $current;
            }
            $payload = json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $now = new \DateTimeImmutable();
            if ($row === null) {
                $this->connection->table(self::TABLE)->insert([
                    'state_key' => self::STATE_KEY, 'state_status' => 'applied', 'payload' => $payload, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $this->connection->table(self::TABLE)->where('state_key', self::STATE_KEY)->update(['payload' => $payload, 'updated_at' => $now]);
            }

            return $next;
        }, 3);
    }

    public function enabled(): array
    {
        $capabilities = array_keys($this->read()['capabilities']);
        sort($capabilities, SORT_STRING);

        return $capabilities;
    }

    public function on(string $capability): bool
    {
        return isset($this->read()['capabilities'][$capability]);
    }

    /** @return list<string> the capabilities a person switched off by hand, sorted: no scenario switches them back on */
    public function switchedOff(): array
    {
        return array_keys($this->read()['switched_off']);
    }

    /** @return list<string> the scenarios applied to the site */
    public function applied(): array
    {
        return array_keys($this->read()['scenarios']);
    }

    /** @return array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>, switched_off: array<string, string>} */
    private static function blank(): array
    {
        return ['capabilities' => [], 'scenarios' => [], 'switched_off' => []];
    }

    /** @return array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>, switched_off: array<string, string>} */
    private static function decode(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : null;
        if (!is_array($decoded)) {
            return self::blank();
        }
        $capabilities = [];
        foreach ((array) ($decoded['capabilities'] ?? []) as $capability => $scenario) {
            if (is_string($capability) && is_string($scenario)) {
                $capabilities[$capability] = $scenario;
            }
        }
        $scenarios = [];
        foreach ((array) ($decoded['scenarios'] ?? []) as $id => $record) {
            if (is_string($id) && is_array($record)) {
                $scenarios[$id] = $record;
            }
        }

        $off = [];
        foreach ((array) ($decoded['switched_off'] ?? []) as $capability => $at) {
            if (is_string($capability) && is_string($at) && !isset($capabilities[$capability])) {
                $off[$capability] = $at;
            }
        }

        return ['capabilities' => $capabilities, 'scenarios' => $scenarios, 'switched_off' => $off];
    }
}

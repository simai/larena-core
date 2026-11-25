<?php

declare(strict_types=1);

namespace Larena\Core\Scenario;

use Closure;
use Illuminate\Database\Connection;
use Larena\Core\Contracts\SiteCapabilitySwitches;

/**
 * What the site's scenarios did, kept as one row of the install state (`larena_install_state`, key `site_scenarios`),
 * so no migration is needed: the capabilities switched on (each with the scenario that switched it on) and the
 * scenarios applied (when first and last, what still needs a licence, whether the demo content came in).
 *
 * Every change runs in a transaction that locks the row; nothing here is ever removed, only added or updated.
 *
 * @phpstan-type State array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>}
 */
final class DatabaseSiteScenarioState implements SiteCapabilitySwitches
{
    public const TABLE = 'larena_install_state';

    public const STATE_KEY = 'site_scenarios';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>} */
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
     * @param Closure(array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>}): array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>} $change
     * @return array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>}
     */
    public function update(Closure $change): array
    {
        return $this->connection->transaction(function () use ($change): array {
            $table = $this->connection->table(self::TABLE);
            $row = $table->where('state_key', self::STATE_KEY)->lockForUpdate()->first();
            $current = $row === null ? self::blank() : self::decode($row->payload ?? null);
            $next = $change($current);
            ksort($next['capabilities'], SORT_STRING);
            ksort($next['scenarios'], SORT_STRING);
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

    /** @return list<string> the scenarios applied to the site */
    public function applied(): array
    {
        return array_keys($this->read()['scenarios']);
    }

    /** @return array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>} */
    private static function blank(): array
    {
        return ['capabilities' => [], 'scenarios' => []];
    }

    /** @return array{capabilities: array<string, string>, scenarios: array<string, array<string, mixed>>} */
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

        return ['capabilities' => $capabilities, 'scenarios' => $scenarios];
    }
}

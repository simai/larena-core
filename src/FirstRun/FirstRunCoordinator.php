<?php

declare(strict_types=1);

namespace Larena\Core\FirstRun;

use Illuminate\Database\Connection;
use Larena\Core\Contracts\FirstRunContributor;

final readonly class FirstRunCoordinator
{
    private const STATE_KEY = 'first_run_site';
    private const REQUIRED_CONTRIBUTORS = ['auth', 'setting', 'content'];

    /** @var list<FirstRunContributor> */
    private array $contributors;

    /** @param iterable<FirstRunContributor> $contributors */
    public function __construct(private Connection $connection, iterable $contributors)
    {
        $resolved = is_array($contributors) ? $contributors : iterator_to_array($contributors, false);
        usort($resolved, static fn (FirstRunContributor $left, FirstRunContributor $right): int => $left->priority() <=> $right->priority());

        $ids = array_map(static fn (FirstRunContributor $contributor): string => $contributor->id(), $resolved);
        if ($ids !== self::REQUIRED_CONTRIBUTORS || count($ids) !== count(array_unique($ids))) {
            throw new \LogicException('First-run composition requires the exact auth, setting and content contributors.');
        }

        $this->contributors = $resolved;
    }

    public function availability(): FirstRunAvailability
    {
        if (!$this->connection->getSchemaBuilder()->hasTable('larena_install_state')) {
            return new FirstRunAvailability(FirstRunAvailability::SCHEMA_MISSING, 'first_run_schema_missing');
        }

        $marker = $this->connection->table('larena_install_state')->where('state_key', self::STATE_KEY)->first();
        if ($marker !== null) {
            return new FirstRunAvailability(
                (string) $marker->state_status === 'completed' ? FirstRunAvailability::COMPLETED : FirstRunAvailability::INCOMPATIBLE_PARTIAL,
                (string) $marker->state_status === 'completed' ? 'first_run_completed' : 'first_run_marker_incomplete',
            );
        }

        $states = [];
        foreach ($this->contributors as $contributor) {
            $state = $contributor->state();
            if (!in_array($state, [FirstRunContributor::STATE_EMPTY, FirstRunContributor::STATE_INITIALIZED, FirstRunContributor::STATE_PARTIAL], true)) {
                throw new \LogicException('First-run contributor returned an invalid state.');
            }
            $states[$contributor->id()] = $state;
        }

        if ($states['auth'] === FirstRunContributor::STATE_INITIALIZED) {
            return new FirstRunAvailability(FirstRunAvailability::EXISTING_INSTALL, 'first_run_existing_administrator');
        }
        if (array_filter($states, static fn (string $state): bool => $state !== FirstRunContributor::STATE_EMPTY) !== []) {
            return new FirstRunAvailability(FirstRunAvailability::INCOMPATIBLE_PARTIAL, 'first_run_partial_owner_state');
        }

        return new FirstRunAvailability(FirstRunAvailability::AVAILABLE, 'first_run_available');
    }

    public function bootstrap(FirstRunPayload $payload): FirstRunContext
    {
        $errors = [];
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->validate($payload) as $field => $message) {
                $errors[$field] ??= $message;
            }
        }
        if ($errors !== []) {
            throw new FirstRunValidationFailed($errors);
        }

        return $this->connection->transaction(function () use ($payload): FirstRunContext {
            if (!$this->availability()->isAvailable()) {
                throw new \DomainException('first_run_closed');
            }

            $attempt = bin2hex(random_bytes(16));
            $now = new \DateTimeImmutable();
            $this->connection->table('larena_install_state')->insert([
                'state_key' => self::STATE_KEY,
                'state_status' => 'initializing',
                'launch_record_id' => $attempt,
                'evidence_path' => null,
                'payload' => json_encode(['contributors' => self::REQUIRED_CONTRIBUTORS], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $context = new FirstRunContext();
            foreach ($this->contributors as $contributor) {
                $context = $contributor->apply($payload, $context);
            }

            $updated = $this->connection->table('larena_install_state')
                ->where('state_key', self::STATE_KEY)
                ->where('state_status', 'initializing')
                ->where('launch_record_id', $attempt)
                ->update(['state_status' => 'completed', 'updated_at' => new \DateTimeImmutable()]);
            if ($updated !== 1) {
                throw new \RuntimeException('first_run_marker_completion_failed');
            }

            return $context;
        }, 3);
    }
}

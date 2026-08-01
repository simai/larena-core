<?php

declare(strict_types=1);

namespace Larena\Core\WebInstall;

use Closure;
use Throwable;

final readonly class WebInstallCoordinator
{
    private const CAPABILITY_TTL = 1800;

    /** @param null|Closure(string,array<string,mixed>):void $checkpointHook */
    public function __construct(
        private WebInstallDatabaseLifecycle $databaseLifecycle,
        private WebInstallStateStore $state,
        private string $signingKey,
        private ?Closure $checkpointHook = null,
    ) {
        if ($signingKey === '') {
            throw new WebInstallException('web_install_signing_key_missing');
        }
    }

    /** @return array{status:string,reason:string} */
    public function availability(): array
    {
        return $this->state->withLock(fn (): array => $this->reconcile());
    }

    public function claim(string $sessionId, string $capability): void
    {
        if ($sessionId === '' || strlen($capability) < 32) {
            throw new WebInstallException('web_install_capability_missing');
        }
        $this->state->withLock(function () use ($sessionId, $capability): void {
            $availability = $this->reconcile();
            if ($availability['status'] === 'closed') {
                throw new WebInstallException('web_install_closed');
            }
            if ($availability['status'] === 'blocked') {
                throw new WebInstallException($availability['reason']);
            }
            $now = time();
            $state = $this->state->read();
            if ($state === null) {
                $this->state->write($this->readyState($sessionId, $capability, $now));
                return;
            }
            if (($state['status'] ?? null) === 'ready' && (int) ($state['expires_at'] ?? 0) < $now) {
                if (hash_equals((string) ($state['session_hash'] ?? ''), hash('sha256', $sessionId))
                    && hash_equals((string) ($state['capability_hash'] ?? ''), hash('sha256', $capability))) {
                    throw new WebInstallException('web_install_capability_expired');
                }
                $this->state->write($this->readyState($sessionId, $capability, $now));
                return;
            }
            $this->assertCapability($state, $sessionId, $capability, $now);
        });
    }

    public function preflight(string $sessionId, string $capability, WebInstallDatabaseConfiguration $database): WebInstallPreflightReport
    {
        $this->assertCapability($this->requiredState(), $sessionId, $capability, time());
        return $this->databaseLifecycle->inspect($database);
    }

    /** @return array{status:string,checkpoint:string,migration_count:int,operation_id:string} */
    public function apply(string $sessionId, string $capability, WebInstallDatabaseConfiguration $database): array
    {
        return $this->state->withLock(function () use ($sessionId, $capability, $database): array {
            $availability = $this->reconcile();
            if ($availability['status'] !== 'available') {
                throw new WebInstallException($availability['reason']);
            }
            $state = $this->requiredState();
            $this->assertCapability($state, $sessionId, $capability, time());
            if (($state['status'] ?? null) !== 'ready') {
                throw new WebInstallException('web_install_wrong_state');
            }
            if (!$this->databaseLifecycle->inspect($database)->passed()) {
                throw new WebInstallException('web_install_preflight_failed');
            }

            $operationId = bin2hex(random_bytes(16));
            $databaseFingerprint = $this->databaseFingerprint($database);
            $this->state->writeCandidate($database->toPrivateArray());
            $this->state->write([
                ...$state,
                'status' => 'applying',
                'checkpoint' => 'configuration_staged',
                'operation_id' => $operationId,
                'database_fingerprint' => $databaseFingerprint,
                'updated_at' => time(),
            ]);

            $completed = false;
            try {
                $prepared = $this->databaseLifecycle->prepare($database, $operationId);
                $pending = [
                    ...$state,
                    'status' => 'applying',
                    'checkpoint' => 'activation_pending',
                    'operation_id' => $operationId,
                    'database_fingerprint' => $databaseFingerprint,
                    'migration_count' => $prepared['migration_count'],
                    'migration_ledger_sha256' => $prepared['migration_ledger_sha256'],
                    'updated_at' => time(),
                ];
                $this->state->write($pending);
                $this->checkpoint('before_configuration_activation', $pending);
                $this->state->activateCandidate();
                $this->checkpoint('after_configuration_activation', $pending);
                $this->checkpoint('before_completed_state_persistence', $pending);
                $completedState = [
                    'status' => 'completed',
                    'checkpoint' => 'completed',
                    'operation_id' => $operationId,
                    'database_fingerprint' => $databaseFingerprint,
                    'migration_count' => $prepared['migration_count'],
                    'migration_ledger_sha256' => $prepared['migration_ledger_sha256'],
                    'completed_at' => time(),
                ];
                $this->state->write($completedState);
                $completed = true;
                $this->checkpoint('after_completed_state_persistence', $completedState);

                return $this->completedResult($completedState);
            } catch (Throwable $exception) {
                if ($completed) {
                    return $this->completedResult($this->requiredState());
                }
                $this->rollbackToReady($state, $database, 'failed_apply_rolled_back', hash('sha256', $operationId));
                throw $exception instanceof WebInstallException
                    ? $exception
                    : new WebInstallException('web_install_apply_failed');
            }
        });
    }

    /** @return array{status:string,reason:string} */
    private function reconcile(): array
    {
        $state = $this->state->read();
        $candidateExists = $this->state->candidateExists();
        $configurationExists = $this->state->configurationExists();

        if ($state === null) {
            return ($candidateExists || $configurationExists)
                ? ['status' => 'blocked', 'reason' => 'web_install_recovery_required']
                : ['status' => 'available', 'reason' => 'web_install_available'];
        }
        if (($state['status'] ?? null) === 'recovery_required') {
            return ['status' => 'blocked', 'reason' => 'web_install_recovery_required'];
        }
        if (($state['status'] ?? null) === 'completed') {
            if (!$configurationExists || $candidateExists || !$this->preparedStateIsValid($state, $this->state->readConfiguration())) {
                $this->markRecoveryRequired($state, 'completed_state_verification_failed');
                return ['status' => 'blocked', 'reason' => 'web_install_recovery_required'];
            }
            return ['status' => 'closed', 'reason' => 'web_install_completed'];
        }
        if (($state['status'] ?? null) !== 'applying') {
            return ($candidateExists || $configurationExists)
                ? ['status' => 'blocked', 'reason' => 'web_install_configuration_without_completion']
                : ['status' => 'available', 'reason' => 'web_install_available'];
        }
        if ($candidateExists === $configurationExists) {
            $this->markRecoveryRequired($state, 'interrupted_configuration_ambiguous');
            return ['status' => 'blocked', 'reason' => 'web_install_recovery_required'];
        }

        $configuration = $candidateExists ? $this->state->readCandidate() : $this->state->readConfiguration();
        $database = $this->databaseFromArray($configuration);
        if ($this->preparedStateIsValid($state, $configuration)) {
            if ($candidateExists) {
                $this->state->activateCandidate();
            }
            $completed = [
                'status' => 'completed',
                'checkpoint' => 'completed',
                'operation_id' => (string) $state['operation_id'],
                'database_fingerprint' => (string) $state['database_fingerprint'],
                'migration_count' => (int) ($state['migration_count'] ?? 0),
                'migration_ledger_sha256' => (string) ($state['migration_ledger_sha256'] ?? ''),
                'completed_at' => time(),
                'recovered_from_checkpoint' => (string) ($state['checkpoint'] ?? 'unknown'),
            ];
            $this->state->write($completed);
            return ['status' => 'closed', 'reason' => 'web_install_completed'];
        }

        $this->rollbackToReady($state, $database, 'interrupted_apply_rolled_back', hash('sha256', (string) ($state['operation_id'] ?? '')));
        return ['status' => 'available', 'reason' => 'web_install_interrupted_apply_rolled_back'];
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $configuration */
    private function preparedStateIsValid(array $state, array $configuration): bool
    {
        $operationId = (string) ($state['operation_id'] ?? '');
        if ($operationId === '' || !hash_equals((string) ($state['database_fingerprint'] ?? ''), $this->databaseFingerprint($this->databaseFromArray($configuration)))) {
            return false;
        }
        return $this->databaseLifecycle->isPrepared(
            $this->databaseFromArray($configuration),
            $operationId,
            ($state['migration_ledger_sha256'] ?? null) !== null ? (string) $state['migration_ledger_sha256'] : null,
        );
    }

    /** @param array<string,mixed> $priorState */
    private function rollbackToReady(array $priorState, WebInstallDatabaseConfiguration $database, string $checkpoint, string $operationSha256): void
    {
        try {
            $this->databaseLifecycle->rollback($database);
            $this->state->discardCandidate();
            $this->state->discardConfiguration();
            $this->state->write([
                ...$priorState,
                'status' => 'ready',
                'checkpoint' => $checkpoint,
                'last_operation_sha256' => $operationSha256,
                'updated_at' => time(),
            ]);
        } catch (Throwable) {
            $this->markRecoveryRequired($priorState, $checkpoint.'_failed');
            throw new WebInstallException('web_install_recovery_required');
        }
    }

    /** @param array<string,mixed> $state */
    private function markRecoveryRequired(array $state, string $checkpoint): void
    {
        $this->state->write([...$state, 'status' => 'recovery_required', 'checkpoint' => $checkpoint, 'updated_at' => time()]);
    }

    /** @param array<string,mixed> $state */
    private function checkpoint(string $name, array $state): void
    {
        if ($this->checkpointHook !== null) {
            ($this->checkpointHook)($name, $state);
        }
    }

    /** @param array<string,mixed> $state @return array{status:string,checkpoint:string,migration_count:int,operation_id:string} */
    private function completedResult(array $state): array
    {
        return [
            'status' => 'completed',
            'checkpoint' => 'completed',
            'migration_count' => (int) ($state['migration_count'] ?? 0),
            'operation_id' => (string) ($state['operation_id'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $configuration */
    private function databaseFromArray(array $configuration): WebInstallDatabaseConfiguration
    {
        return new WebInstallDatabaseConfiguration(
            (string) ($configuration['host'] ?? ''),
            (int) ($configuration['port'] ?? 0),
            (string) ($configuration['database'] ?? ''),
            (string) ($configuration['username'] ?? ''),
            (string) ($configuration['password'] ?? ''),
        );
    }

    /** @return array<string,mixed> */
    private function requiredState(): array
    {
        return $this->state->read() ?? throw new WebInstallException('web_install_capability_missing');
    }

    /** @param array<string,mixed> $state */
    private function assertCapability(array $state, string $sessionId, string $capability, int $now): void
    {
        if (($state['status'] ?? null) === 'completed') {
            throw new WebInstallException('web_install_closed');
        }
        if ((int) ($state['expires_at'] ?? 0) < $now) {
            throw new WebInstallException('web_install_capability_expired');
        }
        if (!hash_equals((string) ($state['session_hash'] ?? ''), hash('sha256', $sessionId))
            || !hash_equals((string) ($state['capability_hash'] ?? ''), hash('sha256', $capability))) {
            throw new WebInstallException('web_install_capability_invalid');
        }
    }

    /** @return array<string,mixed> */
    private function readyState(string $sessionId, string $capability, int $now): array
    {
        return [
            'status' => 'ready', 'checkpoint' => 'capability_claimed',
            'session_hash' => hash('sha256', $sessionId),
            'capability_hash' => hash('sha256', $capability),
            'expires_at' => $now + self::CAPABILITY_TTL,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function databaseFingerprint(WebInstallDatabaseConfiguration $database): string
    {
        return hash_hmac('sha256', $database->database.'@'.$database->host.':'.$database->port, $this->signingKey);
    }
}

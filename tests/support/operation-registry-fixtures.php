<?php

declare(strict_types=1);

/**
 * Resolves a Composer autoloader that can see both larena/core and the YAML
 * parser the declaration loader needs. The package vendor tree does not install
 * symfony/yaml — composer cannot resolve this workspace's dev path repositories
 * — so the workspace and the entry application are tried first, the same way
 * the access package resolves larena/core for its own tests.
 */
(static function (): void {
    if (class_exists(\Symfony\Component\Yaml\Yaml::class, true)
        && class_exists(\Larena\Core\Registry\OperationDeclarationLoader::class, true)) {
        return;
    }

    $packageRoot = dirname(__DIR__, 2);
    $entryAppRoot = getenv('LARENA_ENTRY_APP_ROOT');
    $candidates = [
        dirname($packageRoot, 3) . '/larena/vendor/autoload.php',
        dirname($packageRoot, 2) . '/app/vendor/autoload.php',
        dirname($packageRoot, 2) . '/vendor/autoload.php',
        $packageRoot . '/vendor/autoload.php',
    ];
    if (is_string($entryAppRoot) && $entryAppRoot !== '') {
        array_unshift($candidates, rtrim($entryAppRoot, '/') . '/vendor/autoload.php');
    }

    foreach (array_unique($candidates) as $autoload) {
        if (!is_file($autoload)) {
            continue;
        }

        require_once $autoload;
        if (class_exists(\Symfony\Component\Yaml\Yaml::class, true)
            && class_exists(\Larena\Core\Registry\OperationDeclarationLoader::class, true)) {
            return;
        }
    }

    fwrite(STDERR, "Operation registry tests need an autoloader that sees larena/core and symfony/yaml.\n");
    fwrite(STDERR, "Run composer install in the entry application, or set LARENA_ENTRY_APP_ROOT.\n");
    exit(1);
})();

use Larena\Core\Contracts\ConfirmationPolicy;
use Larena\Core\Contracts\OperationAccessGate;
use Larena\Core\Contracts\OperationAuditRecorder;
use Larena\Core\Contracts\OperationCapabilityGate;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDecision;
use Larena\Core\Contracts\OperationDeclaration;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Enums\TransportKind;
use Larena\Core\Runtime\SyncOperationRuntime;

/**
 * Test doubles for the operation registry, proposal path and transport.
 *
 * Nothing here touches a database: the registry is composed in memory and the
 * proposal path is stateless by design, which is exactly what makes these tests
 * cheap enough to run on every commit.
 */

/**
 * @param array<string, mixed> $overrides
 */
function larena_test_declaration(array $overrides = []): OperationDeclaration
{
    $defaults = [
        'package' => 'larena/core',
        'name' => 'test.thing.change',
        'executionMode' => OperationExecutionMode::Sync,
        'riskClass' => OperationRiskClass::Change,
        'reversible' => true,
        'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        'outputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        'receiptSchema' => ['type' => 'object', 'properties' => ['change' => ['type' => 'string']]],
        'accessScope' => 'test.thing.manage',
        'auditEvent' => 'test.thing.changed',
        'idempotencyKey' => null,
        'transactional' => false,
        'transports' => [TransportKind::Local],
        'allowedExecutionModes' => null,
    ];

    $values = array_merge($defaults, $overrides);

    return new OperationDeclaration(
        package: $values['package'],
        name: $values['name'],
        executionMode: $values['executionMode'],
        riskClass: $values['riskClass'],
        reversible: $values['reversible'],
        inputSchema: $values['inputSchema'],
        outputSchema: $values['outputSchema'],
        receiptSchema: $values['receiptSchema'],
        accessScope: $values['accessScope'],
        auditEvent: $values['auditEvent'],
        idempotencyKey: $values['idempotencyKey'],
        transactional: $values['transactional'],
        transports: $values['transports'],
        allowedExecutionModes: $values['allowedExecutionModes'],
    );
}

function larena_test_descriptor_for(OperationDeclaration $declaration, array $overrides = []): OperationDescriptor
{
    $defaults = [
        'name' => $declaration->name,
        'executionMode' => $declaration->executionMode,
        'accessScope' => $declaration->accessScope,
        'auditEvent' => $declaration->auditEvent,
        'idempotencyKey' => $declaration->idempotencyKey,
        'transactional' => $declaration->transactional,
        'riskClass' => $declaration->riskClass,
        'reversible' => $declaration->reversible,
    ];

    $values = array_merge($defaults, $overrides);

    return new OperationDescriptor(
        name: $values['name'],
        executionMode: $values['executionMode'],
        accessScope: $values['accessScope'],
        auditEvent: $values['auditEvent'],
        idempotencyKey: $values['idempotencyKey'],
        transactional: $values['transactional'],
        riskClass: $values['riskClass'],
        reversible: $values['reversible'],
    );
}

final class LarenaTestAllowGate implements OperationAccessGate, OperationCapabilityGate
{
    public function __construct(private readonly bool $allow = true)
    {
    }

    public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        return $this->allow
            ? OperationDecision::allowed(OperationExecutionMode::Sync, 'test_access_allowed')
            : OperationDecision::denied('test_access_denied');
    }

    public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        return $this->allow
            ? OperationDecision::allowed(OperationExecutionMode::Sync, 'test_capability_allowed')
            : OperationDecision::capabilityLocked('test_capability_locked');
    }
}

final class LarenaTestAuditRecorder implements OperationAuditRecorder
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function recordDecision(
        OperationDescriptor $descriptor,
        OperationContext $context,
        OperationDecision $decision,
        string $phase,
    ): array {
        $event = ['phase' => $phase, 'operation' => $descriptor->name, 'reason' => $decision->reasonCode];
        $this->events[] = $event;

        return $event;
    }

    public function recordResult(
        OperationDescriptor $descriptor,
        OperationContext $context,
        OperationResult $result,
        string $phase,
    ): array {
        $event = ['phase' => $phase, 'operation' => $descriptor->name];
        $this->events[] = $event;

        return $event;
    }
}

/**
 * A handler that records what it was asked to do, and can describe a change
 * without making it.
 */
final class LarenaTestProposalHandler implements OperationProposalHandler
{
    /** @var list<string> */
    public array $writes = [];

    /** @var list<string> */
    public array $proposals = [];

    /** @phpstan-impure */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $this->writes[] = $descriptor->name;

        return ['written' => true];
    }

    /** @phpstan-impure */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $this->proposals[] = $descriptor->name;

        return ['change' => 'would change ' . ($context->metadata['id'] ?? 'nothing')];
    }

    /**
     * Counters rather than public array reads. A static analyser cannot see that
     * a call through the handler interface mutates this object, so reading the
     * arrays directly makes it "prove" that every assertion about them is
     * constant. An impure accessor states the truth instead.
     *
     * @phpstan-impure
     */
    public function writeCount(): int
    {
        return count($this->writes);
    }

    /** @phpstan-impure */
    public function proposalCount(): int
    {
        return count($this->proposals);
    }

    /** @phpstan-impure */
    public function wrote(string $operation): bool
    {
        return in_array($operation, $this->writes, true);
    }

    /** @phpstan-impure */
    public function proposed(string $operation): bool
    {
        return in_array($operation, $this->proposals, true);
    }

    public function forget(): void
    {
        $this->writes = [];
        $this->proposals = [];
    }
}

/** A handler with no proposal support at all. */
final class LarenaTestWriteOnlyHandler implements OperationHandler
{
    /** @var list<string> */
    public array $writes = [];

    /** @phpstan-impure */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $this->writes[] = $descriptor->name;

        return ['written' => true];
    }

    /** @phpstan-impure */
    public function writeCount(): int
    {
        return count($this->writes);
    }
}

function larena_test_runtime(OperationHandler $handler, bool $allow = true): SyncOperationRuntime
{
    $gate = new LarenaTestAllowGate($allow);

    return new SyncOperationRuntime($gate, $gate, new LarenaTestAuditRecorder(), $handler);
}

function larena_test_context(string $id = 'thing-1', array $metadata = []): OperationContext
{
    return new OperationContext(
        actorId: 'actor-1',
        correlationId: 'correlation-1',
        metadata: array_merge(['id' => $id], $metadata),
    );
}

final class LarenaTestConfirmAlways implements ConfirmationPolicy
{
    public function requiresConfirmation(OperationDeclaration $declaration): bool
    {
        return true;
    }
}

<?php

declare(strict_types=1);

use Larena\Core\Contracts\OperationDecision;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Enums\OperationDecisionStatus;
use Larena\Core\Enums\OperationExecutionMode;

require_once __DIR__ . '/../../src/Enums/OperationExecutionMode.php';
require_once __DIR__ . '/../../src/Enums/OperationDecisionStatus.php';
require_once __DIR__ . '/../../src/Contracts/OperationDecision.php';
require_once __DIR__ . '/../../src/Contracts/OperationDescriptor.php';
require_once __DIR__ . '/../../src/Contracts/OperationResult.php';

function assertFailClosedTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assertFailClosedTrue(OperationExecutionMode::tryFrom('unsafe') === null, 'Unknown execution mode must not map to an executable enum case.');

$invalid = OperationDecision::invalid('unknown_mode', 'Unknown operation mode.');
$invalidResult = OperationResult::fromDecision($invalid);

assertFailClosedTrue($invalid->status === OperationDecisionStatus::Invalid, 'Unknown mode must produce invalid decision.');
assertFailClosedTrue($invalid->handlerMayRun === false, 'Invalid decision must not allow handler execution.');
assertFailClosedTrue($invalidResult->successful() === false, 'Invalid result must not be successful.');
assertFailClosedTrue($invalidResult->normalizedError['code'] === 'unknown_mode', 'Invalid result must expose normalized error code.');

$denied = OperationDecision::denied('access_denied');
$deniedResult = OperationResult::fromDecision($denied);

assertFailClosedTrue($denied->handlerMayRun === false, 'Denied decision must not allow handler execution.');
assertFailClosedTrue($deniedResult->normalizedError['code'] === 'access_denied', 'Denied result must expose normalized error.');

try {
    OperationDecision::allowed(OperationExecutionMode::Denied);
    throw new RuntimeException('Denied execution mode unexpectedly produced an allowed decision.');
} catch (InvalidArgumentException) {
    // Expected fail-closed contract behavior.
}

try {
    new OperationDecision(
        OperationDecisionStatus::Allowed,
        OperationExecutionMode::Denied,
        'invalid_allowed_mode',
        'Invalid allowed decision.',
        true,
    );
    throw new RuntimeException('Allowed decision accepted denied execution mode.');
} catch (InvalidArgumentException) {
    // Expected fail-closed contract behavior.
}

try {
    new OperationDecision(
        OperationDecisionStatus::Denied,
        OperationExecutionMode::Denied,
        'invalid_handler_flag',
        'Invalid denied decision.',
        true,
    );
    throw new RuntimeException('Denied decision accepted handler execution.');
} catch (InvalidArgumentException) {
    // Expected fail-closed contract behavior.
}

try {
    new OperationDescriptor('', OperationExecutionMode::Sync);
    throw new RuntimeException('Empty operation descriptor name was accepted.');
} catch (InvalidArgumentException) {
    // Expected fail-closed contract behavior.
}

echo "OperationRuntimeFailsClosedTest passed.\n";

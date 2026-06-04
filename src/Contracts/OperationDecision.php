<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use InvalidArgumentException;
use Larena\Core\Enums\OperationDecisionStatus;
use Larena\Core\Enums\OperationExecutionMode;

final readonly class OperationDecision
{
    public function __construct(
        public OperationDecisionStatus $status,
        public OperationExecutionMode $executionMode,
        public string $reasonCode,
        public string $message,
        public bool $handlerMayRun,
    ) {
        if (trim($this->reasonCode) === '') {
            throw new InvalidArgumentException('Operation decision reason code must not be empty.');
        }

        if ($this->status === OperationDecisionStatus::Allowed && !$this->executionMode->isExecutable()) {
            throw new InvalidArgumentException('Allowed operation decision requires an executable mode.');
        }

        if ($this->status === OperationDecisionStatus::Allowed && !$this->handlerMayRun) {
            throw new InvalidArgumentException('Allowed operation decision must allow handler execution.');
        }

        if ($this->status !== OperationDecisionStatus::Allowed && $this->handlerMayRun) {
            throw new InvalidArgumentException('Non-allowed operation decision must not allow handler execution.');
        }

        if ($this->status !== OperationDecisionStatus::Allowed && $this->executionMode !== OperationExecutionMode::Denied) {
            throw new InvalidArgumentException('Non-allowed operation decision must use denied execution mode.');
        }
    }

    public static function allowed(OperationExecutionMode $executionMode, string $reasonCode = 'allowed'): self
    {
        if (!$executionMode->isExecutable()) {
            throw new InvalidArgumentException('Denied execution mode cannot produce an allowed decision.');
        }

        return new self(
            OperationDecisionStatus::Allowed,
            $executionMode,
            $reasonCode,
            'Operation is allowed by the contract boundary.',
            true,
        );
    }

    public static function denied(string $reasonCode, string $message = 'Operation is denied.'): self
    {
        return new self(
            OperationDecisionStatus::Denied,
            OperationExecutionMode::Denied,
            $reasonCode,
            $message,
            false,
        );
    }

    public static function capabilityLocked(string $reasonCode, string $message = 'Operation capability is locked.'): self
    {
        return new self(
            OperationDecisionStatus::CapabilityLocked,
            OperationExecutionMode::Denied,
            $reasonCode,
            $message,
            false,
        );
    }

    public static function invalid(string $reasonCode, string $message = 'Operation is invalid or unsafe.'): self
    {
        return new self(
            OperationDecisionStatus::Invalid,
            OperationExecutionMode::Denied,
            $reasonCode,
            $message,
            false,
        );
    }
}

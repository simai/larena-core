<?php

declare(strict_types=1);

namespace Larena\Core\FirstRun;

final class FirstRunValidationFailed extends \DomainException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('first_run_validation_failed');
    }
}

<?php

declare(strict_types=1);

namespace Larena\Core\FirstRun;

final readonly class FirstRunPreflightReport
{
    /** @param list<array{id: string, passed: bool, message: string}> $checks */
    public function __construct(public array $checks)
    {
    }

    public function passed(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check['passed']) {
                return false;
            }
        }

        return true;
    }
}

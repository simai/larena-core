<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\FirstRun\FirstRunContext;
use Larena\Core\FirstRun\FirstRunPayload;

interface FirstRunContributor
{
    public const STATE_EMPTY = 'empty';
    public const STATE_INITIALIZED = 'initialized';
    public const STATE_PARTIAL = 'partial';

    public function id(): string;

    public function priority(): int;

    /** @return array<string, string> */
    public function validate(FirstRunPayload $payload): array;

    public function state(): string;

    public function apply(FirstRunPayload $payload, FirstRunContext $context): FirstRunContext;
}

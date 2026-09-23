<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

enum TransportKind: string
{
    case Local = 'local';
    case Network = 'network';
}

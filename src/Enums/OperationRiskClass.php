<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

/**
 * Risk class of a governed operation. The confirmation policy that consumes it
 * is introduced with the operation registry; this enum only records the class.
 */
enum OperationRiskClass: string
{
    case Read = 'read';
    case Change = 'change';
    case Bulk = 'bulk';
    case Irreversible = 'irreversible';
    case External = 'external';

    public function isRead(): bool
    {
        return $this === self::Read;
    }

    public function alwaysRequiresConfirmation(): bool
    {
        return $this === self::Bulk || $this === self::Irreversible || $this === self::External;
    }
}

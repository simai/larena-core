<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use InvalidArgumentException;

/**
 * An approval of one previously proposed change.
 *
 * It carries no payload of its own: the digest is recomputed from the input the
 * execution is actually given, so an approval cannot be replayed against a
 * different change. Nothing is persisted, which is why the path survives a
 * restart without a table.
 */
final readonly class OperationApproval
{
    public const DIGEST_PATTERN = '/^sha256:[0-9a-f]{64}$/';

    public function __construct(
        public string $operation,
        public string $proposalDigest,
        public string $approvedBy,
    ) {
        if (trim($this->operation) === '') {
            throw new InvalidArgumentException('Operation approval must name an operation.');
        }

        if (preg_match(self::DIGEST_PATTERN, $this->proposalDigest) !== 1) {
            throw new InvalidArgumentException('Operation approval digest must be a sha256 digest.');
        }

        if (trim($this->approvedBy) === '') {
            throw new InvalidArgumentException('Operation approval must name an approver.');
        }
    }
}

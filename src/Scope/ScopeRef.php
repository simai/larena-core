<?php

declare(strict_types=1);

namespace Larena\Core\Scope;

use InvalidArgumentException;
use Larena\Core\Enums\ScopeKind;

/**
 * Stable reference to a core scope: "<kind>:<identifier>".
 *
 * The grammar is frozen by the Minimal CMS v1.1 Batch 1 pre-codegen freeze and
 * is the binding every package uses for records, settings, files, grants and
 * entitlements.
 */
final readonly class ScopeRef
{
    public const IDENTIFIER_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/';
    public const MAX_LENGTH = 80;

    private function __construct(
        public ScopeKind $kind,
        public string $identifier,
    ) {
    }

    public static function of(ScopeKind $kind, string $identifier): self
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Scope identifier "%s" must match %s.',
                $identifier,
                self::IDENTIFIER_PATTERN,
            ));
        }

        $reference = $kind->value . ':' . $identifier;
        if (strlen($reference) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Scope reference "%s" exceeds %d characters.',
                $reference,
                self::MAX_LENGTH,
            ));
        }

        return new self($kind, $identifier);
    }

    public static function parse(string $reference): self
    {
        $position = strpos($reference, ':');
        if ($position === false) {
            throw new InvalidArgumentException(sprintf('Scope reference "%s" must be "<kind>:<identifier>".', $reference));
        }

        $kind = ScopeKind::tryFrom(substr($reference, 0, $position));
        if ($kind === null) {
            throw new InvalidArgumentException(sprintf('Unknown scope kind in "%s".', $reference));
        }

        return self::of($kind, substr($reference, $position + 1));
    }

    public static function tryParse(string $reference): ?self
    {
        try {
            return self::parse($reference);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function toString(): string
    {
        return $this->kind->value . ':' . $this->identifier;
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}

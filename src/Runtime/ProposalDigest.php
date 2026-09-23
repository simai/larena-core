<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\OperationContext;

/**
 * The digest that ties an approval to one exact change.
 *
 * It is computed over the operation name and the canonical input, and nothing
 * else: not the time, not the actor, not a random token. That is what lets the
 * runtime recompute it from the input it is actually handed and refuse an
 * approval that was granted for a different change — with no stored proposal,
 * and therefore nothing to expire, leak or lose on restart.
 */
final class ProposalDigest
{
    public static function forContext(string $operation, OperationContext $context): string
    {
        return self::forInput($operation, $context->metadata);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function forInput(string $operation, array $input): string
    {
        $canonical = json_encode(
            ['operation' => $operation, 'input' => self::canonicalize($input)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return 'sha256:' . hash('sha256', $canonical);
    }

    /**
     * Sorted keys at every depth, so two callers that build the same input in a
     * different order produce the same digest.
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        ksort($value);

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[(string) $key] = self::canonicalize($item);
        }

        return $canonical;
    }
}

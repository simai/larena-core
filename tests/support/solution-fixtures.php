<?php

declare(strict_types=1);

require_once __DIR__ . '/operation-registry-fixtures.php';

use Larena\Core\Exceptions\SolutionRejected;

function solution_assert(bool $condition, string $message = 'solution assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * The smallest manifest that validates, with overrides merged on top. Tests state
 * the one thing they are about rather than restating a whole document.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function solution_manifest(array $overrides = []): array
{
    return [
        'schema' => 'larena.solution_manifest.v1',
        'solution_id' => 'example_solution',
        'version' => '1.0.0',
        'title' => 'Example Solution',
        'author' => 'SIMAI',
        'distribution' => 'simai_product',
        'packages' => ['larena/core', 'larena/storage'],
        ...$overrides,
    ];
}

/**
 * Runs a closure and returns the reason code it was refused with, or null when it
 * was not refused.
 *
 * @param callable(): mixed $call
 */
function solution_refusal(callable $call): ?string
{
    try {
        $call();
    } catch (SolutionRejected $rejected) {
        return $rejected->reasonCode;
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function solution_fixture(string $name): array
{
    $path = dirname(__DIR__) . '/fixtures/solutions/' . $name . '.json';
    $decoded = json_decode((string) file_get_contents($path), true);

    solution_assert(is_array($decoded), 'the ' . $name . ' fixture must be a JSON object');

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

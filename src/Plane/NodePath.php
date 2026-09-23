<?php

declare(strict_types=1);

namespace Larena\Core\Plane;

use InvalidArgumentException;

/**
 * Materialized path of plane node keys, root first.
 *
 * Adjacency plus materialized path is the frozen tree strategy for core planes:
 * descendant reads are one indexed prefix query, so SQLite and MySQL behave
 * identically without recursive CTE on ordinary hosting.
 */
final readonly class NodePath
{
    public const SEPARATOR = '/';
    public const MAX_DEPTH = 16;
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/';

    /** @param list<string> $segments */
    private function __construct(public array $segments)
    {
    }

    public static function root(string $key): self
    {
        return new self([self::assertKey($key)]);
    }

    public static function parse(string $path): self
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException('Node path must not be empty.');
        }

        $segments = explode(self::SEPARATOR, $path);
        foreach ($segments as $segment) {
            self::assertKey($segment);
        }

        $depth = count($segments) - 1;
        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidArgumentException(sprintf('Node path depth %d exceeds the maximum of %d.', $depth, self::MAX_DEPTH - 1));
        }

        return new self($segments);
    }

    public function child(string $key): self
    {
        if ($this->depth() + 1 >= self::MAX_DEPTH) {
            throw new InvalidArgumentException(sprintf('Node path depth %d exceeds the maximum of %d.', $this->depth() + 1, self::MAX_DEPTH - 1));
        }

        return new self([...$this->segments, self::assertKey($key)]);
    }

    public function depth(): int
    {
        return count($this->segments) - 1;
    }

    public function key(): string
    {
        return $this->segments[count($this->segments) - 1];
    }

    public function isDescendantOf(self $other): bool
    {
        return str_starts_with($this->toString() . self::SEPARATOR, $other->toString() . self::SEPARATOR)
            && $this->depth() > $other->depth();
    }

    public function toString(): string
    {
        return implode(self::SEPARATOR, $this->segments);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private static function assertKey(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Node key "%s" must match %s.', $key, self::KEY_PATTERN));
        }

        return $key;
    }
}

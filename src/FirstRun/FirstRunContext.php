<?php

declare(strict_types=1);

namespace Larena\Core\FirstRun;

final readonly class FirstRunContext
{
    /** @param array<string, int|string> $facts */
    public function __construct(private array $facts = [])
    {
        foreach ($facts as $key => $value) {
            if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $key) !== 1) {
                throw new \InvalidArgumentException('First-run context facts must use safe scalar keys and values.');
            }
        }
    }

    public function with(string $key, int|string $value): self
    {
        return new self([...$this->facts, $key => $value]);
    }

    public function string(string $key): string
    {
        $value = $this->facts[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \DomainException('first_run_context_missing_' . str_replace('.', '_', $key));
        }

        return $value;
    }

    public function integer(string $key): int
    {
        $value = $this->facts[$key] ?? null;
        if (!is_int($value)) {
            throw new \DomainException('first_run_context_missing_' . str_replace('.', '_', $key));
        }

        return $value;
    }
}

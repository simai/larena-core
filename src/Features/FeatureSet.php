<?php

declare(strict_types=1);

namespace Larena\Core\Features;

use InvalidArgumentException;

/**
 * The features of the packages a product runs (owner decision 2026-10-31). A package declares its features with
 * what each one requires; the product chooses them at build in `config/larena-product.php` (`features`). What is
 * off is absent: the package registers no routes, menu rows, access operations or tables for it.
 *
 * Without a product file, or with `features` null, every feature is on: package tests and editions that take a
 * package whole. With a list, the list is exact. Whether a feature is on is answered from the product's choice
 * alone, so the order in which packages declare does not matter; validate() checks the choice once all have.
 */
final class FeatureSet
{
    public const CONFIG_KEY = 'larena-product.features';

    /** @var array<string, array{package: string, requires: list<string>, operations: list<string>}> */
    private array $declared = [];

    /** @var array<string, true>|null */
    private readonly ?array $selected;

    /** @param array<mixed>|null $selected null: every feature is on; else a list of names */
    public function __construct(?array $selected = null)
    {
        if ($selected === null) {
            $this->selected = null;

            return;
        }
        $names = [];
        foreach ($selected as $name) {
            if (!is_string($name) || !self::validName($name)) {
                throw new InvalidArgumentException('larena_feature_name_invalid');
            }
            $names[$name] = true;
        }
        $this->selected = $names;
    }

    /** A product file's `features` value: null or absent means every feature is on. */
    public static function fromConfig(mixed $selected): self
    {
        if ($selected === null) {
            return new self();
        }
        if (!is_array($selected) || !array_is_list($selected)) {
            throw new InvalidArgumentException('larena_product_features_invalid');
        }

        return new self($selected);
    }

    /**
     * A package's features: name => ['requires' => [names], 'operations' => [access operation codes]].
     *
     * @param array<mixed> $features
     */
    public function declare(string $package, array $features): void
    {
        foreach ($features as $name => $feature) {
            if (!is_string($name) || !self::validName($name) || !is_array($feature)) {
                throw new InvalidArgumentException('larena_feature_declaration_invalid');
            }
            $known = $this->declared[$name] ?? null;
            if ($known !== null && $known['package'] !== $package) {
                throw new InvalidArgumentException('larena_feature_declared_twice');
            }
            $requires = $feature['requires'] ?? [];
            $operations = $feature['operations'] ?? [];
            if (!is_array($requires) || !array_is_list($requires) || !is_array($operations) || !array_is_list($operations)) {
                throw new InvalidArgumentException('larena_feature_declaration_invalid');
            }
            $this->declared[$name] = ['package' => $package, 'requires' => self::names($requires), 'operations' => self::names($operations)];
        }
    }

    public function on(string $name): bool
    {
        return $this->selected === null || isset($this->selected[$name]);
    }

    /** Whether the product chose its features; false when every feature is on. */
    public function chosen(): bool
    {
        return $this->selected !== null;
    }

    /** @return array<string, array{package: string, requires: list<string>, operations: list<string>}> */
    public function declared(): array
    {
        ksort($this->declared);

        return $this->declared;
    }

    /** @return list<string> the declared features that are on */
    public function enabled(): array
    {
        return array_values(array_filter(array_keys($this->declared()), $this->on(...)));
    }

    /**
     * The product's choice against what the packages declared: a chosen name nobody declared, a feature on
     * whose requirement is off, an undeclared requirement or a cycle is refused.
     */
    public function validate(): void
    {
        foreach (array_keys($this->selected ?? []) as $name) {
            if (!isset($this->declared[$name])) {
                throw new InvalidArgumentException('larena_feature_unknown: '.$name);
            }
        }
        foreach ($this->declared as $name => $feature) {
            foreach ($feature['requires'] as $required) {
                if (!isset($this->declared[$required])) {
                    throw new InvalidArgumentException('larena_feature_requirement_unknown: '.$name.' -> '.$required);
                }
                if ($this->on($name) && !$this->on($required)) {
                    throw new InvalidArgumentException('larena_feature_requirement_off: '.$name.' -> '.$required);
                }
            }
        }
        $state = [];
        $visit = function (string $name, array $path) use (&$visit, &$state): void {
            if (($state[$name] ?? null) === 'done') {
                return;
            }
            if (($state[$name] ?? null) === 'open') {
                throw new InvalidArgumentException('larena_feature_cycle: '.implode(' -> ', [...$path, $name]));
            }
            $state[$name] = 'open';
            foreach ($this->declared[$name]['requires'] as $required) {
                $visit($required, [...$path, $name]);
            }
            $state[$name] = 'done';
        };
        foreach (array_keys($this->declared) as $name) {
            $visit($name, []);
        }
    }

    /** @param list<mixed> $values @return list<string> */
    private static function names(array $values): array
    {
        $names = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('larena_feature_declaration_invalid');
            }
            $names[] = $value;
        }

        return $names;
    }

    private static function validName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $name) === 1;
    }
}

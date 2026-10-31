<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Features\FeatureSet;

function featureAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function featureRefused(callable $operation, string $reason, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        featureAssert(str_starts_with($exception->getMessage(), $reason), $message . ': got ' . $exception->getMessage());

        return;
    }
    featureAssert(false, $message . ': accepted');
}

$declare = static function (FeatureSet $set): void {
    $set->declare('larena/admin', [
        'admin.groups' => ['operations' => ['admin.groups.read']],
        'admin.settings.catalog' => ['requires' => ['admin.settings.base']],
        'admin.settings.base' => [],
    ]);
};

// Without a product choice every feature is on, declared or not.
$all = new FeatureSet();
$declare($all);
$all->validate();
featureAssert($all->on('admin.groups') && $all->on('admin.never.declared') && !$all->chosen(), 'every feature is on without a choice');
featureAssert(FeatureSet::fromConfig(null)->on('admin.groups'), 'a null choice is every feature');

// A choice is exact.
$chosen = new FeatureSet(['admin.settings.base', 'admin.settings.catalog']);
$declare($chosen);
$chosen->validate();
featureAssert($chosen->chosen() && !$chosen->on('admin.groups') && $chosen->on('admin.settings.catalog'), 'a choice is exact');
featureAssert($chosen->enabled() === ['admin.settings.base', 'admin.settings.catalog'], 'enabled lists the declared features that are on');
featureAssert($chosen->declared()['admin.groups']['operations'] === ['admin.groups.read'], 'operations are kept with their feature');

// A name nobody declared, a requirement that is off, a requirement nobody declared, a cycle.
$unknown = new FeatureSet(['admin.settings.base', 'admin.typo']);
$declare($unknown);
featureRefused($unknown->validate(...), 'larena_feature_unknown', 'an unknown chosen name is refused');
$off = new FeatureSet(['admin.settings.catalog']);
$declare($off);
featureRefused($off->validate(...), 'larena_feature_requirement_off', 'a feature on without its requirement is refused');
$missing = new FeatureSet();
$missing->declare('larena/env', ['env.sites' => ['requires' => ['env.nowhere']]]);
featureRefused($missing->validate(...), 'larena_feature_requirement_unknown', 'an undeclared requirement is refused');
$cycle = new FeatureSet();
$cycle->declare('larena/env', ['env.one' => ['requires' => ['env.two']], 'env.two' => ['requires' => ['env.one']]]);
featureRefused($cycle->validate(...), 'larena_feature_cycle', 'a cycle is refused');

// Two packages may not declare the same name; a bad name or shape is refused.
$twice = new FeatureSet();
$twice->declare('larena/admin', ['admin.groups' => []]);
featureRefused(static fn () => $twice->declare('larena/env', ['admin.groups' => []]), 'larena_feature_declared_twice', 'a name belongs to one package');
featureRefused(static fn () => new FeatureSet(['Groups']), 'larena_feature_name_invalid', 'a bad chosen name is refused');
featureRefused(static fn () => FeatureSet::fromConfig(['a' => 'admin.groups']), 'larena_product_features_invalid', 'a choice is a list');

echo "FeatureSetTest passed.\n";

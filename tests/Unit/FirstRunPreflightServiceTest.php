<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Core\FirstRun\FirstRunPreflightService;

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$directory = sys_get_temp_dir();

$passing = new FirstRunPreflightService($capsule->getConnection(), ['runtime' => $directory], true);
assert($passing->inspect()->passed());

$failing = new FirstRunPreflightService($capsule->getConnection(), ['runtime' => $directory . '/larena-first-run-missing-' . bin2hex(random_bytes(4))]);
$report = $failing->inspect();
assert(!$report->passed());
assert(array_filter($report->checks, static fn (array $check): bool => $check['id'] === 'database.sqlite' && !$check['passed']) !== []);
assert(array_filter($report->checks, static fn (array $check): bool => $check['id'] === 'writable.runtime' && !$check['passed']) !== []);

$mysql = new class(new PDO('sqlite::memory:'), 'larena_first_run_mysql_test', '', ['driver' => 'mysql']) extends Illuminate\Database\Connection {
    public function getDriverName(): string
    {
        return 'mysql';
    }
};
$mysqlReport = (new FirstRunPreflightService($mysql, ['runtime' => $directory]))->inspect();
assert(array_filter($mysqlReport->checks, static fn (array $check): bool => $check['id'] === 'database.mysql' && $check['passed']) !== []);
assert(array_filter($mysqlReport->checks, static fn (array $check): bool => $check['id'] === 'database.sqlite') === []);
assert(array_filter($mysqlReport->checks, static fn (array $check): bool => $check['id'] === 'extension.pdo_mysql') !== []);

echo "First-run preflight contract passed.\n";

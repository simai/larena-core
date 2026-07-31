<?php

declare(strict_types=1);

namespace Larena\Core\FirstRun;

use Illuminate\Database\Connection;

final readonly class FirstRunPreflightService
{
    /** @param array<string, string> $writablePaths */
    public function __construct(
        private Connection $connection,
        private array $writablePaths,
        private bool $allowMemoryDatabase = false,
    ) {
    }

    public function inspect(): FirstRunPreflightReport
    {
        $checks = [];
        $checks[] = $this->check('php', version_compare(PHP_VERSION, '8.3.0', '>='), 'PHP 8.3 or newer is required.');

        foreach (['json', 'mbstring', 'openssl', 'pdo', 'pdo_sqlite'] as $extension) {
            $checks[] = $this->check('extension.' . $extension, extension_loaded($extension), 'Enable the required ' . $extension . ' PHP extension.');
        }

        foreach ($this->writablePaths as $id => $path) {
            $checks[] = $this->check('writable.' . $id, is_dir($path) && is_writable($path), 'Make the ' . $id . ' directory writable by the application user.');
        }

        $driver = $this->connection->getDriverName();
        $database = (string) $this->connection->getDatabaseName();
        $sqlite = $driver === 'sqlite';
        $bounded = $sqlite && ($this->allowMemoryDatabase || ($database !== '' && $database !== ':memory:'));
        $checks[] = $this->check('database.sqlite', $bounded, 'Configure a dedicated writable SQLite database file for first run.');

        return new FirstRunPreflightReport($checks);
    }

    /** @return array{id: string, passed: bool, message: string} */
    private function check(string $id, bool $passed, string $failure): array
    {
        return ['id' => $id, 'passed' => $passed, 'message' => $passed ? 'Ready.' : $failure];
    }
}

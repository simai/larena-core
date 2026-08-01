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

        $driver = $this->connection->getDriverName();
        $driverExtension = $driver === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite';
        foreach (['json', 'mbstring', 'openssl', 'pdo', $driverExtension] as $extension) {
            $checks[] = $this->check('extension.' . $extension, extension_loaded($extension), 'Enable the required ' . $extension . ' PHP extension.');
        }

        foreach ($this->writablePaths as $id => $path) {
            $checks[] = $this->check('writable.' . $id, is_dir($path) && is_writable($path), 'Make the ' . $id . ' directory writable by the application user.');
        }

        $database = (string) $this->connection->getDatabaseName();
        if ($driver === 'sqlite') {
            $bounded = $this->allowMemoryDatabase || ($database !== '' && $database !== ':memory:');
            $checks[] = $this->check('database.sqlite', $bounded, 'Configure a dedicated writable SQLite database file for first run.');
        } elseif ($driver === 'mysql') {
            try {
                $this->connection->getPdo();
                $connected = $database !== '';
            } catch (\Throwable) {
                $connected = false;
            }
            $checks[] = $this->check('database.mysql', $connected, 'Configure a reachable dedicated MySQL database for first run.');
        } else {
            $checks[] = $this->check('database.supported', false, 'Configure a supported SQLite or MySQL database for first run.');
        }

        return new FirstRunPreflightReport($checks);
    }

    /** @return array{id: string, passed: bool, message: string} */
    private function check(string $id, bool $passed, string $failure): array
    {
        return ['id' => $id, 'passed' => $passed, 'message' => $passed ? 'Ready.' : $failure];
    }
}

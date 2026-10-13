<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PDO;
use PDOException;
use Throwable;

/**
 * The database connection as the doctor sees it: whether the configured connection answers, why it does not
 * (as a safe reason and message, never the driver's text), and whether an install could proceed on it.
 */
final class DatabaseConnectionDiagnostic
{
    public static function connection(ConfigRepository $config): array
    {
        $default = (string) $config->get('database.default', 'sqlite');
        $connection = $config->get("database.connections.{$default}", []);

        if (!is_array($connection)) {
            return [
                'status' => 'degraded',
                'reason' => 'database_connection_config_missing',
                'default_connection' => $default,
                'required_for_current_preview' => false,
                'required_for_future_install' => true,
                'action' => 'Define the selected database connection in config/database.php and .env.',
            ];
        }

        $driver = (string) ($connection['driver'] ?? $default);
        $summary = [
            'default_connection' => $default,
            'driver' => $driver,
            'host' => isset($connection['host']) ? (string) $connection['host'] : null,
            'port' => isset($connection['port']) ? (string) $connection['port'] : null,
            'database' => isset($connection['database']) ? (string) $connection['database'] : null,
            'username_configured' => isset($connection['username']) && (string) $connection['username'] !== '',
            'password_configured' => isset($connection['password']) && (string) $connection['password'] !== '',
            'required_for_current_preview' => false,
            'required_for_future_install' => true,
            'mutates_state' => false,
        ];

        if ($driver === 'sqlite') {
            $database = (string) ($connection['database'] ?? '');
            $exists = $database === ':memory:' || ($database !== '' && is_file($database));

            return [
                ...$summary,
                'status' => $exists ? 'passed' : 'degraded',
                'reason' => $exists ? null : 'sqlite_database_file_missing',
                'action' => $exists ? null : 'Create the SQLite file or update DB_DATABASE in .env.',
            ];
        }

        if ($driver !== 'mysql') {
            return [
                ...$summary,
                'status' => 'diagnostic',
                'reason' => 'database_driver_not_checked_by_installer_foundation',
                'action' => 'Run the project-specific DB smoke before enabling install migrations.',
            ];
        }

        if (!extension_loaded('pdo_mysql')) {
            return [
                ...$summary,
                'status' => 'degraded',
                'reason' => 'pdo_mysql_extension_missing',
                'action' => 'Enable pdo_mysql for the PHP binary used by Artisan.',
            ];
        }

        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? '3306');
        $database = (string) ($connection['database'] ?? '');
        $username = (string) ($connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? '');
        $charset = (string) ($connection['charset'] ?? 'utf8mb4');

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2,
                ],
            );
            $pdo->query('select 1');

            return [
                ...$summary,
                'status' => 'passed',
                'reason' => null,
                'action' => null,
            ];
        } catch (PDOException $exception) {
            return [
                ...$summary,
                'status' => 'degraded',
                'reason' => self::failureReason($exception),
                'safe_message' => self::failureMessage($exception),
                'action' => 'Check DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE and DB_USERNAME in .env, then run php artisan config:clear and php artisan larena:doctor --full.',
            ];
        } catch (Throwable) {
            return [
                ...$summary,
                'status' => 'degraded',
                'reason' => 'database_connection_check_failed',
                'safe_message' => 'Database connection could not be checked safely. Secret values are not printed.',
                'action' => 'Check local DB service and .env values, then run php artisan config:clear and php artisan larena:doctor --full.',
            ];
        }
    }

    private static function failureReason(PDOException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'Access denied')) {
            return 'database_credentials_rejected';
        }

        if (str_contains($message, 'Connection refused')) {
            return 'database_connection_refused';
        }

        if (str_contains($message, 'Unknown database')) {
            return 'database_not_found';
        }

        return 'database_connection_failed';
    }

    private static function failureMessage(PDOException $exception): string
    {
        return match (self::failureReason($exception)) {
            'database_credentials_rejected' => 'Database credentials were rejected. Password is not printed; check local .env values.',
            'database_connection_refused' => 'Database server refused the connection. Check that the local DB service is running.',
            'database_not_found' => 'Configured database was not found. Create it manually or update DB_DATABASE.',
            default => 'Database connection failed. Secret values are not printed.',
        };
    }

    public static function readyForInstall(array $doctor): bool
    {
        $database = $doctor['checks']['database_connection'] ?? [];

        return is_array($database) && ($database['status'] ?? null) === 'passed';
    }
}

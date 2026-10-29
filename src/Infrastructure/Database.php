<?php
declare(strict_types=1);

namespace Projectr\Core\Infrastructure;

use Projectr\Core\System\Config;
use RuntimeException;
use PDOException;
use PDO;

/**
 * Database class provides functionality for establishing and managing
 * database connections, executing SQL queries, and retrieving results using
 * a singleton design pattern.
 *
 * @author Julius Derigs <info@julius-derigs.de>
 * @version 1.0.0
 */
class Database {
    private static ?PDO $instance = null;

    /**
     * Establishes a connection to the database using the provided configuration
     * and returns a PDO instance. Ensures singleton behavior to reuse the same
     * connection if it has already been established.
     *
     * @return PDO The database connection instance.
     * @throws RuntimeException If the database configuration is missing, invalid,
     *                          incomplete, or the connection fails.
     */
    public static function connect(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = Config::get('database', []);
        if (!is_array($config)) {
            throw new RuntimeException('Database configuration is missing or invalid.');
        }

        $dbTypeValue = self::getSetting($config, 'DB_TYPE', 'type');
        if ($dbTypeValue === null) {
            throw new RuntimeException('Database configuration is incomplete.');
        }

        $dbType = strtolower(trim($dbTypeValue));
        if (!in_array($dbType, ['mysql', 'pgsql'], true)) {
            throw new RuntimeException('Unsupported database type.');
        }

        $host = self::requireDsnValue(self::getSetting($config, 'DB_HOST', 'host'), 'host');
        $user = self::getSetting($config, 'DB_USER', 'user');
        $password = self::getSetting($config, 'DB_PASS', 'pass') ?? '';
        $database = self::requireDsnValue(self::getSetting($config, 'DB_NAME', 'name'), 'database name');

        if ($user === null || $user === '') {
            throw new RuntimeException('Database configuration is incomplete.');
        }

        $portValue = self::getSetting($config, 'DB_PORT', 'port')
            ?? ($dbType === 'mysql' ? '3306' : '5432');
        $port = filter_var($portValue, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 65535,
            ],
        ]);
        if ($port === false) {
            throw new RuntimeException('Invalid database port.');
        }

        $dsn = match ($dbType) {
            'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            'pgsql' => "pgsql:host={$host};port={$port};dbname={$database}",
        };

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if ($dbType === 'mysql') {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        try {
            self::$instance = new PDO($dsn, $user, $password, $options);
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed.', 0, $exception);
        }

        return self::$instance;
    }

    /**
     * Executes a given SELECT query on the database with optional parameters
     * and returns the result set as an array.
     *
     * @param string $query The SQL query to execute.
     * @param array $params An associative array of parameters to bind to the query.
     * @return array The fetched results as an associative array.
     * @throws RuntimeException If the query preparation or execution fails,
     *                          or if a database connection cannot be established.
     */
    public static function select(string $query, array $params = []): array {
        try {
            $statement = self::connect()->prepare($query);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare database query.');
            }

            $statement->execute($params);
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw new RuntimeException('Database select operation failed.', 0, $exception);
        }
    }

    /**
     * Executes a database query with the provided SQL string and optional parameters.
     * Prepares the statement, binds the parameters, and executes the query.
     *
     * @param string $query The SQL query to be executed.
     * @param array $params An associative array of parameter bindings for the query.
     * @return bool True if the query executes successfully, false otherwise.
     * @throws RuntimeException If the query preparation or execution fails, or if
     *                          the database connection cannot be established.
     */
    public static function execute(string $query, array $params = []): bool {
        try {
            $statement = self::connect()->prepare($query);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare database query.');
            }

            return $statement->execute($params);
        } catch (PDOException $exception) {
            throw new RuntimeException('Database execute operation failed.', 0, $exception);
        }
    }

    /**
     * Retrieves a configuration setting from the specified environment variable or configuration array.
     * If the value is not found or is invalid, returns null.
     *
     * @param array $config An associative array containing configuration values.
     * @param string $environmentKey The key to look for in the environment variables ($_ENV).
     * @param string $configKey The key to look for in the configuration array.
     *
     * @return string|null The configuration value as a string, or null if not found.
     * @throws RuntimeException If the configuration value is invalid (not a string or integer).
     */
    private static function getSetting(array $config, string $environmentKey, string $configKey): ?string {
        $value = $_ENV[$environmentKey] ?? $config[$configKey] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value)) {
            throw new RuntimeException('Database configuration contains an invalid value.');
        }

        return (string) $value;
    }

    /**
     * Validates the provided DSN value for a database configuration and ensures it meets the required criteria.
     *
     * @param string|null $value The DSN value to validate.
     * @param string $label A descriptive label for the value being validated, used in error messages.
     *
     * @return string The validated and non-empty DSN value.
     *
     * @throws RuntimeException If the provided value is null, empty, or contains invalid characters.
     */
    private static function requireDsnValue(?string $value, string $label): string {
        if ($value === null || $value === '' || preg_match('/[\x00-\x1F\x7F;]/', $value) === 1) {
            throw new RuntimeException("Invalid database {$label}.");
        }

        return $value;
    }
}

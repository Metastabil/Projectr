<?php
declare(strict_types=1);

namespace Projectr\Core\System;

use RuntimeException;

/**
 * Manages the configuration system for the application, including loading configuration
 * files and retrieving configuration values.
 *
 * @author Julius Derigs <info@julius-derigs.de>
 * @version 1.0.0
 */
class Config {
    private static bool $loaded = false;
    private static array $items = [];

    /**
     * Loads the application's configuration files from the specified directory.
     *
     * This method checks if the configuration directory exists, validates its location,
     * and processes the PHP configuration files found within it. The configurations are
     * stored in an internal structure for later use.
     *
     * @return void Does not return any value.
     * @throws RuntimeException If the application directory cannot be resolved, the configuration
     *                          directory is invalid, or if there is an error reading the configuration files.
     */
    public static function load(): void {
        if (self::$loaded) {
            return;
        }

        $applicationPath = realpath(dirname(__DIR__, 2));
        if ($applicationPath === false) {
            throw new RuntimeException('Unable to resolve the application directory.');
        }

        $directoryName = $_ENV['APP_CONFIG_DIR'] ?? 'config';
        if (!is_string($directoryName) || $directoryName === '') {
            throw new RuntimeException('Invalid configuration directory.');
        }

        $path = realpath($applicationPath . DIRECTORY_SEPARATOR . $directoryName);
        if ($path === false || !is_dir($path) || !self::isInsideDirectory($path, $applicationPath)) {
            throw new RuntimeException('Invalid configuration directory.');
        }

        $files = glob($path . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            throw new RuntimeException('Unable to read configuration files.');
        }

        $items = [];
        foreach ($files as $file) {
            $resolvedFile = realpath($file);
            if ($resolvedFile === false || !self::isInsideDirectory($resolvedFile, $path)) {
                throw new RuntimeException('Invalid configuration file.');
            }

            if (!is_file($resolvedFile)) {
                continue;
            }

            $key = basename($resolvedFile, '.php');
            $config = require $resolvedFile;

            if (is_array($config)) {
                $items[$key] = $config;
            }
        }

        self::$items = $items;
        self::$loaded = true;
    }

    /**
     * Retrieves a value from a nested array structure using a dot-delimited key.
     *
     * @param string $key The dot-delimited key specifying the value to retrieve.
     * @param mixed $default The default value to return if the specified key does not exist.
     * @return mixed Returns the value associated with the key, or the default value if the key is not found.
     */
    public static function get(string $key, mixed $default = null): mixed {
        if (!self::$loaded) {
            self::load();
        }

        $segments = explode('.', $key);
        $values = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($values) || !array_key_exists($segment, $values)) {
                return $default;
            }

            $values = $values[$segment];
        }

        return $values;
    }

    /**
     * Determines whether the given path is located inside the specified directory.
     *
     * @param string $path The path to check.
     * @param string $directory The directory against which the path is checked.
     * @return bool Returns true if the path is inside the directory, false otherwise.
     */
    private static function isInsideDirectory(string $path, string $directory): bool {
        $directoryPrefix = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR;
        $path = rtrim($path, "\\/");

        if (PHP_OS_FAMILY === 'Windows') {
            return strncasecmp($path, $directoryPrefix, strlen($directoryPrefix)) === 0;
        }

        return strncmp($path, $directoryPrefix, strlen($directoryPrefix)) === 0;
    }
}

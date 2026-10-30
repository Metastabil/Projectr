<?php
declare(strict_types=1);

namespace Projectr\Core\Infrastructure;

use Projectr\Core\System\Config;
use DateTimeImmutable;
use RuntimeException;

/**
 * File-based logger that appends JSON lines to a daily log file under storage.
 *
 * @author Julius Derigs <info@julius-derigs.de>
 * @version 1.0.0
 */
class Logger {
    /**
     * Writes a log entry to a specified log file.
     *
     * This method writes log entries with a given message and level to a
     * log file named by the current date in the configured log directory.
     * It ensures proper file locking, secure file permissions, and reliable
     * handling of file operations.
     *
     * @param string $message The message to log.
     * @param string $level The severity level of the log. Defaults to 'info'.
     *
     * @return void
     *
     * @throws RuntimeException If the application directory cannot be resolved.
     * @throws RuntimeException If the log directory or log file is inaccessible.
     * @throws RuntimeException If the log file is a symbolic link.
     * @throws RuntimeException If the log entry cannot be encoded to JSON.
     * @throws RuntimeException If the log file cannot be securely locked.
     * @throws RuntimeException If the log file cannot be written.
     * @throws RuntimeException If the log file cannot be flushed.
     * @throws RuntimeException If the log file permissions cannot be secured.
     */
    public static function write(string $message, string $level = 'info'): void {
        $applicationPath = realpath(dirname(__DIR__, 2));
        if ($applicationPath === false) {
            throw new RuntimeException('Unable to resolve the application directory.');
        }

        $configuredDirectory = $_ENV['APP_LOG_DIR'] ?? Config::get('app.logDir', '/storage/logs');
        $directory = self::resolveLogDirectory($configuredDirectory, $applicationPath);

        $now = new DateTimeImmutable();
        $path = $directory . DIRECTORY_SEPARATOR . $now->format('Ymd') . '.log';
        if (is_link($path)) {
            throw new RuntimeException('Log file must not be a symbolic link.');
        }

        $record = json_encode([
            'timestamp' => $now->format(DATE_ATOM),
            'level' => $level,
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($record === false) {
            throw new RuntimeException('Unable to encode log entry.');
        }

        $line = $record . PHP_EOL;
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'ab');
        } finally {
            umask($previousUmask);
        }

        if ($handle === false) {
            throw new RuntimeException('Unable to open log file.');
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock log file.');
            }

            try {
                self::assertOpenedFileMatchesPath($handle, $path);

                if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($path, 0600)) {
                    throw new RuntimeException('Unable to secure log file permissions.');
                }

                $length = strlen($line);
                $offset = 0;
                while ($offset < $length) {
                    $written = @fwrite($handle, substr($line, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Unable to write log entry.');
                    }

                    $offset += $written;
                }

                if (!@fflush($handle)) {
                    throw new RuntimeException('Unable to flush log entry.');
                }
            } finally {
                @flock($handle, LOCK_UN);
            }
        } finally {
            @fclose($handle);
        }
    }

    /**
     * Resolves the log directory path based on the provided configuration and application directory.
     * Validates and ensures that the resolved path is relative to the application directory
     * and resides within the "storage" directory, creating missing directories as necessary.
     *
     * @param mixed $configuredDirectory The directory configured for log storage. Must be a non-empty string.
     * @param string $applicationPath The absolute path to the root of the application.
     * @return string The absolute and resolved path to the log directory.
     * @throws RuntimeException If the configured directory is invalid, resolves outside allowed directories,
     *                          or fails to create necessary directories.
     */
    private static function resolveLogDirectory(mixed $configuredDirectory, string $applicationPath): string {
        if (!is_string($configuredDirectory) || trim($configuredDirectory) === '') {
            throw new RuntimeException('Invalid log directory.');
        }

        $normalized = str_replace('\\', '/', $configuredDirectory);
        if (str_starts_with($normalized, '//') || preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            throw new RuntimeException('Log directory must be relative to the application directory.');
        }

        $relativeDirectory = trim($normalized, '/');
        $segments = explode('/', $relativeDirectory);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || str_contains($segment, ':') || str_contains($segment, "\0")) {
                throw new RuntimeException('Invalid log directory.');
            }
        }

        $isStorageDirectory = PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($segments[0], 'storage') === 0
            : $segments[0] === 'storage';
        if (!$isStorageDirectory) {
            throw new RuntimeException('Log directory must be inside the storage directory.');
        }

        $current = $applicationPath;
        $storageRoot = null;
        foreach ($segments as $index => $segment) {
            $candidate = $current . DIRECTORY_SEPARATOR . $segment;

            if (is_link($candidate)) {
                $resolvedLink = realpath($candidate);
                if ($index === 0 || $resolvedLink === false || $storageRoot === null
                    || !self::isInsideDirectory($resolvedLink, $storageRoot)) {
                    throw new RuntimeException('Log directory resolves outside the storage directory.');
                }
            }

            if (!is_dir($candidate)) {
                if (file_exists($candidate) || is_link($candidate)) {
                    throw new RuntimeException('Log directory path is not a directory.');
                }

                if (!@mkdir($candidate, 0700) && !is_dir($candidate)) {
                    throw new RuntimeException('Unable to create log directory.');
                }
            }

            $resolvedDirectory = realpath($candidate);
            if ($resolvedDirectory === false || !is_dir($resolvedDirectory)) {
                throw new RuntimeException('Invalid log directory.');
            }

            if ($index === 0) {
                if (!self::isInsideDirectory($resolvedDirectory, $applicationPath)) {
                    throw new RuntimeException('Storage directory resolves outside the application directory.');
                }

                $storageRoot = $resolvedDirectory;
            } elseif ($storageRoot === null || !self::isInsideDirectory($resolvedDirectory, $storageRoot)) {
                throw new RuntimeException('Log directory resolves outside the storage directory.');
            }

            $current = $resolvedDirectory;
        }

        if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($current, 0700)) {
            throw new RuntimeException('Unable to secure log directory permissions.');
        }

        return $current;
    }

    /**
     * Detects a symlink or path replacement between the pre-open check and the write.
     *
     * @param resource $handle Open log file handle.
     * @param string $path Log file path.
     * @return void
     * @throws RuntimeException If the path does not identify the opened regular file.
     */
    private static function assertOpenedFileMatchesPath($handle, string $path): void {
        if (is_link($path)) {
            throw new RuntimeException('Log file must not be a symbolic link.');
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $openedStat = @fstat($handle);
        $pathStat = @lstat($path);
        if (
            $openedStat === false
            || $pathStat === false
            || (($pathStat['mode'] & 0170000) !== 0100000)
            || $openedStat['dev'] !== $pathStat['dev']
            || $openedStat['ino'] !== $pathStat['ino']
        ) {
            throw new RuntimeException('Log file path changed while it was being opened.');
        }
    }

    /**
     * Determines if a given path is inside the specified directory.
     *
     * @param string $path The path to check.
     * @param string $directory The directory against which the path is checked.
     * @return bool True if the path is inside the directory, false otherwise.
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

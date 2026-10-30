<?php
declare(strict_types=1);

namespace Projectr\Core\System;

use RuntimeException;

/**
 * Provides a utility class for managing PHP sessions with robust handling of session-related operations.
 * This class includes features for starting, regenerating, retrieving, setting,
 * and destroying sessions, along with flash message support and secure session configurations.
 *
 * @author Julius Derigs <info@julius-derigs.de>
 * @version 1.0.0
 */
class Session {
    /**
     * Initializes and starts a new PHP session with predefined configurations
     * or resumes an existing session if one is already active.
     *
     * Ensures proper handling for CLI environments, session initialization states,
     * and enforces secure session configurations.
     *
     * @return void
     * @throws RuntimeException If sessions are disabled or session start fails.
     */
    public static function start(): void {
        if (PHP_SAPI === 'cli') {
            self::initializeSessionArray();
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (session_status() !== PHP_SESSION_NONE) {
            throw new RuntimeException('Sessions are disabled in this PHP configuration.');
        }

        $options = [
            'use_strict_mode' => true,
            'use_cookies' => true,
            'use_only_cookies' => true,
            'use_trans_sid' => false,
            'cookie_lifetime' => 0,
            'cookie_path' => '/',
            'cookie_secure' => self::shouldUseSecureCookie(),
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ];

        if (!session_start($options)) {
            throw new RuntimeException('Unable to start the session.');
        }

        self::initializeSessionArray();
    }

    /**
     * Regenerates the session ID while optionally deleting the old session data.
     *
     * @param bool $deleteOldSession Indicates whether to delete the old session data. Defaults to true.
     * @return bool Returns true on successful regeneration, or false if running in CLI mode.
     * @throws RuntimeException If the session ID cannot be regenerated.
     */
    public static function regenerateId(bool $deleteOldSession = true): bool {
        self::ensureStarted();

        if (PHP_SAPI === 'cli') {
            return false;
        }

        if (!session_regenerate_id($deleteOldSession)) {
            throw new RuntimeException('Unable to regenerate the session ID.');
        }

        return true;
    }

    /**
     * Sets a value in the session data for the specified key.
     *
     * @param string $key The key to associate with the value in the session.
     * @param mixed $value The value to store in the session.
     * @return void
     */
    public static function set(string $key, mixed $value): void {
        self::ensureStarted();
        $_SESSION[$key] = $value;
    }

    /**
     * Retrieves a value from the session by the specified key.
     *
     * @param string $key The key associated with the session value to retrieve.
     * @param mixed $default The default value to return if the key does not exist in the session.
     * @return mixed The value from the session if the key exists, or the default value if the key is not found.
     */
    public static function get(string $key, mixed $default = null): mixed {
        self::ensureStarted();

        return array_key_exists($key, $_SESSION) ? $_SESSION[$key] : $default;
    }

    /**
     * Checks if a specified key exists in the session.
     *
     * @param string $key The key to check for existence in the session.
     * @return bool True if the key exists in the session, otherwise false.
     */
    public static function has(string $key): bool {
        self::ensureStarted();

        return array_key_exists($key, $_SESSION);
    }

    /**
     * Removes a value from the session by the specified key.
     *
     * @param string $key The key associated with the session value to remove.
     * @return void
     */
    public static function remove(string $key): void {
        self::ensureStarted();
        unset($_SESSION[$key]);
    }

    /**
     * Clears all data from the current session.
     *
     * @return void
     */
    public static function clear(): void {
        self::ensureStarted();
        $_SESSION = [];
    }

    /**
     * Destroys the current session and clears all associated data and cookies.
     *
     * @return void
     * @throws RuntimeException If the session cannot be destroyed or the session cookie cannot be expired.
     */
    public static function destroy(): void {
        self::ensureStarted();

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $cookie = session_get_cookie_params();
            $sessionName = session_name();

            if (!session_destroy()) {
                throw new RuntimeException('Unable to destroy the session.');
            }

            if (PHP_SAPI === 'cli') {
                return;
            }

            $cookieExpired = setcookie($sessionName, '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);

            if (!$cookieExpired) {
                throw new RuntimeException('Unable to expire the session cookie.');
            }
        }
    }

    /**
     * Sets a flash message in the session with a specified type and message content.
     *
     * @param string $type The type of the flash message (e.g., 'success', 'error').
     * @param string $message The content of the flash message.
     * @return void
     */
    public static function setFlashMessage(string $type, string $message): void {
        self::set('flashMessage', [
            'type' => $type,
            'message' => $message,
        ]);
    }

    /**
     * Retrieves and removes a flash message from the session.
     *
     * @return array|null An associative array containing the flash message with keys 'type' and 'message',
     *                    or null if no valid flash message is found.
     */
    public static function getFlashMessage(): ?array {
        $flashMessage = self::get('flashMessage');
        self::remove('flashMessage');

        if (!is_array($flashMessage)
            || !isset($flashMessage['type'], $flashMessage['message'])
            || !is_string($flashMessage['type'])
            || !is_string($flashMessage['message'])) {
            return null;
        }

        return [
            'type' => $flashMessage['type'],
            'message' => $flashMessage['message'],
        ];
    }

    /**
     * Ensures the session is started and initializes the session array.
     *
     * @return void
     */
    private static function ensureStarted(): void {
        self::start();
        self::initializeSessionArray();
    }

    /**
     * Ensures that the session is initialized as an array. If the session does not exist or is not an array, it will be
     * set to an empty array.
     *
     * @return void
     */
    private static function initializeSessionArray(): void {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
    }

    /**
     * Determines if the current request is made over a secure connection (HTTPS).
     *
     * @return bool True if the request is secure, false otherwise.
     */
    private static function isSecureRequest(): bool {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

        return in_array($https, ['on', '1', 'true'], true);
    }

    /**
     * Determines whether secure cookies should be used based on HTTPS, the explicit setting,
     * and the application environment. Production always requires secure cookies.
     *
     * @return bool True if secure cookies should be used, false otherwise.
     */
    private static function shouldUseSecureCookie(): bool {
        $configuredSecure = filter_var(
            $_ENV['APP_SESSION_COOKIE_SECURE'] ?? false,
            FILTER_VALIDATE_BOOL
        );
        $environment = strtolower((string) ($_ENV['APP_ENV'] ?? 'production'));

        return self::isSecureRequest() || $configuredSecure || $environment === 'production';
    }
}

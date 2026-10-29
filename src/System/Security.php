<?php
declare(strict_types=1);

namespace Projectr\Core\System;

use LengthException;

/**
 * Provides security-related functionalities such as password hashing and verification.
 *
 * @author Julius Derigs <info@julius-derigs.de>
 * @version 1.0.0
 */
class Security {
    /**
     * Hashes the given password using the default password hashing algorithm.
     *
     * @param string $password The plain-text password to be hashed.
     * @return string Returns the hashed password.
     * @throws LengthException If the password exceeds the maximum length allowed by the bcrypt algorithm.
     */
    public static function hashPassword(string $password): string {
        if (PASSWORD_DEFAULT === PASSWORD_BCRYPT && strlen($password) > 72) {
            throw new LengthException('Passwords hashed with bcrypt may not exceed 72 bytes.');
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verifies that a given password matches a hashed password, ensuring proper validation rules.
     *
     * @param string $password The plaintext password to verify.
     * @param string $hash The hashed password to compare against.
     * @return bool Returns true if the password matches the hash, false otherwise.
     */
    public static function verifyPassword(string $password, string $hash): bool {
        $hashInfo = password_get_info($hash);

        // bcrypt silently ignores bytes beyond this limit, which can make distinct passwords equivalent.
        if (($hashInfo['algo'] ?? null) === PASSWORD_BCRYPT && strlen($password) > 72) {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Determines if the given hash requires rehashing based on the currently used algorithm and options.
     *
     * @param string $hash The hashed password to be verified for rehashing necessity.
     * @return bool Returns true if the hash needs rehashing, false otherwise.
     */
    public static function needsRehash(string $hash): bool {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}

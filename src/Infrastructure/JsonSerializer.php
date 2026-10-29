<?php
declare(strict_types=1);

namespace Projectr\Core\Infrastructure;

use RuntimeException;
use JsonException;

/**
 * Provides methods for encoding and decoding JSON data.
 *
 * This class facilitates safe JSON serialization and deserialization
 * with proper error handling. It ensures strict and robust processing
 * of JSON data while maintaining a maximum depth for nested structures.
 *
 * @author Julius Derigs <info@julius-derigs.de>
 * @version 1.0.0
 */
class JsonSerializer {
    private const int MAX_DEPTH = 512;

    /**
     * Encodes a value into its JSON representation.
     *
     * @param mixed $data The value to be encoded. Can be any data type supported by JSON.
     * @return string The JSON-encoded string representation of the input value.
     * @throws RuntimeException If the encoding process fails due to an unsupported value or other errors.
     */
    public static function encode(mixed $data): string {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR, self::MAX_DEPTH);
        } catch (JsonException $exception) {
            throw new RuntimeException('JSON encoding failed.', 0, $exception);
        }
    }

    /**
     * Decodes a JSON-encoded string into a PHP value.
     *
     * @param string $data The JSON string to decode.
     * @return mixed The decoded value. Returns an associative array or other PHP structure based on the input JSON.
     * @throws RuntimeException If the JSON decoding process fails.
     */
    public static function decode(string $data): mixed {
        try {
            return json_decode(
                $data,
                true,
                self::MAX_DEPTH,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('JSON decoding failed.', 0, $exception);
        }
    }
}

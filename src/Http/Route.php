<?php
declare(strict_types=1);

namespace Projectr\Core\Http;

use InvalidArgumentException;

/**
 * Builds validated route definitions without storing global route state.
 *
 * Each call to {@see self::define()} returns an independent route definition
 * that can be stored or passed to a router.
 */
final class Route {
    /**
     * Creates a normalized and validated route definition.
     *
     * Middleware entries use the `ClassName@method` format and remain ordered
     * in the returned definition.
     *
     * @param string $method HTTP method, for example `GET` or `POST`.
     * @param string $path Absolute route path beginning with `/`.
     * @param string $controller Syntactically valid controller class name.
     * @param string $action Controller action method name expected by the router.
     * @param list<string> $middlewares Middleware entries in `ClassName@method` format.
     * @return array{
     *     method: string,
     *     path: string,
     *     controller: string,
     *     action: string,
     *     middlewares: list<array{class: string, method: string}>
     * }
     * @throws InvalidArgumentException If a route value or middleware entry is invalid.
     */
    public static function define(string $method, string $path, string $controller, string $action, array $middlewares = []): array {
        $method = strtoupper($method);
        if (preg_match('/^[!#$%&\'*+.^_`|~-]+$/D', $method) !== 1) {
            throw new InvalidArgumentException('Route method must be a valid HTTP method token.');
        }

        if (
            $path === ''
            || $path[0] !== '/'
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
            || str_contains($path, '\\')
            || str_contains($path, '?')
            || str_contains($path, '#')
        ) {
            throw new InvalidArgumentException('Route path must be an absolute path without control characters, spaces, or query and fragment delimiters.');
        }

        if (!self::hasValidPathParameters($path)) {
            throw new InvalidArgumentException('Route path contains an invalid or duplicate parameter.');
        }

        if (!self::isValidClassName($controller)) {
            throw new InvalidArgumentException('Controller must be a valid class name.');
        }

        if (!self::isValidIdentifier($action)) {
            throw new InvalidArgumentException('Action must be a valid method name.');
        }

        if (!array_is_list($middlewares)) {
            throw new InvalidArgumentException('Middleware entries must be provided as a list.');
        }

        $parsedMiddlewares = [];
        foreach ($middlewares as $middleware) {
            if (!is_string($middleware)) {
                throw new InvalidArgumentException('Each middleware entry must be a string.');
            }

            $parsedMiddlewares[] = self::parseMiddleware($middleware);
        }

        return [
            'method' => $method,
            'path' => $path,
            'controller' => $controller,
            'action' => $action,
            'middlewares' => $parsedMiddlewares,
        ];
    }

    /**
     * Checks whether a string is a syntactically valid PHP class name.
     *
     * @param string $class Candidate class name.
     * @return bool True when every namespace segment is a valid identifier.
     */
    private static function isValidClassName(string $class): bool {
        if (str_starts_with($class, '\\')) {
            $class = substr($class, 1);
        }

        if ($class === '') {
            return false;
        }

        return array_all(explode('\\', $class), fn($segment) => self::isValidIdentifier($segment));

    }

    /**
     * Validates `{name}` placeholders and ensures their names are unique.
     *
     * @param string $path Route path to inspect.
     * @return bool True when all braces belong to valid, unique parameters.
     */
    private static function hasValidPathParameters(string $path): bool {
        preg_match_all('/\{([^{}]*)\}/', $path, $matches);
        $withoutParameters = preg_replace('/\{[^{}]*\}/', '', $path);

        if ($withoutParameters === null
            || str_contains($withoutParameters, '{')
            || str_contains($withoutParameters, '}')) {
            return false;
        }

        $names = [];
        foreach ($matches[1] as $name) {
            if (!self::isValidIdentifier($name) || isset($names[$name])) {
                return false;
            }

            $names[$name] = true;
        }

        return true;
    }

    /**
     * Checks whether a string is a valid PHP identifier.
     *
     * @param string $identifier Candidate identifier.
     * @return bool True when the string matches PHP's identifier character rules.
     */
    private static function isValidIdentifier(string $identifier): bool {
        return preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $identifier) === 1;
    }

    /**
     * Parses and validates a middleware declaration.
     *
     * @param string $middleware Middleware in `ClassName@method` format.
     * @return array{class: string, method: string} Parsed class and method names.
     * @throws InvalidArgumentException If the declaration is malformed.
     */
    public static function parseMiddleware(string $middleware): array {
        $separator = strpos($middleware, '@');
        if ($separator === false || strpos($middleware, '@', $separator + 1) !== false) {
            throw new InvalidArgumentException('Middleware must contain exactly one "@" separator.');
        }

        $class = substr($middleware, 0, $separator);
        $method = substr($middleware, $separator + 1);

        if (!self::isValidClassName($class) || !self::isValidIdentifier($method)) {
            throw new InvalidArgumentException('Middleware must use a valid ClassName@method format.');
        }

        return ['class' => $class, 'method' => $method];
    }
}

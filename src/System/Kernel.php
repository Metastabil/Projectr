<?php
declare(strict_types=1);

namespace Projectr\Core\System;

use Dotenv\Dotenv;
use InvalidArgumentException;
use Projectr\Core\Http\Router;
use RuntimeException;

/**
 * Loads the environment, configuration, and session before registering routes and dispatching requests.
 */
final class Kernel {
    private static bool $booted = false;

    /**
     * Boots the application and dispatches the current HTTP request.
     *
     * @return void
     * @throws RuntimeException If application or route configuration cannot be loaded or the session cannot start.
     * @throws InvalidArgumentException If a configured route is invalid.
     * @throws \Dotenv\Exception\InvalidFileException If `.env` contains invalid syntax.
     */
    public static function run(): void {
        self::boot();
        Router::run();
    }

    /**
     * Loads `.env`, application configuration, and the session, then registers routes from `config/routes.php`.
     *
     * Calling this method more than once in the same process does not register routes again.
     *
     * @return void
     * @throws RuntimeException If the application directory, route configuration, or session is invalid.
     * @throws InvalidArgumentException If a route definition is invalid or duplicates a route.
     * @throws \Dotenv\Exception\InvalidFileException If `.env` contains invalid syntax.
     */
    public static function boot(): void {
        if (self::$booted) {
            return;
        }

        $projectRoot = realpath(dirname(__DIR__, 2));
        if ($projectRoot === false) {
            throw new RuntimeException('Unable to resolve the application directory.');
        }

        Dotenv::createImmutable($projectRoot)->safeLoad();
        Config::load();
        Session::start();

        $routes = Config::get('routes', []);

        if (!is_array($routes) || !array_is_list($routes)) {
            throw new RuntimeException('Route configuration must be a list.');
        }

        foreach ($routes as $route) {
            if (!is_array($route)) {
                throw new RuntimeException('Each configured route must be an array.');
            }

            $method = $route['method'] ?? null;
            $path = $route['path'] ?? null;
            $action = $route['action'] ?? null;
            $middlewares = $route['middlewares'] ?? [];

            if (
                !is_string($method)
                || !is_string($path)
                || !is_string($action)
                || !is_array($middlewares)
                || !array_is_list($middlewares)
            ) {
                throw new RuntimeException('Configured route fields have invalid types.');
            }

            Router::register($method, $path, $action, $middlewares);
        }

        self::$booted = true;
    }
}

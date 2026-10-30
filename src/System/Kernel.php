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

        Router::registerMany($routes);

        self::$booted = true;
    }
}

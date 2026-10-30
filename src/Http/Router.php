<?php
declare(strict_types=1);

namespace Projectr\Core\Http;

use InvalidArgumentException;
use Projectr\Core\Infrastructure\Logger;
use Projectr\Core\System\Config;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Registers routes and dispatches matching HTTP requests.
 *
 * Routes are created through the static {@see Route::define()} API and stored
 * as validated arrays until the current request is dispatched.
 *
 * @phpstan-type RouteDefinition array{
 *     method: string,
 *     path: string,
 *     controller: string,
 *     action: string,
 *     middlewares: list<array{class: string, method: string}>
 * }
 */
final class Router {
    private const CONTROLLER_NAMESPACE = 'App\\Controllers';
    private const MIDDLEWARE_NAMESPACE = 'App\\Middlewares';

    /** @var list<RouteDefinition> */
    private static array $routes = [];

    /**
     * Prevents creation of instances because all router operations are static.
     */
    private function __construct() {
    }

    /**
     * Registers a POST route.
     *
     * @param string $path Absolute route path, optionally containing `{name}` parameters.
     * @param string $action Controller declaration in `Controller@method` format.
     * @param list<string> $middlewares Middleware declarations in `ClassName@method` format.
     * @return RouteDefinition The registered route definition.
     * @throws InvalidArgumentException If the route or middleware is invalid or duplicates a route.
     */
    public static function post(string $path, string $action, array $middlewares = []): array {
        return self::add('POST', $path, $action, $middlewares);
    }

    /**
     * Registers a route for an arbitrary HTTP method.
     *
     * @param string $method HTTP method.
     * @param string $path Absolute route path, optionally containing `{name}` parameters.
     * @param string $action Controller declaration in `Controller@method` format.
     * @param list<string> $middlewares Middleware declarations in `ClassName@method` format.
     * @return RouteDefinition The registered route definition.
     * @throws InvalidArgumentException If the route or middleware is invalid or duplicated.
     */
    public static function register(string $method, string $path, string $action, array $middlewares = []): array {
        return self::add($method, $path, $action, $middlewares);
    }

    /**
     * Registers a list of route definitions atomically.
     *
     * Routes are validated against each other and the existing registry before
     * any of them are committed.
     *
     * @param list<array{method: string, path: string, action: string, middlewares?: list<string>}> $definitions
     * @return void
     * @throws InvalidArgumentException If a definition is malformed, invalid, or duplicated.
     */
    public static function registerMany(array $definitions): void {
        if (!array_is_list($definitions)) {
            throw new InvalidArgumentException('Route definitions must be provided as a list.');
        }

        $routes = self::$routes;
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new InvalidArgumentException('Each route definition must be an array.');
            }

            $method = $definition['method'] ?? null;
            $path = $definition['path'] ?? null;
            $action = $definition['action'] ?? null;
            $middlewares = $definition['middlewares'] ?? [];

            if (
                !is_string($method)
                || !is_string($path)
                || !is_string($action)
                || !is_array($middlewares)
                || !array_is_list($middlewares)
            ) {
                throw new InvalidArgumentException('Route definition fields have invalid types.');
            }

            $route = self::createRoute($method, $path, $action, $middlewares);
            self::assertRouteIsUnique($route, $routes);
            $routes[] = $route;
        }

        self::$routes = $routes;
    }

    /**
     * Registers a route by parsing its controller action and normalizing handler classes.
     *
     * Bare controller and middleware class names are resolved in `App\\Controllers`
     * and `App\\Middlewares`; namespaced names are used as supplied.
     *
     * @param string $method HTTP method.
     * @param string $path Absolute route path.
     * @param string $action Controller declaration in `Controller@method` format.
     * @param list<string> $middlewares Middleware declarations in `ClassName@method` format.
     * @return RouteDefinition The registered route definition.
     * @throws InvalidArgumentException If any route value is invalid or a duplicate is registered.
     */
    private static function add(
        string $method,
        string $path,
        string $action,
        array  $middlewares = [],
    ): array {
        $route = self::createRoute($method, $path, $action, $middlewares);
        self::assertRouteIsUnique($route, self::$routes);

        self::$routes[] = $route;

        return $route;
    }

    /**
     * Creates a validated route definition through the shared `Route` API.
     *
     * @param string $method HTTP method.
     * @param string $path Absolute route path.
     * @param string $action Controller declaration in `Controller@method` format.
     * @param list<string> $middlewares Middleware declarations in `ClassName@method` format.
     * @return RouteDefinition Validated route data.
     * @throws InvalidArgumentException If any route value is invalid.
     */
    private static function createRoute(
        string $method,
        string $path,
        string $action,
        array $middlewares = [],
    ): array {
        [$controller, $controllerAction] = self::splitDeclaration($action, 'Route action');
        $controller = self::resolveClassName($controller, self::CONTROLLER_NAMESPACE);

        if (!array_is_list($middlewares)) {
            throw new InvalidArgumentException('Middleware declarations must be provided as a list.');
        }

        $resolvedMiddlewares = [];
        foreach ($middlewares as $middleware) {
            if (!is_string($middleware)) {
                throw new InvalidArgumentException('Each middleware declaration must be a string.');
            }

            $parts = Route::parseMiddleware($middleware);
            $middlewareClass = self::resolveClassName($parts['class'], self::MIDDLEWARE_NAMESPACE);
            $resolvedMiddlewares[] = $middlewareClass . '@' . $parts['method'];
        }

        return Route::define($method, $path, $controller, $controllerAction, $resolvedMiddlewares);
    }

    /**
     * Rejects a route that duplicates an already prepared method and path.
     *
     * @param RouteDefinition $route Route candidate.
     * @param list<RouteDefinition> $registeredRoutes Routes already registered or pending.
     * @return void
     * @throws InvalidArgumentException If a route for the same method and path exists.
     */
    private static function assertRouteIsUnique(array $route, array $registeredRoutes): void {
        $normalizedPath = self::normalizeRoutePath($route['path']);
        foreach ($registeredRoutes as $registeredRoute) {
            if (
                $registeredRoute['method'] === $route['method']
                && self::normalizeRoutePath($registeredRoute['path']) === $normalizedPath
            ) {
                throw new InvalidArgumentException('A route for this HTTP method and path is already registered.');
            }
        }
    }

    /**
     * Splits a `ClassName@method` declaration and rejects missing or extra separators.
     *
     * @param string $declaration Declaration to split.
     * @param string $label Name used in the validation error.
     * @return array{string, string} Class name and method name.
     * @throws InvalidArgumentException If the declaration does not contain exactly one `@`.
     */
    private static function splitDeclaration(string $declaration, string $label): array {
        $separator = strpos($declaration, '@');
        if ($separator === false || strpos($declaration, '@', $separator + 1) !== false) {
            throw new InvalidArgumentException($label . ' must contain exactly one "@" separator.');
        }

        return [substr($declaration, 0, $separator), substr($declaration, $separator + 1)];
    }

    /**
     * Resolves an unqualified handler class into its application namespace.
     *
     * @param string $className Handler class name.
     * @param string $defaultNamespace Namespace used for unqualified names.
     * @return string Resolved class name without a leading namespace separator.
     */
    private static function resolveClassName(string $className, string $defaultNamespace): string {
        if (str_starts_with($className, '\\')) {
            return substr($className, 1);
        }

        if (str_contains($className, '\\')) {
            return $className;
        }

        return $defaultNamespace . '\\' . $className;
    }

    /**
     * Normalizes the route path for duplicate registration checks.
     *
     * @param string $path Route path.
     * @return string Path with leading and trailing slashes removed, except root.
     */
    private static function normalizeRoutePath(string $path): string {
        $normalizedPath = trim($path, '/');
        $normalizedPath = preg_replace('/\{[^{}]+\}/', '{}', $normalizedPath) ?? $normalizedPath;

        return $normalizedPath !== '' ? $normalizedPath : '/';
    }

    /**
     * Registers a PUT route.
     *
     * @param string $path Absolute route path, optionally containing `{name}` parameters.
     * @param string $action Controller declaration in `Controller@method` format.
     * @param list<string> $middlewares Middleware declarations in `ClassName@method` format.
     * @return RouteDefinition The registered route definition.
     * @throws InvalidArgumentException If the route or middleware is invalid or duplicates a route.
     */
    public static function put(string $path, string $action, array $middlewares = []): array {
        return self::add('PUT', $path, $action, $middlewares);
    }

    /**
     * Registers a PATCH route.
     *
     * @param string $path Absolute route path, optionally containing `{name}` parameters.
     * @param string $action Controller declaration in `Controller@method` format.
     * @param list<string> $middlewares Middleware declarations in `ClassName@method` format.
     * @return RouteDefinition The registered route definition.
     * @throws InvalidArgumentException If the route or middleware is invalid or duplicates a route.
     */
    public static function patch(string $path, string $action, array $middlewares = []): array {
        return self::add('PATCH', $path, $action, $middlewares);
    }

    /**
     * Registers a DELETE route.
     *
     * @param string $path Absolute route path, optionally containing `{name}` parameters.
     * @param string $action Controller declaration in `Controller@method` format.
     * @param list<string> $middlewares Middleware declarations in `ClassName@method` format.
     * @return RouteDefinition The registered route definition.
     * @throws InvalidArgumentException If the route or middleware is invalid or duplicates a route.
     */
    public static function delete(string $path, string $action, array $middlewares = []): array {
        return self::add('DELETE', $path, $action, $middlewares);
    }

    /**
     * Matches the current request to a registered route and runs its middleware and controller.
     *
     * Returns an HTTP 400, 404, 405, or 500 response when the request cannot be dispatched.
     *
     * @return void
     */
    public static function run(): void {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if (!is_string($method) || !self::isValidHttpMethod($method)) {
                self::badRequest();
                return;
            }

            $requestPath = self::getRequestPath();
            if ($requestPath === null) {
                self::badRequest();
                return;
            }

            $requestMethod = strtoupper($method);
            $allowedMethods = [];

            foreach (self::$routes as $route) {
                $parameters = self::matchRoute($route['path'], $requestPath);
                if ($parameters === false) {
                    continue;
                }

                $allowedMethods[$route['method']] = true;
                if ($route['method'] !== $requestMethod) {
                    continue;
                }

                self::executeRoute($route, $parameters);
                return;
            }

            if ($allowedMethods !== []) {
                self::methodNotAllowed(array_keys($allowedMethods));
                return;
            }

            self::notFound();
        } catch (Throwable $exception) {
            self::logException($exception);
            http_response_code(500);
            echo '500 Internal Server Error';
        }
    }

    /**
     * Validates an HTTP method token.
     *
     * @param string $method Candidate HTTP method.
     * @return bool True when the value contains only valid token characters.
     */
    private static function isValidHttpMethod(string $method): bool {
        return preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', $method) === 1;
    }

    /**
     * Sends a 400 response for an invalid request target or method.
     *
     * @return void
     */
    private static function badRequest(): void {
        http_response_code(400);
        echo '400 Bad Request';
    }

    /**
     * Returns the request path after removing the configured base path.
     *
     * Query strings are excluded. Malformed, absolute-form, or non-origin-form request targets
     * or fall outside the configured base path return `null` so the caller can reject them with HTTP 400.
     *
     * @return string|null Normalized request path, or null when the request target is malformed.
     */
    private static function getRequestPath(): ?string {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        if (!is_string($requestUri) || preg_match('/[\x00-\x20\x7F]/', $requestUri) === 1) {
            return null;
        }

        $uriParts = parse_url($requestUri);
        if (
            $uriParts === false
            || isset($uriParts['scheme'])
            || isset($uriParts['host'])
            || isset($uriParts['fragment'])
            || !isset($uriParts['path'])
            || !is_string($uriParts['path'])
        ) {
            return null;
        }

        $path = $uriParts['path'];
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        $baseUrlParts = parse_url(self::baseURL());
        if ($baseUrlParts === false) {
            return null;
        }

        $basePath = $baseUrlParts['path'] ?? '';
        if (!is_string($basePath)) {
            return null;
        }

        $basePrefix = '/' . trim($basePath, '/');
        if ($basePrefix !== '/') {
            $basePrefix = rtrim($basePrefix, '/');
            if ($path === $basePrefix) {
                $path = '/';
            } elseif (str_starts_with($path, $basePrefix . '/')) {
                $path = substr($path, strlen($basePrefix));
            } else {
                return null;
            }
        }

        return $path;
    }

    /**
     * Builds a URL from the configured application base URL and a relative URI.
     *
     * The base URL is read from `BASE_URL` or the `app.baseUrl` configuration key.
     *
     * @param string $uri Optional URI to append to the base URL.
     * @return string The configured base URL, optionally followed by the URI.
     * @throws RuntimeException If the configured base URL is not a string.
     * @throws InvalidArgumentException If the URI contains control characters or spaces.
     */
    public static function baseURL(string $uri = ''): string {
        $configuredBaseUrl = $_ENV['BASE_URL'] ?? Config::get('app.baseUrl', '');
        if (!is_string($configuredBaseUrl) || preg_match('/[\x00-\x20\x7F]/', $configuredBaseUrl) === 1) {
            throw new RuntimeException('Invalid application base URL.');
        }

        $baseUrl = rtrim($configuredBaseUrl, '/');
        if ($uri === '') {
            return $baseUrl;
        }

        if (preg_match('/[\x00-\x20\x7F]/', $uri) === 1) {
            throw new InvalidArgumentException('URI must not contain control characters or spaces.');
        }

        return $baseUrl . '/' . ltrim($uri, '/');
    }

    /**
     * Registers a GET route.
     *
     * @param string $path Absolute route path, optionally containing `{name}` parameters.
     * @param string $action Controller declaration in `Controller@method` format.
     * @param list<string> $middlewares Middleware declarations in `ClassName@method` format.
     * @return RouteDefinition The registered route definition.
     * @throws InvalidArgumentException If the route or middleware is invalid or duplicates a route.
     */
    public static function get(string $path, string $action, array $middlewares = []): array {
        return self::add('GET', $path, $action, $middlewares);
    }

    /**
     * Matches a request path to a route and returns placeholder values as strings.
     *
     * Route parameters use the `{name}` syntax. Route definitions are validated by
     * {@see Route::define()} before they reach this method.
     *
     * @param string $routePath Registered route path.
     * @param string $requestPath Incoming request path.
     * @return array<string, string>|false Parameter values in declaration order, or false on no match.
     */
    private static function matchRoute(string $routePath, string $requestPath): array|false {
        $routePath = trim($routePath, '/');
        $requestPath = trim($requestPath, '/');

        preg_match_all(
            '/\{([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\}/',
            $routePath,
            $placeholders,
            PREG_OFFSET_CAPTURE,
        );

        $routeRegex = '';
        $parameterNames = [];
        $offset = 0;

        foreach ($placeholders[0] as $index => [$placeholder, $position]) {
            $routeRegex .= preg_quote(substr($routePath, $offset, $position - $offset), '~');
            $routeRegex .= '([^/]+)';
            $parameterNames[] = $placeholders[1][$index][0];
            $offset = $position + strlen($placeholder);
        }

        $routeRegex .= preg_quote(substr($routePath, $offset), '~');
        if (preg_match('~^' . $routeRegex . '$~D', $requestPath, $matches) !== 1) {
            return false;
        }

        array_shift($matches);
        $parameters = [];
        foreach ($parameterNames as $index => $name) {
            $parameters[$name] = $matches[$index];
        }

        return $parameters;
    }

    /**
     * Instantiates registered middleware and the controller, then dispatches the action.
     *
     * Only public, non-static, non-magic instance methods are callable as handlers.
     * A middleware may return `false` to stop the request before the controller runs;
     * when it has not selected a status code, the router responds with HTTP 403.
     * Parameter values are passed positionally in the order they appear in the route.
     *
     * @param RouteDefinition $route Matched route definition.
     * @param array<string, string> $parameters Matched route parameters.
     * @return void
     * @throws RuntimeException If a handler class or method is unavailable or not callable.
     */
    private static function executeRoute(array $route, array $parameters): void {
        foreach ($route['middlewares'] as $middleware) {
            $middlewareInstance = self::instantiateHandler($middleware['class'], $middleware['method']);
            if ($middlewareInstance->{$middleware['method']}() === false) {
                if (http_response_code() === 200) {
                    http_response_code(403);
                }

                return;
            }
        }

        $controller = self::instantiateHandler($route['controller'], $route['action']);
        $controller->{$route['action']}(...array_values($parameters));
    }

    /**
     * Creates a handler after checking that it exposes a public instance method.
     *
     * @param string $className Handler class name.
     * @param string $methodName Handler method name.
     * @return object Instantiated handler.
     * @throws RuntimeException If the class or method cannot safely be dispatched.
     */
    private static function instantiateHandler(string $className, string $methodName): object {
        if (str_starts_with($methodName, '__') || !class_exists($className)) {
            throw new RuntimeException('Route handler is unavailable.');
        }

        $class = new ReflectionClass($className);
        if (!$class->isInstantiable() || !$class->hasMethod($methodName)) {
            throw new RuntimeException('Route handler is unavailable.');
        }

        $method = new ReflectionMethod($className, $methodName);
        if (!$method->isPublic() || $method->isStatic()) {
            throw new RuntimeException('Route handler is not a public instance method.');
        }

        return $class->newInstance();
    }

    /**
     * Sends a 405 response and declares methods registered for the matched path.
     *
     * @param list<string> $allowedMethods Methods accepted for the matched path.
     * @return void
     */
    private static function methodNotAllowed(array $allowedMethods): void {
        sort($allowedMethods);
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
        }

        http_response_code(405);
        echo '405 Method Not Allowed';
    }

    /**
     * Sends a 404 response when no route matches the request path.
     *
     * @return void
     */
    private static function notFound(): void {
        http_response_code(404);
        echo '404 Not Found';
    }

    /**
     * Logs an exception without allowing logger failures to break the error response.
     *
     * @param Throwable $exception Exception raised during routing or dispatch.
     * @return void
     */
    private static function logException(Throwable $exception): void {
        try {
            Logger::write('[Router::run()] ' . $exception->getMessage(), 'error');
        } catch (Throwable $loggingException) {
            error_log('Router logging failed: ' . get_class($loggingException));
        }
    }
}

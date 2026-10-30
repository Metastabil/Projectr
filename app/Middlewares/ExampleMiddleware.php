<?php
declare(strict_types=1);

namespace App\Middlewares;

/**
 * Adds baseline response headers for the example route.
 */
final class ExampleMiddleware
{
    /**
     * Adds security headers and allows the request to continue.
     *
     * @return bool True to continue to the controller.
     */
    public function handle(): bool
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }

        return true;
    }
}

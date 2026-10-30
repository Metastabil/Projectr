<?php
declare(strict_types=1);

namespace App\Controllers;

/**
 * Handles the example home page route.
 */
final class HomeController
{
    /**
     * Returns a plain-text response confirming that the application is running.
     *
     * @return void
     */
    public function index(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8');
        }

        echo 'Projectr is running.' . PHP_EOL;
    }
}

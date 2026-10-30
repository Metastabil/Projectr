<?php

/**
 * General application configuration.
 *
 * Holds core app settings loaded at startup:
 * base URL, runtime environment, log directory, and locale.
 *
 * @author Julius Derigs <info@julius-derigs.de>
 * @version 1.0.0
 */

return [
    'baseUrl' => $_ENV['BASE_URL'] ?? 'http://localhost:8080/',
    'environment' => $_ENV['APP_ENV'] ?? 'production',
    'logDir' => $_ENV['APP_LOG_DIR'] ?? 'storage/logs',
    'locale' => $_ENV['APP_LOCALE'] ?? 'en_US',
];

<?php
declare(strict_types=1);

try {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/src/Helpers/core.php';

    \Projectr\Core\System\Kernel::run();
} catch (Throwable $exception) {
    error_log(sprintf(
        'Projectr bootstrap failed (%s): %s',
        get_class($exception),
        $exception->getMessage(),
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo '500 Internal Server Error';
}

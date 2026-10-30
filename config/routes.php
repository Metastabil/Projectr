<?php
declare(strict_types=1);

/**
 * HTTP route definitions consumed by Projectr\Core\Http\Router.
 *
 * Bare controller names resolve to the App\Controllers namespace. The example
 * expects a public `index()` method on App\Controllers\HomeController and a
 * public `handle()` method on App\Middlewares\ExampleMiddleware.
 *
 * @return list<array{
 *     method: string,
 *     path: string,
 *     action: string,
 *     middlewares?: list<string>
 * }>
 */
return [
    [
        'method' => 'GET',
        'path' => '/',
        'action' => 'HomeController@index',
        'middlewares' => [
            'ExampleMiddleware@handle',
        ],
    ],
];

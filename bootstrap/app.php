<?php

// Register class aliases to override Spatie's models with our custom models in tests and legacy code
if (!class_exists('Spatie\Permission\Models\Role', false)) {
    class_alias(\App\Models\Role::class, 'Spatie\Permission\Models\Role');
}
if (!class_exists('Spatie\Permission\Models\Permission', false)) {
    class_alias(\App\Models\Permission::class, 'Spatie\Permission\Models\Permission');
}

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->alias([
            'role'               => \App\Http\Middleware\CustomRoleMiddleware::class,
            'permission'         => \App\Http\Middleware\CustomPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, \Throwable $exception, Request $request) {
            if (!app()->environment(['local', 'testing'])
                && in_array($response->getStatusCode(), [403, 404, 500, 503])
            ) {
                return Inertia::render('Error', ['status' => $response->getStatusCode()])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();

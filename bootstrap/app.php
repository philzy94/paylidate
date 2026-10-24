<?php

use App\Http\Middleware\ThirdPartyApiMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            // Routes reference controllers by short name ('Api\\AuthController@login'),
            // so the controller namespace is applied to both route files.
            Route::middleware('web')
                ->namespace('App\\Http\\Controllers')
                ->group(base_path('routes/web.php'));

            // routes/api.php declares its own "api" prefix and per-route middleware.
            Route::middleware('api')
                ->namespace('App\\Http\\Controllers')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'third_party_Api' => ThirdPartyApiMiddleware::class,
        ]);
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);

        // Structured context attached to every reported exception (logs and Sentry).
        $exceptions->context(fn () => array_filter([
            'user_id' => Auth::guard('api')->id(),
            'route' => request()->route()?->uri(),
            'method' => request()->method(),
        ]));
        $exceptions->dontFlash(['password', 'password_confirmation']);
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })
    ->create();

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\EnvironmentValidationServiceProvider::class,
        \App\Providers\PerformanceMonitoringServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \App\Http\Middleware\ResolveTenant::class,
            \App\Http\Middleware\EnsureUserEnabled::class,
            \App\Http\Middleware\LogSlowRequests::class,
        ]);
        
        $middleware->alias([
            'role' => \App\Http\Middleware\RequireRole::class,
            'dev' => \App\Http\Middleware\DevOnly::class,
            'require_module' => \App\Http\Middleware\RequireModule::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Exceptions\CropCycleClosedException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
        $exceptions->render(function (\App\Exceptions\CropCycleCloseException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })->create();

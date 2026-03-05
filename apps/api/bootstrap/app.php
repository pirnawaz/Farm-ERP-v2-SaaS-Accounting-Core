<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\AppServiceProvider::class,
        \App\Providers\EnvironmentValidationServiceProvider::class,
        \App\Providers\PerformanceMonitoringServiceProvider::class,
        \App\Providers\ConsoleSafetyServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Order: ClearCookieOnUnauthorized (wraps response) → RequestId → ResolveTenant → ... → (route: RequireRole/RequireModule)
        $middleware->api(prepend: [
            \App\Http\Middleware\ClearCookieOnUnauthorized::class,
            \App\Http\Middleware\RequestId::class,
            \App\Http\Middleware\ResolveTenant::class,
            \App\Http\Middleware\EnsureTenantActive::class,
            \App\Http\Middleware\ResolveTenantAuth::class,
            \App\Http\Middleware\RequirePasswordUpdated::class,
            \App\Http\Middleware\ResolvePlatformAuth::class,
            \App\Http\Middleware\EnsureUserEnabled::class,
            \App\Http\Middleware\LogSlowRequests::class,
            \App\Http\Middleware\RequestTimingLogger::class,
        ]);
        
        $middleware->alias([
            'role' => \App\Http\Middleware\RequireRole::class,
            'dev' => \App\Http\Middleware\DevOnly::class,
            'require_module' => \App\Http\Middleware\RequireModule::class, // EnsureModuleLicensed (route-level)
            'platform_admin_or_impersonation' => \App\Http\Middleware\AllowPlatformAdminOrImpersonationCookie::class,
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
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($request->expectsJson()) {
                $message = $e->getMessage();
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
                    $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
                    $message = 'Too many attempts. Try again in ' . ($retryAfter ?? '60') . ' seconds.';
                }
                return response()->json(['message' => $message], $e->getStatusCode());
            }
        });
    })->create();

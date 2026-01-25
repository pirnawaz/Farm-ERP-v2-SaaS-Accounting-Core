<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DevOnly
{
    /**
     * Handle an incoming request.
     * Blocks access if in production or if APP_DEBUG is false.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Block if in production environment
        if (app()->environment('production')) {
            return response()->json([
                'error' => 'Dev bootstrap disabled in production'
            ], 403);
        }

        // Block if APP_DEBUG is not true
        if (config('app.debug') !== true) {
            return response()->json([
                'error' => 'Dev bootstrap disabled. Enable APP_DEBUG to use this feature.'
            ], 403);
        }

        return $next($request);
    }
}

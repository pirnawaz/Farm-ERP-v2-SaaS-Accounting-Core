<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserEnabled
{
    /**
     * If X-User-Id is present, ensure the user exists and is_enabled.
     * Skip for health, dev, and when X-User-Id is absent (dev/compat).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health') || $request->is('api/dev/*')) {
            return $next($request);
        }

        $userId = $request->header('X-User-Id');
        if (!$userId || $userId === '') {
            return $next($request);
        }

        $user = User::find($userId);
        if (!$user || !$user->is_enabled) {
            return response()->json(['message' => 'User is disabled or not found.'], 403);
        }

        return $next($request);
    }
}

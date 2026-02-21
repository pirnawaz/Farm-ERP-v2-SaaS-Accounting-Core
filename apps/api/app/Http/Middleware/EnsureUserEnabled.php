<?php

namespace App\Http\Middleware;

use App\Helpers\DevIdentity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserEnabled
{
    /**
     * When user identity is present, ensure the user exists and is_enabled.
     * Identity is taken from request attributes (cookie/auth) or, when dev identity
     * is allowed, from X-User-Id header. Skip for health and dev routes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health') || $request->is('api/dev/*')) {
            return $next($request);
        }

        $userId = DevIdentity::isAllowed()
            ? ($request->attributes->get('user_id') ?? $request->header('X-User-Id'))
            : $request->attributes->get('user_id');
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

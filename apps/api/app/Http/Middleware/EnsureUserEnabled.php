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
     * Skip entirely for platform routes (api/platform/*): platform auth uses Identity
     * only and must not require a User record; the client may send X-User-Id with
     * identity id, which would otherwise trigger User::find() and 403.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/health') || $request->is('api/dev/*')) {
            return $next($request);
        }

        if ($request->is('api/platform/*')) {
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

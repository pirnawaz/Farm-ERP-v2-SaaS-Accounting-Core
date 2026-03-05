<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordUpdated
{
    private const ALLOWED_PATHS = [
        'api/auth/me',
        'api/auth/whoami',
        'api/auth/logout',
        'api/auth/logout-all',
        'api/auth/complete-first-login-password',
    ];

    /**
     * If the authenticated user has must_change_password, allow only me, logout, complete-first-login-password.
     * Otherwise return 403 with message "Password update required".
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->attributes->get('user_id');
        if (!$userId) {
            return $next($request);
        }

        $path = $request->path();
        foreach (self::ALLOWED_PATHS as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed . '?')) {
                return $next($request);
            }
        }

        $user = User::find($userId);
        if (!$user || !$user->must_change_password) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Password update required',
            'error' => 'password_update_required',
        ], 403);
    }
}

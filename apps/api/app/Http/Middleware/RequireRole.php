<?php

namespace App\Http\Middleware;

use App\Helpers\DevIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /**
     * Handle an incoming request.
     * When dev identity is disabled (production), only role from request attributes
     * (e.g. set by ResolvePlatformAuth from cookie) is trusted; header-only requests fail (401).
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $userRole = DevIdentity::isAllowed()
            ? ($request->attributes->get('user_role') ?? $request->header('X-User-Role'))
            : $request->attributes->get('user_role');

        if (!$userRole) {
            return response()->json([
                'error' => 'Authentication required'
            ], 401);
        }

        if (!in_array($userRole, $roles)) {
            return response()->json([
                'error' => 'Insufficient permissions'
            ], 403);
        }

        $request->attributes->set('user_role', $userRole);
        if (!$request->attributes->has('user_id') && DevIdentity::isAllowed()) {
            $request->attributes->set('user_id', $request->header('X-User-Id') ?? '');
        }

        return $next($request);
    }
}

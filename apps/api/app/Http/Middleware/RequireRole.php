<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /**
     * Handle an incoming request.
     * 
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // For now, we'll use a simple approach with X-User-Role header
        // In production, this should be replaced with proper JWT/OAuth authentication
        $userRole = $request->header('X-User-Role');

        if (!$userRole) {
            return response()->json([
                'error' => 'X-User-Role header is required'
            ], 401);
        }

        if (!in_array($userRole, $roles)) {
            return response()->json([
                'error' => 'Insufficient permissions'
            ], 403);
        }

        $request->attributes->set('user_role', $userRole);

        return $next($request);
    }
}

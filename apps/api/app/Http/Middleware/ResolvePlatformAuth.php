<?php

namespace App\Http\Middleware;

use App\Helpers\DevIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePlatformAuth
{
    /**
     * For api/platform/*: resolve identity from cookie or (when dev identity allowed) from headers.
     * When dev identity is disabled (production), client-supplied X-User-Id / X-User-Role are
     * ignored; only cookie-based auth is trusted. Header-only requests then fail downstream (401/403).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('api/platform/*')) {
            return $next($request);
        }

        $trustHeaders = DevIdentity::isAllowed();
        if (!$trustHeaders) {
            $request->headers->remove('X-User-Id');
            $request->headers->remove('X-User-Role');
        }

        $userId = $request->header('X-User-Id');
        $userRole = $request->header('X-User-Role');
        if ($userId && $userRole) {
            $request->attributes->set('user_id', $userId);
            $request->attributes->set('user_role', $userRole);
            return $next($request);
        }

        $token = $request->cookie('farm_erp_auth_token');
        if (!$token) {
            return $next($request);
        }

        try {
            $data = json_decode(base64_decode($token), true);
            if (!is_array($data) || !isset($data['expires_at']) || $data['expires_at'] < now()->timestamp) {
                return $next($request);
            }
            $userId = $data['user_id'] ?? '';
            $userRole = $data['role'] ?? '';
            $request->headers->set('X-User-Id', $userId);
            $request->headers->set('X-User-Role', $userRole);
            $request->attributes->set('user_id', $userId);
            $request->attributes->set('user_role', $userRole);
        } catch (\Throwable $e) {
            // ignore
        }

        return $next($request);
    }
}

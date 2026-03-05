<?php

namespace App\Http\Middleware;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Helpers\DevIdentity;
use App\Models\User;
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

        $token = $request->cookie(AuthCookie::NAME);
        // In testing, also accept the same token via Authorization so tests can forward cookie value
        if (!$token && app()->environment('testing')) {
            $token = $request->bearerToken();
        }
        if (!$token) {
            return $next($request);
        }

        $data = AuthToken::parse($token);
        if (!$data) {
            return $next($request);
        }
        if (array_key_exists('tenant_id', $data) && $data['tenant_id'] !== null) {
            return $next($request);
        }
        $user = User::find($data['user_id'] ?? null);
        if (!$user || !$user->is_enabled) {
            return $next($request);
        }
        $version = (int) ($data['v'] ?? $data['token_version'] ?? 1);
        if ($user->token_version !== $version) {
            return $next($request);
        }
        $userId = $data['user_id'] ?? '';
        $userRole = $data['role'] ?? '';
        $request->headers->set('X-User-Id', $userId);
        $request->headers->set('X-User-Role', $userRole);
        $request->attributes->set('user_id', $userId);
        $request->attributes->set('user_role', $userRole);

        return $next($request);
    }
}

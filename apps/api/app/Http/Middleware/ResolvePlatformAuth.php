<?php

namespace App\Http\Middleware;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Helpers\DevIdentity;
use App\Models\Identity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePlatformAuth
{
    /**
     * For api/platform/*: resolve identity from cookie or (when dev identity allowed) from headers.
     * Supports both legacy (User) and identity-based (Identity) tokens.
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

        // Prefer cookie/bearer over X-User-Id so identity-based sessions never get user_id set to identity id.
        // The web client sends X-User-Id from localStorage (which is identity id in platform mode); we must not use it for actor attribution.
        $token = $request->cookie(AuthCookie::NAME);
        if (!$token && app()->environment('testing')) {
            $token = $request->bearerToken();
        }
        if ($token) {
            $data = AuthToken::parse($token);
            $isPlatformToken = $data && (!array_key_exists('tenant_id', $data) || $data['tenant_id'] === null);
            if ($isPlatformToken) {
                if (!empty($data['identity_id'])) {
                    $identity = Identity::find($data['identity_id']);
                    if (!$identity) {
                        return response()->json(['message' => 'Identity not found.'], 403);
                    }
                    if (!$identity->is_enabled) {
                        return response()->json(['message' => 'Account is disabled.'], 403);
                    }
                    if (!$identity->is_platform_admin) {
                        return response()->json(['message' => 'Not a platform admin.'], 403);
                    }
                    $version = (int) ($data['v'] ?? 1);
                    if ($identity->token_version !== $version) {
                        return response()->json(['message' => 'Session invalidated. Please log in again.'], 401);
                    }
                    $request->attributes->set('identity_id', $identity->id);
                    $request->attributes->set('identity', $identity);
                    $request->attributes->set('user_role', 'platform_admin');
                    $request->attributes->set('user_id', null);
                    $request->attributes->set('auth_mode', 'platform');
                    $request->headers->set('X-User-Role', 'platform_admin');
                    return $next($request);
                }

                $user = User::find($data['user_id'] ?? null);
                if ($user && $user->is_enabled) {
                    $version = (int) ($data['v'] ?? $data['token_version'] ?? 1);
                    if ($user->token_version === $version) {
                        $userId = $data['user_id'] ?? '';
                        $userRole = $data['role'] ?? '';
                        $request->headers->set('X-User-Id', $userId);
                        $request->headers->set('X-User-Role', $userRole);
                        $request->attributes->set('user_id', $userId);
                        $request->attributes->set('user_role', $userRole);
                        return $next($request);
                    }
                }
            }
        }

        // No valid cookie/bearer: fall back to headers (dev or legacy).
        $userId = $request->header('X-User-Id');
        $userRole = $request->header('X-User-Role');
        if ($userId && $userRole) {
            $request->attributes->set('user_id', $userId);
            $request->attributes->set('user_role', $userRole);
            return $next($request);
        }

        return $next($request);
    }
}

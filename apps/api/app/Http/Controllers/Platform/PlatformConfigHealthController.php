<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PlatformConfigHealthController extends Controller
{
    /**
     * Runtime config health for platform admin (subdomain/CORS/cookie).
     * GET /api/platform/config-health
     */
    public function __invoke(): JsonResponse
    {
        $authCookie = config('auth.auth_cookie', []);
        $rootDomain = config('app.root_domain');
        $sessionDomain = config('auth.auth_cookie.domain') ?? $authCookie['domain'] ?? env('SESSION_DOMAIN');

        return response()->json([
            'root_domain_set' => !empty($rootDomain),
            'root_domain' => $rootDomain,
            'session_domain_set' => !empty($sessionDomain),
            'session_domain' => $sessionDomain,
            'secure_cookie_on' => (bool) ($authCookie['secure'] ?? false),
            'same_site' => $authCookie['same_site'] ?? 'lax',
            'auth_token_ttl_hours' => (int) config('auth.auth_token_ttl_hours', 168),
        ]);
    }
}

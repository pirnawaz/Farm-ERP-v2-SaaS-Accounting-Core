<?php

namespace App\Http\Middleware;

use App\Helpers\DevIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows request when either:
 * - User has role platform_admin (from cookie or headers), or
 * - Request has valid impersonation cookie (so tenant app can call GET impersonation/status while impersonating), or
 * - Neither (so UI can call and get 200 { is_impersonating: false } without flashing 401).
 */
class AllowPlatformAdminOrImpersonationCookie
{
    private const IMPERSONATION_COOKIE = 'farm_erp_impersonation';

    public function handle(Request $request, Closure $next): Response
    {
        $value = $request->cookie(self::IMPERSONATION_COOKIE);
        if ($value) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && !empty($decoded['target_tenant_id'])) {
                return $next($request);
            }
        }

        $userRole = DevIdentity::isAllowed()
            ? ($request->attributes->get('user_role') ?? $request->header('X-User-Role'))
            : $request->attributes->get('user_role');

        if ($userRole === 'platform_admin') {
            return $next($request);
        }

        // Allow through so controller can return { is_impersonating: false } (read-only status).
        return $next($request);
    }
}

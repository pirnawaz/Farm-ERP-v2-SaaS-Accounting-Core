<?php

namespace App\Http\Controllers\Platform;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Http\Controllers\Controller;
use App\Models\IdentityAuditLog;
use App\Models\ImpersonationAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IdentityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class ImpersonationController extends Controller
{
    private const COOKIE_NAME = 'farm_erp_impersonation';
    private const PLATFORM_SAVED_COOKIE = 'farm_erp_platform_saved';
    private const COOKIE_DAYS = 1;

    /**
     * Get current impersonation state (from cookie). Platform admin only.
     * GET /api/platform/impersonation
     */
    public function status(Request $request): JsonResponse
    {
        $payload = $this->getImpersonationPayload($request);
        if (!$payload) {
            return response()->json(['impersonating' => false]);
        }
        $tenant = Tenant::find($payload['target_tenant_id']);
        $user = isset($payload['target_user_id'])
            ? User::find($payload['target_user_id'])
            : null;
        return response()->json([
            'impersonating' => true,
            'target_tenant_id' => $payload['target_tenant_id'],
            'target_tenant_name' => $tenant?->name,
            'target_user_id' => $payload['target_user_id'] ?? null,
            'target_user_email' => $user?->email,
        ]);
    }

    /**
     * Impersonation status for UI: is_impersonating + tenant/user objects.
     * Callable by platform_admin or when impersonation cookie is present (so tenant app can show banner).
     * GET /api/platform/impersonation/status
     */
    public function statusForUi(Request $request): JsonResponse
    {
        $payload = $this->getImpersonationPayload($request);
        if (!$payload) {
            return response()->json(['is_impersonating' => false]);
        }
        $tenant = Tenant::find($payload['target_tenant_id']);
        $user = isset($payload['target_user_id'])
            ? User::find($payload['target_user_id'])
            : null;
        return response()->json([
            'is_impersonating' => true,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
        ]);
    }

    /**
     * Start impersonation. Platform admin only. Saves platform token, sets tenant-scoped auth cookie with impersonator_user_id. Creates audit log.
     * POST /api/platform/impersonation/start
     * Body: { tenant_id: string, user_id?: string }
     */
    public function start(Request $request): JsonResponse
    {
        return $this->doStart($request, $request->validate([
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
        ]));
    }

    /**
     * Impersonate a tenant (route param). Platform admin only. Same as start with tenant from URL.
     * POST /api/platform/tenants/{tenant}/impersonate
     * Body: { user_id?: string }
     */
    public function impersonate(Request $request, string $tenant): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
        ]);
        $tenantModel = Tenant::where('id', $tenant)->first();
        if (!$tenantModel) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        return $this->doStart($request, [
            'tenant_id' => $tenantModel->id,
            'user_id' => $validated['user_id'] ?? null,
        ]);
    }

    private function doStart(Request $request, array $validated): JsonResponse
    {
        $actorUserId = $request->header('X-User-Id');
        if (!$actorUserId) {
            return response()->json(['error' => 'X-User-Id is required for impersonation'], 400);
        }

        // Prevent nesting: cannot start impersonation while already impersonating
        if ($this->getImpersonationPayload($request) !== null) {
            return response()->json([
                'message' => 'Already impersonating. Stop impersonation first.',
                'error' => 'impersonation_nesting_not_allowed',
            ], 409);
        }

        $platformToken = $request->cookie(AuthCookie::NAME);
        if (!$platformToken && app()->environment('testing') && $actorUserId) {
            $platformUser = User::find($actorUserId);
            $platformToken = $platformUser ? AuthToken::create($platformUser, null) : null;
        }
        if (!$platformToken) {
            return response()->json(['error' => 'Platform session required. Log in as platform admin first.'], 401);
        }
        $platformData = AuthToken::parse($platformToken);
        if (!$platformData || ($platformData['tenant_id'] ?? null) !== null || ($platformData['role'] ?? '') !== 'platform_admin') {
            return response()->json(['error' => 'Platform admin session required'], 403);
        }

        $targetTenantId = $validated['tenant_id'];
        $targetUserId = $validated['user_id'] ?? null;

        $targetUser = null;
        if ($targetUserId) {
            $targetUser = User::where('id', $targetUserId)->where('tenant_id', $targetTenantId)->first();
            if (!$targetUser) {
                return response()->json(['error' => 'Target user must belong to target tenant'], 422);
            }
            if (!$targetUser->is_enabled) {
                return response()->json([
                    'message' => 'The selected user is disabled and cannot be impersonated.',
                    'error' => 'user_disabled',
                ], 422);
            }
        } else {
            // Default: tenant_admin, then accountant, then operator, then any enabled user
            $targetUser = User::where('tenant_id', $targetTenantId)
                ->where('is_enabled', true)
                ->orderByRaw("CASE role WHEN 'tenant_admin' THEN 1 WHEN 'accountant' THEN 2 WHEN 'operator' THEN 3 ELSE 4 END")
                ->first();
            if (!$targetUser) {
                return response()->json([
                    'message' => 'No users exist in this tenant to impersonate.',
                    'error' => 'no_users_to_impersonate',
                ], 422);
            }
        }

        ImpersonationAuditLog::create([
            'actor_user_id' => $actorUserId,
            'target_tenant_id' => $targetTenantId,
            'target_user_id' => $targetUser->id,
            'action' => ImpersonationAuditLog::ACTION_START,
            'metadata' => [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        ]);
        IdentityAuditLogger::log(IdentityAuditLog::ACTION_IMPERSONATION_START, $targetTenantId, $actorUserId, ['target_user_id' => $targetUser->id], $request);

        $tenantToken = AuthToken::create($targetUser, $targetTenantId, $actorUserId, self::COOKIE_DAYS * 24);

        $authCookie = AuthCookie::make($tenantToken);
        $savedCookie = $this->makeSavedPlatformCookie($platformToken);
        $statusCookie = $this->makeImpersonationStatusCookie($targetTenantId, $targetUser->id);

        return response()->json([
            'message' => 'Impersonation started',
            'target_tenant_id' => $targetTenantId,
            'target_user_id' => $targetUser->id,
        ])->cookie($authCookie)->cookie($savedCookie)->cookie($statusCookie);
    }

    /**
     * Stop impersonation. Restores platform auth cookie; clears impersonation cookies. Creates audit log.
     * POST /api/platform/impersonation/stop
     * Optional body: { target_tenant_id } so STOP can be logged when cookie is missing (e.g. tests).
     */
    public function stop(Request $request): JsonResponse
    {
        $actorUserId = $request->header('X-User-Id');
        if (!$actorUserId) {
            $authToken = $request->cookie(AuthCookie::NAME);
            if ($authToken) {
                $data = AuthToken::parse($authToken);
                $actorUserId = $data['impersonator_user_id'] ?? null;
            }
        }
        $payload = $this->getImpersonationPayload($request);
        $targetTenantId = $payload['target_tenant_id'] ?? $request->input('target_tenant_id');
        $targetUserId = $payload['target_user_id'] ?? $request->input('target_user_id');

        $platformToken = $request->cookie(self::PLATFORM_SAVED_COOKIE);

        if ($targetTenantId) {
            ImpersonationAuditLog::create([
                'actor_user_id' => $actorUserId,
                'target_tenant_id' => $targetTenantId,
                'target_user_id' => $targetUserId,
                'action' => ImpersonationAuditLog::ACTION_STOP,
                'metadata' => [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ]);
            IdentityAuditLogger::log(IdentityAuditLog::ACTION_IMPERSONATION_STOP, $targetTenantId, $actorUserId, ['target_user_id' => $targetUserId], $request);
        }

        // Issue a fresh platform token (do not reuse stored token) and invalidate impersonation token
        $platformUser = $actorUserId ? User::whereNull('tenant_id')->where('role', 'platform_admin')->find($actorUserId) : null;
        if ($platformUser) {
            $freshPlatformToken = AuthToken::create($platformUser, null);
            $response = response()->json(['message' => 'Impersonation ended']);
            $response->cookie(Cookie::forget(self::COOKIE_NAME));
            $response->cookie(Cookie::forget(self::PLATFORM_SAVED_COOKIE));
            $response->cookie(AuthCookie::make($freshPlatformToken));
            return $response;
        }

        $response = response()->json(['message' => 'Impersonation ended']);
        $response->cookie(Cookie::forget(self::COOKIE_NAME));
        $response->cookie(Cookie::forget(self::PLATFORM_SAVED_COOKIE));
        if ($platformToken) {
            $response->cookie(AuthCookie::make($platformToken));
        } else {
            $response->cookie(AuthCookie::make('', true));
        }
        return $response;
    }

    /**
     * Force-stop impersonation. Clears impersonation cookies unconditionally; restores platform auth if possible.
     * Platform admin only. Use when normal stop fails (e.g. cookie/state mismatch). Always returns 200.
     * POST /api/platform/impersonation/force-stop
     */
    public function forceStop(Request $request): JsonResponse
    {
        $actorUserId = $request->header('X-User-Id');
        if (!$actorUserId) {
            $authToken = $request->cookie(AuthCookie::NAME);
            if ($authToken) {
                $data = AuthToken::parse($authToken);
                $actorUserId = $data['impersonator_user_id'] ?? null;
            }
        }
        $payload = $this->getImpersonationPayload($request);
        $targetTenantId = $payload['target_tenant_id'] ?? null;
        $targetUserId = $payload['target_user_id'] ?? null;

        ImpersonationAuditLog::create([
            'actor_user_id' => $actorUserId,
            'target_tenant_id' => $targetTenantId,
            'target_user_id' => $targetUserId,
            'action' => ImpersonationAuditLog::ACTION_STOP,
            'metadata' => [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'force_stop' => true,
            ],
        ]);
        if ($targetTenantId) {
            IdentityAuditLogger::log(IdentityAuditLog::ACTION_IMPERSONATION_STOP, $targetTenantId, $actorUserId, ['target_user_id' => $targetUserId, 'force_stop' => true], $request);
        }

        $response = response()->json(['message' => 'Impersonation ended']);
        $response->cookie(Cookie::forget(self::COOKIE_NAME));
        $response->cookie(Cookie::forget(self::PLATFORM_SAVED_COOKIE));

        $platformUser = $actorUserId ? User::whereNull('tenant_id')->where('role', 'platform_admin')->find($actorUserId) : null;
        if ($platformUser) {
            $freshPlatformToken = AuthToken::create($platformUser, null);
            $response->cookie(AuthCookie::make($freshPlatformToken));
        } else {
            $response->cookie(AuthCookie::make('', true));
        }
        return $response;
    }

    private function getImpersonationPayload(Request $request): ?array
    {
        $value = $request->cookie(self::COOKIE_NAME);
        if (!$value) {
            return null;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || empty($decoded['target_tenant_id'])) {
            return null;
        }
        return $decoded;
    }

    private function makeSavedPlatformCookie(string $platformToken): \Symfony\Component\HttpFoundation\Cookie
    {
        $config = config('auth.auth_cookie', []);
        $secure = $config['secure'] ?? (config('app.env') === 'production');
        $sameSite = $config['same_site'] ?? 'lax';
        $domain = $config['domain'] ?? null;
        return cookie(
            self::PLATFORM_SAVED_COOKIE,
            $platformToken,
            60 * 24 * self::COOKIE_DAYS,
            '/',
            $domain,
            $secure,
            true,
            false,
            $sameSite
        );
    }

    private function makeImpersonationStatusCookie(string $targetTenantId, string $targetUserId): \Symfony\Component\HttpFoundation\Cookie
    {
        $config = config('auth.auth_cookie', []);
        $secure = $config['secure'] ?? (config('app.env') === 'production');
        $sameSite = $config['same_site'] ?? 'lax';
        $domain = $config['domain'] ?? null;
        $value = json_encode([
            'target_tenant_id' => $targetTenantId,
            'target_user_id' => $targetUserId,
            'started_at' => now()->toIso8601String(),
        ]);
        return cookie(
            self::COOKIE_NAME,
            $value,
            60 * 24 * self::COOKIE_DAYS,
            '/',
            $domain,
            $secure,
            true,
            false,
            $sameSite
        );
    }
}

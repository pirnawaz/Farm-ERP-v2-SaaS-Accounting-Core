<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ImpersonationAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class ImpersonationController extends Controller
{
    private const COOKIE_NAME = 'farm_erp_impersonation';
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
     * Start impersonation. Platform admin only. Creates audit log.
     * POST /api/platform/impersonation/start
     * Body: { tenant_id: string, user_id?: string }
     */
    public function start(Request $request): JsonResponse
    {
        $actorUserId = $request->header('X-User-Id');
        if (!$actorUserId) {
            return response()->json(['error' => 'X-User-Id is required for impersonation'], 400);
        }

        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
        ]);

        $targetTenantId = $validated['tenant_id'];
        $targetUserId = $validated['user_id'] ?? null;

        // Ensure target user belongs to target tenant if provided
        if ($targetUserId) {
            $targetUser = User::where('id', $targetUserId)->where('tenant_id', $targetTenantId)->first();
            if (!$targetUser) {
                return response()->json(['error' => 'Target user must belong to target tenant'], 422);
            }
        }

        ImpersonationAuditLog::create([
            'actor_user_id' => $actorUserId,
            'target_tenant_id' => $targetTenantId,
            'target_user_id' => $targetUserId,
            'action' => ImpersonationAuditLog::ACTION_START,
            'metadata' => [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        ]);

        $cookieValue = json_encode([
            'target_tenant_id' => $targetTenantId,
            'target_user_id' => $targetUserId,
            'started_at' => now()->toIso8601String(),
        ]);
        $cookie = cookie(
            self::COOKIE_NAME,
            $cookieValue,
            60 * 24 * self::COOKIE_DAYS,
            '/',
            null,
            true,
            true
        );

        return response()->json([
            'message' => 'Impersonation started',
            'target_tenant_id' => $targetTenantId,
            'target_user_id' => $targetUserId,
        ])->cookie($cookie);
    }

    /**
     * Stop impersonation. Platform admin only. Creates audit log.
     * POST /api/platform/impersonation/stop
     * Optional body: { target_tenant_id } so STOP can be logged when cookie is missing (e.g. tests).
     */
    public function stop(Request $request): JsonResponse
    {
        $actorUserId = $request->header('X-User-Id');
        $payload = $this->getImpersonationPayload($request);

        $targetTenantId = $payload['target_tenant_id'] ?? $request->input('target_tenant_id');
        $targetUserId = $payload['target_user_id'] ?? $request->input('target_user_id');

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
        }

        $cookie = Cookie::forget(self::COOKIE_NAME);

        return response()->json(['message' => 'Impersonation ended'])->cookie($cookie);
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
}

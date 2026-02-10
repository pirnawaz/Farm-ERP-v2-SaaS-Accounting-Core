<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dev\E2ESeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class DevE2ESeedController extends Controller
{
    public function __construct(
        private E2ESeedService $seedService
    ) {}

    /**
     * POST /api/dev/e2e/seed
     * Idempotent E2E seed: ensure tenant, OPEN cycle, project, DRAFT + POSTED + reversal-ready operational records.
     * Body: { "tenant_id": "uuid optional", "tenant_name": "E2E Farm optional" }
     */
    public function seed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['nullable', 'uuid'],
            'tenant_name' => ['nullable', 'string', 'max:255'],
        ]);

        $tenantId = $validated['tenant_id'] ?? null;
        $tenantName = $validated['tenant_name'] ?? 'E2E Farm';

        try {
            $state = $this->seedService->seed($tenantId, $tenantName);
            return response()->json($state);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'E2E seed failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/dev/e2e/seed-state
     * Return current seed state without creating new records.
     * Query: tenant_id (optional), tenant_name (optional).
     */
    public function seedState(Request $request): JsonResponse
    {
        $tenantId = $request->query('tenant_id');
        $tenantName = $request->query('tenant_name', 'E2E Farm');

        if ($tenantId && !\Illuminate\Support\Str::isUuid($tenantId)) {
            return response()->json(['error' => 'Invalid tenant_id'], 422);
        }

        $state = $this->seedService->getSeedState($tenantId, $tenantName);
        if ($state === null) {
            return response()->json(['error' => 'No seed state found. Run POST /api/dev/e2e/seed first.'], 404);
        }
        return response()->json($state);
    }

    /**
     * POST /api/dev/e2e/auth-cookie
     * Dev-only: set farm_erp_auth_token httpOnly cookie so E2E can call API with cookie auth.
     * Body: { tenant_id: uuid, role: string, user_id: uuid }
     * Cookie format matches AuthController (base64 JSON with user_id, tenant_id, role, expires_at).
     */
    public function authCookie(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'role' => ['required', 'string', 'in:tenant_admin,accountant,operator,platform_admin'],
            'user_id' => ['required', 'uuid'],
        ]);

        $user = User::where('id', $validated['user_id'])
            ->where('tenant_id', $validated['tenant_id'])
            ->where('role', $validated['role'])
            ->first();

        if (!$user) {
            return response()->json(['error' => 'User not found for given tenant_id, role, user_id'], 404);
        }

        $token = base64_encode(json_encode([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'role' => $user->role,
            'email' => $user->email,
            'expires_at' => now()->addHours(24)->timestamp,
        ]));

        $cookie = cookie(
            'farm_erp_auth_token',
            $token,
            60 * 24,
            '/',
            null,
            false,
            true,
            false,
            'lax'
        );

        return response()->json(['ok' => true])->cookie($cookie);
    }
}

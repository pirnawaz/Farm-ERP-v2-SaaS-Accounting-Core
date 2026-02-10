<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
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

    /**
     * GET /api/dev/e2e/accounting-artifacts
     * Test-only: return posting group and ledger balance for a source (e.g. Payment).
     * Query: tenant_id (required), source_type (e.g. ADJUSTMENT), source_id (uuid).
     * Only available when APP_ENV=testing or local (dev middleware).
     */
    public function accountingArtifacts(Request $request): JsonResponse
    {
        $tenantId = $request->query('tenant_id');
        $sourceType = $request->query('source_type');
        $sourceId = $request->query('source_id');

        if (!$tenantId || !\Illuminate\Support\Str::isUuid($tenantId)) {
            return response()->json(['error' => 'Valid tenant_id required'], 422);
        }
        if (!$sourceType || !$sourceId || !\Illuminate\Support\Str::isUuid($sourceId)) {
            return response()->json(['error' => 'Valid source_type and source_id required'], 422);
        }

        $pg = PostingGroup::where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if (!$pg) {
            return response()->json([
                'posting_group_id' => null,
                'ledger_entry_count' => 0,
                'total_debit' => '0',
                'total_credit' => '0',
                'balanced' => true,
            ]);
        }

        $totals = LedgerEntry::where('posting_group_id', $pg->id)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(debit_amount), 0) as total_debit, COALESCE(SUM(credit_amount), 0) as total_credit')
            ->first();

        $totalDebit = (string) $totals->total_debit;
        $totalCredit = (string) $totals->total_credit;
        $balanced = abs((float) $totalDebit - (float) $totalCredit) < 0.01;

        return response()->json([
            'posting_group_id' => $pg->id,
            'ledger_entry_count' => (int) $totals->cnt,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced' => $balanced,
        ]);
    }
}

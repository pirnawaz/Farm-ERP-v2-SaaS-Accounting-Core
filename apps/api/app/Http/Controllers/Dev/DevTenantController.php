<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\Tenant;
use App\Models\Account;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class DevTenantController extends Controller
{
    /**
     * List all tenants
     * GET /api/dev/tenants
     */
    public function index(): JsonResponse
    {
        try {
            $tenants = Tenant::orderBy('created_at', 'desc')
                ->get(['id', 'name', 'status', 'created_at']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to list tenants',
                'message' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        return response()->json([
            'tenants' => $tenants->map(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'status' => $tenant->status,
                    'created_at' => $tenant->created_at?->toIso8601String() ?? '',
                ];
            }),
        ]);
    }

    /**
     * Create a new tenant
     * POST /api/dev/tenants
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
        ]);

        // Determine localization defaults based on country
        $country = isset($validated['country']) ? strtoupper(trim($validated['country'])) : '';
        $isPakistan = $country === 'PK' || $country === 'PAKISTAN';
        
        $tenantData = [
            'id' => (string) Str::uuid(),
            'name' => trim($validated['name']),
            'status' => 'active',
        ];

        if ($isPakistan) {
            $tenantData['currency_code'] = 'PKR';
            $tenantData['locale'] = 'en-PK';
            $tenantData['timezone'] = 'Asia/Karachi';
        } else {
            // Default to GB settings
            $tenantData['currency_code'] = 'GBP';
            $tenantData['locale'] = 'en-GB';
            $tenantData['timezone'] = 'Europe/London';
        }

        $tenant = DB::transaction(function () use ($tenantData) {
            $tenant = Tenant::create($tenantData);

            Farm::firstOrCreate(
                ['tenant_id' => $tenant->id],
                ['farm_name' => $tenant->name]
            );

            // Initialize system accounts for the new tenant (must succeed or entire create rolls back)
            $this->initializeSystemAccounts($tenant->id);

            return $tenant;
        });

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'created_at' => $tenant->created_at?->toIso8601String() ?? '',
            ],
        ], 201);
    }

    /**
     * Activate a tenant (set status to active)
     * POST /api/dev/tenants/{id}/activate
     */
    public function activate(string $id): JsonResponse
    {
        $tenant = Tenant::find($id);

        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant not found'
            ], 404);
        }

        $tenant->update(['status' => 'active']);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'created_at' => $tenant->created_at?->toIso8601String() ?? '',
            ],
        ]);
    }

    /**
     * Delete a tenant (dev only).
     * DELETE /api/dev/tenants/{id}
     * Works for tenants with no extra data (e.g. failed creates). Tenants with accounts/projects
     * etc. may hit FK constraints; use migrate:fresh for a full reset.
     */
    public function destroy(string $id): JsonResponse
    {
        $tenant = Tenant::find($id);

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        try {
            $tenant->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            $msg = $e->getMessage();
            if (($e->getCode() === '23503') || str_contains($msg, '23503') || str_contains($msg, 'foreign key')) {
                return response()->json([
                    'error' => 'Cannot delete tenant: it has linked data (accounts, projects, etc.). Use migrate:fresh for a full reset.',
                ], 409);
            }
            throw $e;
        }

        return response()->json(null, 204);
    }

    /**
     * Bootstrap missing system accounts for an existing tenant (dev only).
     * Use when GRN/Issue/Adjustment post fails with "System account ... not found".
     * POST /api/dev/tenants/{id}/bootstrap-accounts
     */
    public function bootstrapAccounts(string $id): JsonResponse
    {
        $tenant = Tenant::find($id);

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        SystemAccountsSeeder::runForTenant($tenant->id);

        return response()->json([
            'message' => 'System accounts bootstrapped. Missing accounts (e.g. INVENTORY_INPUTS) have been added.',
        ]);
    }

    /**
     * Initialize system accounts for a tenant.
     * These accounts are required for the accounting core to function.
     */
    private function initializeSystemAccounts(string $tenantId): void
    {
        $accounts = [
            // ASSET accounts
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'CASH',
                'name' => 'Cash',
                'type' => 'asset',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'AR',
                'name' => 'Accounts Receivable',
                'type' => 'asset',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'ADVANCE_HARI',
                'name' => 'Advance to Hari',
                'type' => 'asset',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'ADVANCE_VENDOR',
                'name' => 'Advance to Vendor',
                'type' => 'asset',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'LOAN_RECEIVABLE',
                'name' => 'Loan Receivable',
                'type' => 'asset',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'DUE_FROM_HARI',
                'name' => 'Due from Hari',
                'type' => 'asset',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PARTY_CONTROL_HARI',
                'name' => 'Party Control - Hari (sign-driven)',
                'type' => 'asset',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PARTY_CONTROL_LANDLORD',
                'name' => 'Party Control - Landlord (sign-driven)',
                'type' => 'asset',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PARTY_CONTROL_KAMDAR',
                'name' => 'Party Control - Kamdar (sign-driven)',
                'type' => 'asset',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PROFIT_DISTRIBUTION_CLEARING',
                'name' => 'Profit Distribution Clearing (settlement only)',
                'type' => 'equity',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'INVENTORY_INPUTS',
                'name' => 'Inventory / Inputs Stock',
                'type' => 'asset',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'INVENTORY_PRODUCE',
                'name' => 'Produce Inventory',
                'type' => 'asset',
                'is_system' => true,
            ],
            // LIABILITY accounts
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'AP',
                'name' => 'Accounts Payable',
                'type' => 'liability',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PAYABLE_HARI',
                'name' => 'Payable to Hari',
                'type' => 'liability',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PAYABLE_LANDLORD',
                'name' => 'Payable to Landlord',
                'type' => 'liability',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PAYABLE_KAMDAR',
                'name' => 'Payable to Kamdar',
                'type' => 'liability',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'LOAN_PAYABLE',
                'name' => 'Loans Payable',
                'type' => 'liability',
                'is_system' => true,
            ],
            // INCOME accounts
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PROJECT_REVENUE',
                'name' => 'Project Revenue',
                'type' => 'income',
                'is_system' => true,
            ],
            // EXPENSE accounts
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'EXP_SHARED',
                'name' => 'Shared Project Expense',
                'type' => 'expense',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'EXP_HARI_ONLY',
                'name' => 'Hari-only Project Expense',
                'type' => 'expense',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'EXP_LANDLORD_ONLY',
                'name' => 'Landlord-only Project Expense',
                'type' => 'expense',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'EXP_FARM_OVERHEAD',
                'name' => 'Farm Overhead Expense',
                'type' => 'expense',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'EXP_KAMDARI',
                'name' => 'Kamdari Expense',
                'type' => 'expense',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'STOCK_VARIANCE',
                'name' => 'Stock Variance / Shrinkage',
                'type' => 'expense',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'INPUTS_EXPENSE',
                'name' => 'Inputs Expense',
                'type' => 'expense',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'LABOUR_EXPENSE',
                'name' => 'Labour Expense',
                'type' => 'expense',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'WAGES_PAYABLE',
                'name' => 'Wages Payable',
                'type' => 'liability',
                'is_system' => true,
            ],
            // EQUITY/CLEARING accounts
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PROFIT_DISTRIBUTION',
                'name' => 'Profit Distribution / Settlement Clearing',
                'type' => 'equity',
                'is_system' => true,
            ],
        ];

        // Insert accounts, ignoring duplicates (based on tenant_id + code unique constraint)
        foreach ($accounts as $account) {
            DB::table('accounts')->insertOrIgnore($account);
        }
    }
}

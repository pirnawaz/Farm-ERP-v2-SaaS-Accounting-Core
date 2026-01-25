<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Account;
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
        $tenants = Tenant::orderBy('created_at', 'desc')
            ->get(['id', 'name', 'status', 'created_at']);

        return response()->json([
            'tenants' => $tenants->map(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'status' => $tenant->status,
                    'created_at' => $tenant->created_at->toIso8601String(),
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

        $tenant = Tenant::create($tenantData);

        // Initialize system accounts for the new tenant
        $this->initializeSystemAccounts($tenant->id);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'created_at' => $tenant->created_at->toIso8601String(),
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
                'created_at' => $tenant->created_at->toIso8601String(),
            ],
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
                'type' => 'ASSET',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'AR',
                'name' => 'Accounts Receivable',
                'type' => 'ASSET',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'ADVANCE_HARI',
                'name' => 'Advance to Hari',
                'type' => 'ASSET',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'ADVANCE_VENDOR',
                'name' => 'Advance to Vendor',
                'type' => 'ASSET',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'LOAN_RECEIVABLE',
                'name' => 'Loan Receivable',
                'type' => 'ASSET',
                'is_system' => true,
            ],
            // LIABILITY accounts
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'AP',
                'name' => 'Accounts Payable',
                'type' => 'LIABILITY',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PAYABLE_HARI',
                'name' => 'Payable to Hari',
                'type' => 'LIABILITY',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PAYABLE_LANDLORD',
                'name' => 'Payable to Landlord',
                'type' => 'LIABILITY',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PAYABLE_KAMDAR',
                'name' => 'Payable to Kamdar',
                'type' => 'LIABILITY',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'LOAN_PAYABLE',
                'name' => 'Loans Payable',
                'type' => 'LIABILITY',
                'is_system' => true,
            ],
            // INCOME accounts
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PROJECT_REVENUE',
                'name' => 'Project Revenue',
                'type' => 'INCOME',
                'is_system' => true,
            ],
            // EXPENSE accounts
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'EXP_SHARED',
                'name' => 'Shared Project Expense',
                'type' => 'EXPENSE',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'EXP_HARI_ONLY',
                'name' => 'Hari-only Project Expense',
                'type' => 'EXPENSE',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'EXP_FARM_OVERHEAD',
                'name' => 'Farm Overhead Expense',
                'type' => 'EXPENSE',
                'is_system' => true,
            ],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'EXP_KAMDARI',
                'name' => 'Kamdari Expense',
                'type' => 'EXPENSE',
                'is_system' => true,
            ],
            // EQUITY/CLEARING accounts
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'PROFIT_DISTRIBUTION',
                'name' => 'Profit Distribution / Settlement Clearing',
                'type' => 'EQUITY',
                'is_system' => true,
            ],
        ];

        // Insert accounts, ignoring duplicates (based on tenant_id + code unique constraint)
        foreach ($accounts as $account) {
            DB::table('accounts')->insertOrIgnore($account);
        }
    }
}

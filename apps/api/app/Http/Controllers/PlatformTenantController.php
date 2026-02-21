<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlatformTenantRequest;
use App\Http\Requests\UpdatePlatformTenantRequest;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\PlanModules;
use App\Services\SystemPartyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformTenantController extends Controller
{
    public function __construct(
        protected PlanModules $planModules
    ) {}
    private const ENABLED_MODULE_KEYS = [
        'accounting_core',
        'projects_crop_cycles',
        'land',
        'treasury_payments',
        'treasury_advances',
        'ar_sales',
        'settlements',
        'reports',
    ];

    /**
     * List all tenants.
     * GET /api/platform/tenants
     */
    public function index(): JsonResponse
    {
        $tenants = Tenant::orderBy('created_at', 'desc')
            ->get(['id', 'name', 'status', 'plan_key', 'currency_code', 'locale', 'timezone', 'created_at']);

        return response()->json([
            'tenants' => $tenants->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'status' => $t->status,
                'plan_key' => $t->plan_key,
                'currency_code' => $t->currency_code,
                'locale' => $t->locale,
                'timezone' => $t->timezone,
                'created_at' => $t->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Show a tenant with farm.
     * GET /api/platform/tenants/{id}
     */
    public function show(string $id): JsonResponse
    {
        $tenant = Tenant::with('farm')->findOrFail($id);

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'status' => $tenant->status,
            'plan_key' => $tenant->plan_key,
            'currency_code' => $tenant->currency_code,
            'locale' => $tenant->locale,
            'timezone' => $tenant->timezone,
            'created_at' => $tenant->created_at->toIso8601String(),
            'farm' => $tenant->farm ? [
                'id' => $tenant->farm->id,
                'farm_name' => $tenant->farm->farm_name,
                'country' => $tenant->farm->country,
                'address_line1' => $tenant->farm->address_line1,
                'address_line2' => $tenant->farm->address_line2,
                'city' => $tenant->farm->city,
                'region' => $tenant->farm->region,
                'postal_code' => $tenant->farm->postal_code,
                'phone' => $tenant->farm->phone,
            ] : null,
        ]);
    }

    /**
     * Create a tenant with full onboarding.
     * POST /api/platform/tenants
     */
    public function store(StorePlatformTenantRequest $request): JsonResponse
    {
        $v = $request->validated();

        $currencyCode = $v['currency_code'] ?? null;
        $locale = $v['locale'] ?? null;
        $timezone = $v['timezone'] ?? null;

        if ($currencyCode === null || $locale === null || $timezone === null) {
            $country = isset($v['country']) ? strtoupper(trim((string) $v['country'])) : '';
            $isPakistan = $country === 'PK' || $country === 'PAKISTAN';
            if ($currencyCode === null) {
                $currencyCode = $isPakistan ? 'PKR' : 'GBP';
            }
            if ($locale === null) {
                $locale = $isPakistan ? 'en-PK' : 'en-GB';
            }
            if ($timezone === null) {
                $timezone = $isPakistan ? 'Asia/Karachi' : 'Europe/London';
            }
        }

        $tenant = DB::transaction(function () use ($v, $currencyCode, $locale, $timezone) {
            $tenant = Tenant::create([
                'name' => trim($v['name']),
                'status' => 'active',
                'currency_code' => $currencyCode,
                'locale' => $locale,
                'timezone' => $timezone,
            ]);

            Farm::create([
                'tenant_id' => $tenant->id,
                'farm_name' => $tenant->name,
            ]);

            $this->initializeSystemAccounts($tenant->id);

            (new SystemPartyService())->ensureSystemLandlordParty($tenant->id);

            $this->enableDefaultModules($tenant->id);

            User::create([
                'tenant_id' => $tenant->id,
                'name' => $v['initial_admin_name'],
                'email' => $v['initial_admin_email'],
                'password' => Hash::make($v['initial_admin_password']),
                'role' => 'tenant_admin',
                'is_enabled' => true,
            ]);

            return $tenant->fresh();
        });

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'currency_code' => $tenant->currency_code,
                'locale' => $tenant->locale,
                'timezone' => $tenant->timezone,
                'created_at' => $tenant->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Update a tenant.
     * PUT /api/platform/tenants/{id}
     * On plan_key change, disables any tenant_modules not allowed by the new plan.
     */
    public function update(UpdatePlatformTenantRequest $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update($request->validated());

        $this->syncTenantModulesToPlan($tenant);

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'status' => $tenant->status,
            'plan_key' => $tenant->plan_key,
            'currency_code' => $tenant->currency_code,
            'locale' => $tenant->locale,
            'timezone' => $tenant->timezone,
            'created_at' => $tenant->created_at->toIso8601String(),
        ]);
    }

    private function initializeSystemAccounts(string $tenantId): void
    {
        $accounts = [
            ['code' => 'CASH', 'name' => 'Cash', 'type' => 'asset'],
            ['code' => 'AR', 'name' => 'Accounts Receivable', 'type' => 'asset'],
            ['code' => 'ADVANCE_HARI', 'name' => 'Advance to Hari', 'type' => 'asset'],
            ['code' => 'ADVANCE_VENDOR', 'name' => 'Advance to Vendor', 'type' => 'asset'],
            ['code' => 'LOAN_RECEIVABLE', 'name' => 'Loan Receivable', 'type' => 'asset'],
            ['code' => 'DUE_FROM_HARI', 'name' => 'Due from Hari', 'type' => 'asset'],
            ['code' => 'PARTY_CONTROL_HARI', 'name' => 'Party Control - Hari (sign-driven)', 'type' => 'asset'],
            ['code' => 'PARTY_CONTROL_LANDLORD', 'name' => 'Party Control - Landlord (sign-driven)', 'type' => 'asset'],
            ['code' => 'PARTY_CONTROL_KAMDAR', 'name' => 'Party Control - Kamdar (sign-driven)', 'type' => 'asset'],
            ['code' => 'PROFIT_DISTRIBUTION_CLEARING', 'name' => 'Profit Distribution Clearing (settlement only)', 'type' => 'equity'],
            ['code' => 'AP', 'name' => 'Accounts Payable', 'type' => 'liability'],
            ['code' => 'PAYABLE_HARI', 'name' => 'Payable to Hari', 'type' => 'liability'],
            ['code' => 'PAYABLE_LANDLORD', 'name' => 'Payable to Landlord', 'type' => 'liability'],
            ['code' => 'PAYABLE_KAMDAR', 'name' => 'Payable to Kamdar', 'type' => 'liability'],
            ['code' => 'LOAN_PAYABLE', 'name' => 'Loans Payable', 'type' => 'liability'],
            ['code' => 'PROJECT_REVENUE', 'name' => 'Project Revenue', 'type' => 'income'],
            ['code' => 'EXP_SHARED', 'name' => 'Shared Project Expense', 'type' => 'expense'],
            ['code' => 'EXP_HARI_ONLY', 'name' => 'Hari-only Project Expense', 'type' => 'expense'],
            ['code' => 'EXP_LANDLORD_ONLY', 'name' => 'Landlord-only Project Expense', 'type' => 'expense'],
            ['code' => 'EXP_FARM_OVERHEAD', 'name' => 'Farm Overhead Expense', 'type' => 'expense'],
            ['code' => 'EXP_KAMDARI', 'name' => 'Kamdari Expense', 'type' => 'expense'],
            ['code' => 'PROFIT_DISTRIBUTION', 'name' => 'Profit Distribution / Settlement Clearing', 'type' => 'equity'],
        ];

        $now = now();
        foreach ($accounts as $a) {
            DB::table('accounts')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => $a['code'],
                'name' => $a['name'],
                'type' => $a['type'],
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function syncTenantModulesToPlan(Tenant $tenant): void
    {
        $allowedKeys = $this->planModules->getAllowedModuleKeysForPlan($tenant->plan_key);
        $modules = Module::all();
        $nonCoreKeys = $modules->filter(fn ($m) => !$m->is_core)->pluck('key')->all();

        foreach ($nonCoreKeys as $key) {
            if (in_array($key, $allowedKeys, true)) {
                continue;
            }
            $module = $modules->firstWhere('key', $key);
            if (!$module) {
                continue;
            }
            TenantModule::where('tenant_id', $tenant->id)
                ->where('module_id', $module->id)
                ->where('status', 'ENABLED')
                ->update([
                    'status' => 'DISABLED',
                    'disabled_at' => now(),
                ]);
        }
    }

    private function enableDefaultModules(string $tenantId): void
    {
        $modules = Module::whereIn('key', self::ENABLED_MODULE_KEYS)->get();

        foreach ($modules as $module) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenantId, 'module_id' => $module->id],
                [
                    'status' => 'ENABLED',
                    'enabled_at' => now(),
                    'disabled_at' => null,
                    'enabled_by_user_id' => null,
                ]
            );
        }
    }
}

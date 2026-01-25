<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SystemAccountsSeeder extends Seeder
{
    /**
     * Seed system accounts for a single tenant. Use in tests after Tenant::create.
     */
    public static function runForTenant(string $tenantId): void
    {
        $now = now();
        foreach (self::accountSpecs() as $a) {
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

    /**
     * Seed minimum required system accounts for all existing tenants.
     */
    public function run(): void
    {
        $tenantIds = DB::table('tenants')->pluck('id');
        foreach ($tenantIds as $tenantId) {
            self::runForTenant($tenantId);
        }
    }

    /**
     * @return array<int, array{code: string, name: string, type: string}>
     */
    private static function accountSpecs(): array
    {
        return [
            ['code' => 'CASH', 'name' => 'Cash', 'type' => 'asset'],
            ['code' => 'AR', 'name' => 'Accounts Receivable', 'type' => 'asset'],
            ['code' => 'ADVANCE_HARI', 'name' => 'Advance to Hari', 'type' => 'asset'],
            ['code' => 'ADVANCE_VENDOR', 'name' => 'Advance to Vendor', 'type' => 'asset'],
            ['code' => 'LOAN_RECEIVABLE', 'name' => 'Loan Receivable', 'type' => 'asset'],
            ['code' => 'AP', 'name' => 'Accounts Payable', 'type' => 'liability'],
            ['code' => 'PAYABLE_HARI', 'name' => 'Payable to Hari', 'type' => 'liability'],
            ['code' => 'PAYABLE_LANDLORD', 'name' => 'Payable to Landlord', 'type' => 'liability'],
            ['code' => 'PAYABLE_KAMDAR', 'name' => 'Payable to Kamdar', 'type' => 'liability'],
            ['code' => 'LOAN_PAYABLE', 'name' => 'Loans Payable', 'type' => 'liability'],
            ['code' => 'PROJECT_REVENUE', 'name' => 'Project Revenue', 'type' => 'income'],
            ['code' => 'EXP_SHARED', 'name' => 'Shared Project Expense', 'type' => 'expense'],
            ['code' => 'EXP_HARI_ONLY', 'name' => 'Hari-only Project Expense', 'type' => 'expense'],
            ['code' => 'EXP_FARM_OVERHEAD', 'name' => 'Farm Overhead Expense', 'type' => 'expense'],
            ['code' => 'EXP_KAMDARI', 'name' => 'Kamdari Expense', 'type' => 'expense'],
            ['code' => 'PROFIT_DISTRIBUTION', 'name' => 'Profit Distribution / Settlement Clearing', 'type' => 'equity'],
            ['code' => 'INVENTORY_INPUTS', 'name' => 'Inventory / Inputs Stock', 'type' => 'asset'],
            ['code' => 'INPUTS_EXPENSE', 'name' => 'Inputs Expense', 'type' => 'expense'],
            ['code' => 'STOCK_VARIANCE', 'name' => 'Stock Variance / Shrinkage', 'type' => 'expense'],
        ];
    }
}

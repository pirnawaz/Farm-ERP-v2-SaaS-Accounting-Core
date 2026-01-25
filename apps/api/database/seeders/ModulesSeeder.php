<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModulesSeeder extends Seeder
{
    /**
     * Seed the modules catalog. Idempotent via updateOrInsert on key.
     */
    public function run(): void
    {
        $catalog = [
            ['key' => 'accounting_core', 'name' => 'Accounting Core', 'description' => 'Core accounting: chart of accounts, posting, ledger', 'is_core' => true, 'sort_order' => 1],
            ['key' => 'projects_crop_cycles', 'name' => 'Projects & Crop Cycles', 'description' => 'Crop cycles, projects, allocations, transactions, settlement', 'is_core' => false, 'sort_order' => 2],
            ['key' => 'land', 'name' => 'Land', 'description' => 'Land parcels and documents', 'is_core' => false, 'sort_order' => 3],
            ['key' => 'treasury_payments', 'name' => 'Treasury – Payments', 'description' => 'Payments and cash movements', 'is_core' => false, 'sort_order' => 4],
            ['key' => 'treasury_advances', 'name' => 'Treasury – Advances', 'description' => 'Advances to parties', 'is_core' => false, 'sort_order' => 5],
            ['key' => 'ar_sales', 'name' => 'AR & Sales', 'description' => 'Sales, receivables, AR ageing', 'is_core' => false, 'sort_order' => 6],
            ['key' => 'settlements', 'name' => 'Settlements', 'description' => 'Project settlements and profit distribution', 'is_core' => false, 'sort_order' => 7],
            ['key' => 'reports', 'name' => 'Reports', 'description' => 'Trial balance, general ledger, P&L, cashbook, account balances', 'is_core' => false, 'sort_order' => 8],
            ['key' => 'inventory', 'name' => 'Inventory', 'description' => 'Stock: GRNs, Issues, Transfers, Adjustments, Valuation', 'is_core' => false, 'sort_order' => 9],
            ['key' => 'machinery', 'name' => 'Machinery', 'description' => 'Machinery and equipment (future)', 'is_core' => false, 'sort_order' => 10],
            ['key' => 'loans', 'name' => 'Loans', 'description' => 'Loans and loan transactions (future)', 'is_core' => false, 'sort_order' => 11],
        ];

        $now = now();
        foreach ($catalog as $row) {
            DB::table('modules')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['created_at' => $now, 'updated_at' => $now])
            );
        }
    }
}

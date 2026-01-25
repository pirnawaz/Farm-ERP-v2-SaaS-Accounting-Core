<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_core')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        // Insert initial catalog (idempotent: updateOrInsert per row)
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
            ['key' => 'labour', 'name' => 'Labour', 'description' => 'Workers (Hari), work logs, wage accrual, wage payments', 'is_core' => false, 'sort_order' => 10],
            ['key' => 'machinery', 'name' => 'Machinery', 'description' => 'Machinery and equipment (future)', 'is_core' => false, 'sort_order' => 11],
            ['key' => 'loans', 'name' => 'Loans', 'description' => 'Loans and loan transactions (future)', 'is_core' => false, 'sort_order' => 12],
            ['key' => 'crop_ops', 'name' => 'Crop Operations / Activities', 'description' => 'Activity types, activities, inputs, labour; post consumes stock and accrues wages', 'is_core' => false, 'sort_order' => 13],
        ];

        $now = now();
        foreach ($catalog as $row) {
            DB::table('modules')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, ['created_at' => $now, 'updated_at' => $now])
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};

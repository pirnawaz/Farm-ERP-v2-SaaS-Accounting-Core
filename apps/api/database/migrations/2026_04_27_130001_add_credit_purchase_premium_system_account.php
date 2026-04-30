<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Add a dedicated expense account for the credit purchase premium (insert-only; never updates existing CoA rows).
        $now = now();
        $tenantIds = DB::table('tenants')->pluck('id');
        foreach ($tenantIds as $tenantId) {
            DB::table('accounts')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'code' => 'CREDIT_PURCHASE_PREMIUM_EXPENSE',
                'name' => 'Credit Purchase Premium Expense',
                'type' => 'expense',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive down: keep chart of accounts stable.
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * SAFETY: This migration ONLY creates new account records.
     * It does NOT modify any existing Posting Groups, Allocation Rows, or Ledger Entries.
     */
    public function up(): void
    {
        // Get all tenant IDs
        $tenantIds = DB::table('tenants')->pluck('id');

        foreach ($tenantIds as $tenantId) {
            // Check if account already exists
            $existing = DB::table('accounts')
                ->where('tenant_id', $tenantId)
                ->where('code', 'DUE_FROM_HARI')
                ->exists();

            if (!$existing) {
                DB::table('accounts')->insert([
                    'id' => DB::raw("gen_random_uuid()"),
                    'tenant_id' => $tenantId,
                    'code' => 'DUE_FROM_HARI',
                    'name' => 'Due from Hari',
                    'type' => 'asset',
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove DUE_FROM_HARI system account for all tenants
        DB::table('accounts')
            ->where('code', 'DUE_FROM_HARI')
            ->where('is_system', true)
            ->delete();
    }
};

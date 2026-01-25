<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Get all tenant IDs
        $tenantIds = DB::table('tenants')->pluck('id');

        foreach ($tenantIds as $tenantId) {
            // Check if accounts already exist
            $existingCodes = DB::table('accounts')
                ->where('tenant_id', $tenantId)
                ->whereIn('code', ['ADVANCE_HARI', 'ADVANCE_VENDOR', 'LOAN_RECEIVABLE'])
                ->pluck('code')
                ->toArray();

            $accountsToCreate = [];

            if (!in_array('ADVANCE_HARI', $existingCodes)) {
                $accountsToCreate[] = [
                    'id' => DB::raw("gen_random_uuid()"),
                    'tenant_id' => $tenantId,
                    'code' => 'ADVANCE_HARI',
                    'name' => 'Advance to Hari',
                    'type' => 'asset',
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!in_array('ADVANCE_VENDOR', $existingCodes)) {
                $accountsToCreate[] = [
                    'id' => DB::raw("gen_random_uuid()"),
                    'tenant_id' => $tenantId,
                    'code' => 'ADVANCE_VENDOR',
                    'name' => 'Advance to Vendor',
                    'type' => 'asset',
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!in_array('LOAN_RECEIVABLE', $existingCodes)) {
                $accountsToCreate[] = [
                    'id' => DB::raw("gen_random_uuid()"),
                    'tenant_id' => $tenantId,
                    'code' => 'LOAN_RECEIVABLE',
                    'name' => 'Loan Receivable',
                    'type' => 'asset',
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($accountsToCreate)) {
                DB::table('accounts')->insert($accountsToCreate);
            }
        }
    }

    public function down(): void
    {
        // Remove system accounts for all tenants
        DB::table('accounts')
            ->whereIn('code', ['ADVANCE_HARI', 'ADVANCE_VENDOR', 'LOAN_RECEIVABLE'])
            ->where('is_system', true)
            ->delete();
    }
};

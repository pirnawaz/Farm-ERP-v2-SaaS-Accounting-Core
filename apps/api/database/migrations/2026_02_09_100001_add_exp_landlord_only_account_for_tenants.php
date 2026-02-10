<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ensure EXP_LANDLORD_ONLY account exists for all existing tenants.
     */
    public function up(): void
    {
        $tenantIds = DB::table('tenants')->pluck('id');
        foreach ($tenantIds as $tenantId) {
            \Database\Seeders\SystemAccountsSeeder::runForTenant($tenantId);
        }
    }

    public function down(): void
    {
        // Do not remove accounts on rollback (other data may reference them)
    }
};

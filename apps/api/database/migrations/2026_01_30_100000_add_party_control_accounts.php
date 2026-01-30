<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $accounts = [
            ['code' => 'PARTY_CONTROL_HARI', 'name' => 'Party Control - Hari (sign-driven)', 'type' => 'asset'],
            ['code' => 'PARTY_CONTROL_LANDLORD', 'name' => 'Party Control - Landlord (sign-driven)', 'type' => 'asset'],
            ['code' => 'PARTY_CONTROL_KAMDAR', 'name' => 'Party Control - Kamdar (sign-driven)', 'type' => 'asset'],
            ['code' => 'PROFIT_DISTRIBUTION_CLEARING', 'name' => 'Profit Distribution Clearing (settlement only)', 'type' => 'equity'],
        ];

        $tenantIds = DB::table('tenants')->pluck('id');

        foreach ($tenantIds as $tenantId) {
            foreach ($accounts as $a) {
                $exists = DB::table('accounts')
                    ->where('tenant_id', $tenantId)
                    ->where('code', $a['code'])
                    ->exists();

                if (!$exists) {
                    DB::table('accounts')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId,
                        'code' => $a['code'],
                        'name' => $a['name'],
                        'type' => $a['type'],
                        'is_system' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('accounts')
            ->whereIn('code', [
                'PARTY_CONTROL_HARI',
                'PARTY_CONTROL_LANDLORD',
                'PARTY_CONTROL_KAMDAR',
                'PROFIT_DISTRIBUTION_CLEARING',
            ])
            ->where('is_system', true)
            ->delete();
    }
};

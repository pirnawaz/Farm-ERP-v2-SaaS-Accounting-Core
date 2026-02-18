<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Add RETAINED_EARNINGS and CURRENT_EARNINGS (equity) for period close.
     * Safe: only inserts new account records.
     */
    public function up(): void
    {
        $tenantIds = DB::table('tenants')->pluck('id');

        $accounts = [
            ['code' => 'RETAINED_EARNINGS', 'name' => 'Retained Earnings', 'type' => 'equity'],
            ['code' => 'CURRENT_EARNINGS', 'name' => 'Current Earnings (period close clearing)', 'type' => 'equity'],
        ];

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

    public function down(): void
    {
        DB::table('accounts')
            ->whereIn('code', ['RETAINED_EARNINGS', 'CURRENT_EARNINGS'])
            ->where('is_system', true)
            ->delete();
    }
};

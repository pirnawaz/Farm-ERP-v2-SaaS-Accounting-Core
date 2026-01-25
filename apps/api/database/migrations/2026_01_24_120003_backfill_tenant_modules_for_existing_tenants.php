<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ENABLED_KEYS = [
        'accounting_core',
        'projects_crop_cycles',
        'land',
        'treasury_payments',
        'treasury_advances',
        'ar_sales',
        'settlements',
        'reports',
    ];

    public function up(): void
    {
        $moduleIds = DB::table('modules')
            ->whereIn('key', self::ENABLED_KEYS)
            ->pluck('id', 'key');

        if ($moduleIds->isEmpty()) {
            return;
        }

        $tenantIds = DB::table('tenants')->pluck('id');
        $now = now();

        foreach ($tenantIds as $tenantId) {
            $rows = [];
            foreach (self::ENABLED_KEYS as $key) {
                $moduleId = $moduleIds->get($key);
                if (!$moduleId) {
                    continue;
                }
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'module_id' => $moduleId,
                    'status' => 'ENABLED',
                    'enabled_at' => $now,
                    'disabled_at' => null,
                    'enabled_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach ($rows as $row) {
                DB::table('tenant_modules')->insertOrIgnore([$row]);
            }
        }
    }

    public function down(): void
    {
        // Reversing the backfill is not fully deterministic (we cannot restore
        // previous tenant_module state). No-op; document that logical undo is not supported.
    }
};

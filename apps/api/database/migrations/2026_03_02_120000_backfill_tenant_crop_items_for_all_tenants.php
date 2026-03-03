<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Backfill tenant_crop_items for all tenants so each has rows for every active crop_catalog_item.
     * Idempotent: only inserts missing (tenant_id, crop_catalog_item_id) pairs.
     */
    public function up(): void
    {
        $catalogItems = DB::table('crop_catalog_items')
            ->where('is_active', true)
            ->get(['id', 'default_name']);

        if ($catalogItems->isEmpty()) {
            return;
        }

        $tenants = DB::table('tenants')->pluck('id');
        $now = now();

        foreach ($tenants as $tenantId) {
            $existingCatalogIds = DB::table('tenant_crop_items')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('crop_catalog_item_id')
                ->pluck('crop_catalog_item_id')
                ->flip();

            foreach ($catalogItems as $catalog) {
                if ($existingCatalogIds->has($catalog->id)) {
                    continue;
                }
                DB::table('tenant_crop_items')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'crop_catalog_item_id' => $catalog->id,
                    'custom_name' => null,
                    'display_name' => $catalog->default_name,
                    'is_active' => true,
                    'sort_order' => 0,
                    'metadata' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Safe down: only remove tenant_crop_items that were provisioned from catalog
     * (crop_catalog_item_id in catalog and display_name matches catalog default_name).
     * Does not remove custom or renamed rows.
     */
    public function down(): void
    {
        $catalogIds = DB::table('crop_catalog_items')->pluck('default_name', 'id');

        foreach ($catalogIds as $catalogId => $defaultName) {
            DB::table('tenant_crop_items')
                ->where('crop_catalog_item_id', $catalogId)
                ->where('display_name', $defaultName)
                ->delete();
        }
    }
};

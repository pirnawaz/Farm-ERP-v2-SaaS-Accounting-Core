<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantCropItemProvisioner
{
    /**
     * Ensure the tenant has tenant_crop_items for every active crop_catalog_item.
     * Idempotent: inserts only missing rows (unique on tenant_id, crop_catalog_item_id).
     */
    public function syncTenant(string $tenantId): void
    {
        $catalogItems = DB::table('crop_catalog_items')
            ->where('is_active', true)
            ->get(['id', 'default_name']);

        if ($catalogItems->isEmpty()) {
            return;
        }

        $existingCatalogIds = DB::table('tenant_crop_items')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('crop_catalog_item_id')
            ->pluck('crop_catalog_item_id')
            ->flip();

        $now = now();
        $rows = [];

        foreach ($catalogItems as $catalog) {
            if ($existingCatalogIds->has($catalog->id)) {
                continue;
            }
            $rows[] = [
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
            ];
        }

        if ($rows !== []) {
            foreach ($rows as $row) {
                DB::table('tenant_crop_items')->insertOrIgnore($row);
            }
        }
    }
}

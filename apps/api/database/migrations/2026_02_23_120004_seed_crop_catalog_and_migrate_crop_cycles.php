<?php

/*
|--------------------------------------------------------------------------
| IMPORTANT: Data Safety Rule
|--------------------------------------------------------------------------
| This migration seeds core catalog data.
| Rollbacks MUST NOT delete production data.
| Destructive rollback is allowed only in local/testing environments.
|--------------------------------------------------------------------------
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFAULT_CATALOG = [
        ['code' => 'MAIZE', 'default_name' => 'Maize', 'scientific_name' => 'Zea mays', 'category' => 'cereal'],
        ['code' => 'WHEAT', 'default_name' => 'Wheat', 'scientific_name' => 'Triticum aestivum', 'category' => 'cereal'],
        ['code' => 'RICE', 'default_name' => 'Rice', 'scientific_name' => 'Oryza sativa', 'category' => 'cereal'],
        ['code' => 'COTTON', 'default_name' => 'Cotton', 'scientific_name' => 'Gossypium hirsutum', 'category' => 'fiber'],
        ['code' => 'SOYBEAN', 'default_name' => 'Soybean', 'scientific_name' => 'Glycine max', 'category' => 'legume'],
        ['code' => 'BARLEY', 'default_name' => 'Barley', 'scientific_name' => 'Hordeum vulgare', 'category' => 'cereal'],
        ['code' => 'SUNFLOWER', 'default_name' => 'Sunflower', 'scientific_name' => 'Helianthus annuus', 'category' => 'oilseed'],
        ['code' => 'SORGHUM', 'default_name' => 'Sorghum', 'scientific_name' => 'Sorghum bicolor', 'category' => 'cereal'],
        ['code' => 'MILLET', 'default_name' => 'Millet', 'scientific_name' => 'Pennisetum glaucum', 'category' => 'cereal'],
        ['code' => 'SUGARCANE', 'default_name' => 'Sugarcane', 'scientific_name' => 'Saccharum officinarum', 'category' => 'other'],
        ['code' => 'POTATO', 'default_name' => 'Potato', 'scientific_name' => 'Solanum tuberosum', 'category' => 'vegetable'],
        ['code' => 'TOMATO', 'default_name' => 'Tomato', 'scientific_name' => 'Solanum lycopersicum', 'category' => 'vegetable'],
        ['code' => 'ONION', 'default_name' => 'Onion', 'scientific_name' => 'Allium cepa', 'category' => 'vegetable'],
        ['code' => 'ALFALFA', 'default_name' => 'Alfalfa', 'scientific_name' => 'Medicago sativa', 'category' => 'fodder'],
    ];

    public function up(): void
    {
        $now = now()->toIso8601String();

        $existingCodes = DB::table('crop_catalog_items')->pluck('code')->flip();
        foreach (self::DEFAULT_CATALOG as $row) {
            if ($existingCodes->has($row['code'])) {
                continue;
            }
            $id = (string) \Illuminate\Support\Str::uuid();
            DB::table('crop_catalog_items')->insert([
                'id' => $id,
                'code' => $row['code'],
                'default_name' => $row['default_name'],
                'scientific_name' => $row['scientific_name'],
                'category' => $row['category'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $catalogItems = DB::table('crop_catalog_items')->get()->keyBy('code');
        $tenants = DB::table('tenants')->pluck('id');
        $existingTenantCatalog = DB::table('tenant_crop_items')
            ->whereNotNull('crop_catalog_item_id')
            ->get()
            ->keyBy(fn ($r) => $r->tenant_id . '|' . $r->crop_catalog_item_id);

        foreach ($tenants as $tenantId) {
            foreach ($catalogItems as $catalog) {
                $key = $tenantId . '|' . $catalog->id;
                if ($existingTenantCatalog->has($key)) {
                    continue;
                }
                $tciId = (string) \Illuminate\Support\Str::uuid();
                DB::table('tenant_crop_items')->insert([
                    'id' => $tciId,
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

        $cycles = DB::table('crop_cycles')
            ->whereNull('tenant_crop_item_id')
            ->whereNotNull('crop_type')
            ->where('crop_type', '!=', '')
            ->get();
        $tenantCropItemsByTenant = DB::table('tenant_crop_items')
            ->join('crop_catalog_items', 'tenant_crop_items.crop_catalog_item_id', '=', 'crop_catalog_items.id')
            ->select(
                'tenant_crop_items.id as tenant_crop_item_id',
                'tenant_crop_items.tenant_id',
                'crop_catalog_items.default_name',
                'crop_catalog_items.code'
            )
            ->get()
            ->groupBy('tenant_id');

        foreach ($cycles as $cycle) {
            $tenantId = $cycle->tenant_id;
            $cropType = trim($cycle->crop_type);
            if ($cropType === '') {
                continue;
            }

            $items = $tenantCropItemsByTenant->get($tenantId, collect());
            $matched = $items->first(function ($item) use ($cropType) {
                return strcasecmp($item->default_name, $cropType) === 0
                    || strcasecmp($item->code, $cropType) === 0;
            });

            if ($matched) {
                DB::table('crop_cycles')
                    ->where('id', $cycle->id)
                    ->update([
                        'tenant_crop_item_id' => $matched->tenant_crop_item_id,
                        'updated_at' => $now,
                    ]);
                continue;
            }

            $customId = (string) \Illuminate\Support\Str::uuid();
            DB::table('tenant_crop_items')->insert([
                'id' => $customId,
                'tenant_id' => $tenantId,
                'crop_catalog_item_id' => null,
                'custom_name' => $cropType,
                'display_name' => $cropType,
                'is_active' => true,
                'sort_order' => 0,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('crop_cycles')
                ->where('id', $cycle->id)
                ->update([
                    'tenant_crop_item_id' => $customId,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Never delete real data outside local/testing
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        DB::table('crop_cycles')->update([
            'tenant_crop_item_id' => null,
            'crop_variety_id' => null,
        ]);

        DB::table('tenant_crop_items')->delete();
        DB::table('crop_catalog_items')->delete();
    }
};

<?php

namespace Database\Seeders;

use App\Models\InvUom;
use App\Models\InvItemCategory;
use App\Models\InvItem;
use App\Models\InvStore;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventorySeeder extends Seeder
{
    /**
     * Seed inventory master data for a single tenant.
     * Safe to run multiple times (uses firstOrCreate / updateOrInsert where appropriate).
     */
    public static function runForTenant(string $tenantId): void
    {
        $uomKg = InvUom::firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'KG'],
            ['name' => 'Kilogram']
        );
        $uomBag = InvUom::firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'BAG'],
            ['name' => 'Bag']
        );
        InvUom::firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => 'L'],
            ['name' => 'Litre']
        );

        $cat = InvItemCategory::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Fertilizer'],
            []
        );

        $store = InvStore::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Main Store'],
            ['type' => 'MAIN', 'is_active' => true]
        );

        InvItem::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Fertilizer Bag'],
            [
                'sku' => 'FERT-BAG-01',
                'category_id' => $cat->id,
                'uom_id' => $uomBag->id,
                'valuation_method' => 'WAC',
                'is_active' => true,
            ]
        );
    }

    /**
     * Run for all existing tenants. Use in local/dev.
     */
    public function run(): void
    {
        $tenantIds = DB::table('tenants')->pluck('id');
        foreach ($tenantIds as $tid) {
            self::runForTenant($tid);
        }
    }
}

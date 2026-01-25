<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;

/**
 * One-off: for each tenant, run SystemAccountsSeeder, InventorySeeder, and enable inventory module.
 * Run: php artisan tinker then: (new \Database\Seeders\SeedTenantsInventory())->run();
 */
class SeedTenantsInventory
{
    public function run(): void
    {
        $tenants = Tenant::all();
        echo 'Tenants: ' . $tenants->count() . PHP_EOL;
        foreach ($tenants as $t) {
            SystemAccountsSeeder::runForTenant($t->id);
            echo '  SystemAccounts for ' . $t->name . PHP_EOL;
            InventorySeeder::runForTenant($t->id);
            echo '  Inventory for ' . $t->name . PHP_EOL;
            $m = Module::where('key', 'inventory')->first();
            if ($m) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $t->id, 'module_id' => $m->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
                echo '  Inventory module enabled for ' . $t->name . PHP_EOL;
            }
        }
        echo 'Done.' . PHP_EOL;
    }
}

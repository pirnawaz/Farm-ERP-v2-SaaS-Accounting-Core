<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryModuleDisabledReturns403Test extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_routes_return_403_when_module_disabled(): void
    {
        // TEMP: skip when force-all-modules override is on (RequireModule bypasses enforcement).
        if (filter_var(env('FORCE_ALL_MODULES_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Skip when FORCE_ALL_MODULES_ENABLED is set.');
        }
        $tenant = Tenant::create(['name' => 'Test', 'status' => 'active']);
        // Do NOT enable inventory (no TenantModule or status=DISABLED)

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/v1/inventory/items');
        $r->assertStatus(403);
        $this->assertStringContainsString('module not enabled', strtolower($r->json('message') ?? (string) $r->getContent()));

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')->getJson('/api/v1/inventory/transfers');
        $r2->assertStatus(403);

        $r3 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')->getJson('/api/v1/inventory/adjustments');
        $r3->assertStatus(403);
    }
}

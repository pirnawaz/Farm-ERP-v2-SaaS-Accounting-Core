<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CropOpsModuleDisabledReturns403Test extends TestCase
{
    use RefreshDatabase;

    public function test_crop_ops_routes_return_403_when_module_disabled(): void
    {
        // TEMP: skip when force-all-modules override is on (RequireModule bypasses enforcement).
        if (filter_var(env('FORCE_ALL_MODULES_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Skip when FORCE_ALL_MODULES_ENABLED is set.');
        }
        $tenant = Tenant::create(['name' => 'Test', 'status' => 'active']);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/v1/crop-ops/activities');
        $r->assertStatus(403);
        $this->assertStringContainsString('crop_ops', strtolower($r->json('message') ?? ''));
    }
}

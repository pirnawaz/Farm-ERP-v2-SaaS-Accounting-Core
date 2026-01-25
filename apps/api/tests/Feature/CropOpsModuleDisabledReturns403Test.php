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
        $tenant = Tenant::create(['name' => 'Test', 'status' => 'active']);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/v1/crop-ops/activities');
        $r->assertStatus(403);
        $this->assertStringContainsString('crop_ops', strtolower($r->json('message') ?? ''));
    }
}

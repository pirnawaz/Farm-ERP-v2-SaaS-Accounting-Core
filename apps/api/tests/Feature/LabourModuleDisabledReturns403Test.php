<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabourModuleDisabledReturns403Test extends TestCase
{
    use RefreshDatabase;

    public function test_labour_routes_return_403_when_module_disabled(): void
    {
        // TEMP: skip when force-all-modules override is on (RequireModule bypasses enforcement).
        if (filter_var(env('FORCE_ALL_MODULES_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Skip when FORCE_ALL_MODULES_ENABLED is set.');
        }
        $tenant = Tenant::create(['name' => 'Test', 'status' => 'active']);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/v1/labour/workers');
        $r->assertStatus(403);
        $this->assertStringContainsString('labour', strtolower($r->json('message') ?? ''));

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')->getJson('/api/v1/labour/work-logs');
        $r2->assertStatus(403);

        $r3 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')->getJson('/api/v1/labour/payables/outstanding');
        $r3->assertStatus(403);
    }
}

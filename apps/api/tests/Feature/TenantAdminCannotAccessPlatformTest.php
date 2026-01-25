<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAdminCannotAccessPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_cannot_access_platform_tenants(): void
    {
        $tenant = Tenant::create(['name' => 'T1']);

        $response = $this->withHeaders([
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'tenant_admin',
        ])->getJson('/api/platform/tenants');

        $response->assertStatus(403);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarmProfileIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_farm_profile_is_scoped_to_tenant(): void
    {
        $t1 = Tenant::create(['name' => 'T1']);
        $t2 = Tenant::create(['name' => 'T2']);
        Farm::create(['tenant_id' => $t1->id, 'farm_name' => 'Farm1']);
        Farm::create(['tenant_id' => $t2->id, 'farm_name' => 'Farm2']);

        $response = $this->withHeaders([
            'X-Tenant-Id' => $t1->id,
            'X-User-Role' => 'tenant_admin',
        ])->getJson('/api/tenant/farm-profile');

        $response->assertStatus(200);
        $this->assertEquals('Farm1', $response->json('farm_name'));
    }

    public function test_update_farm_profile_affects_only_current_tenant(): void
    {
        $t1 = Tenant::create(['name' => 'T1']);
        $t2 = Tenant::create(['name' => 'T2']);
        Farm::create(['tenant_id' => $t1->id, 'farm_name' => 'F1']);
        Farm::create(['tenant_id' => $t2->id, 'farm_name' => 'F2']);

        $this->withHeaders([
            'X-Tenant-Id' => $t1->id,
            'X-User-Role' => 'tenant_admin',
        ])->putJson('/api/tenant/farm-profile', ['farm_name' => 'Updated1']);

        $this->assertEquals('Updated1', Farm::where('tenant_id', $t1->id)->first()->farm_name);
        $this->assertEquals('F2', Farm::where('tenant_id', $t2->id)->first()->farm_name);
    }
}

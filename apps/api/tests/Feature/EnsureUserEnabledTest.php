<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureUserEnabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_user_receives_403_when_x_user_id_present(): void
    {
        $t = Tenant::create(['name' => 'T1']);
        $u = User::create([
            'tenant_id' => $t->id,
            'name' => 'U',
            'email' => 'u@x.com',
            'role' => 'operator',
            'is_enabled' => false,
        ]);

        $response = $this->withHeaders([
            'X-Tenant-Id' => $t->id,
            'X-User-Role' => 'operator',
            'X-User-Id' => $u->id,
        ])->getJson('/api/operational-transactions');

        $response->assertStatus(403);
    }

    public function test_enabled_user_succeeds_when_x_user_id_present(): void
    {
        $t = Tenant::create(['name' => 'T1']);
        $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => 'u@x.com', 'role' => 'operator']);

        $response = $this->withHeaders([
            'X-Tenant-Id' => $t->id,
            'X-User-Role' => 'operator',
            'X-User-Id' => $u->id,
        ])->getJson('/api/operational-transactions');

        $response->assertStatus(200);
    }

    public function test_request_succeeds_when_x_user_id_absent(): void
    {
        $t = Tenant::create(['name' => 'T1']);

        $response = $this->withHeaders([
            'X-Tenant-Id' => $t->id,
            'X-User-Role' => 'operator',
        ])->getJson('/api/operational-transactions');

        $response->assertStatus(200);
    }
}

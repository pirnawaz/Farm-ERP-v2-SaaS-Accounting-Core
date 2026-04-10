<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Legacy DELETE /api/users/{id} must not hard-delete rows (same semantics as /api/tenant/users/{id}).
 */
class UserControllerDestroyPreservesUserRowTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'tenant_admin',
        ];
    }

    public function test_delete_users_route_soft_disables_and_keeps_row(): void
    {
        TenantContext::clear();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'a@t.test',
            'password' => Hash::make('x'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $operator = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Op',
            'email' => 'o@t.test',
            'password' => Hash::make('x'),
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $this->withHeaders($this->headers($tenant))
            ->deleteJson('/api/users/'.$operator->id)
            ->assertStatus(204);

        $this->assertDatabaseHas('users', [
            'id' => $operator->id,
            'email' => 'o@t.test',
            'is_enabled' => false,
        ]);
    }
}

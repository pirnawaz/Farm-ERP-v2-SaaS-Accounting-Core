<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformUserUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;
    private Tenant $tenant;
    private User $tenantAdmin;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platformAdmin = User::create([
            'tenant_id' => null,
            'name' => 'Platform Admin',
            'email' => 'platform@test.test',
            'password' => Hash::make('secret'),
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);
        $this->tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $this->tenantAdmin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tenant Admin',
            'email' => 'admin@test.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $this->operator = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Operator',
            'email' => 'op@test.test',
            'password' => Hash::make('secret'),
            'role' => 'operator',
            'is_enabled' => true,
        ]);
    }

    private function asPlatformAdmin(): self
    {
        return $this->withHeader('X-User-Id', $this->platformAdmin->id)
            ->withHeader('X-User-Role', 'platform_admin');
    }

    public function test_platform_can_disable_a_user(): void
    {
        $r = $this->asPlatformAdmin()->patchJson(
            '/api/platform/tenants/' . $this->tenant->id . '/users/' . $this->operator->id,
            ['is_enabled' => false]
        );
        $r->assertOk();
        $r->assertJsonPath('is_enabled', false);
        $this->operator->refresh();
        $this->assertFalse($this->operator->is_enabled);
    }

    public function test_platform_cannot_disable_last_tenant_admin(): void
    {
        $r = $this->asPlatformAdmin()->patchJson(
            '/api/platform/tenants/' . $this->tenant->id . '/users/' . $this->tenantAdmin->id,
            ['is_enabled' => false]
        );
        $r->assertStatus(422);
        $r->assertJsonPath('error', 'Cannot remove the last tenant admin.');
        $this->tenantAdmin->refresh();
        $this->assertTrue($this->tenantAdmin->is_enabled);
    }

    public function test_platform_can_change_role(): void
    {
        $r = $this->asPlatformAdmin()->patchJson(
            '/api/platform/tenants/' . $this->tenant->id . '/users/' . $this->operator->id,
            ['role' => 'accountant']
        );
        $r->assertOk();
        $r->assertJsonPath('role', 'accountant');
        $this->operator->refresh();
        $this->assertSame('accountant', $this->operator->role);
    }

    public function test_platform_cannot_demote_last_tenant_admin(): void
    {
        $r = $this->asPlatformAdmin()->patchJson(
            '/api/platform/tenants/' . $this->tenant->id . '/users/' . $this->tenantAdmin->id,
            ['role' => 'operator']
        );
        $r->assertStatus(422);
        $r->assertJsonPath('error', 'Cannot remove the last tenant admin.');
        $this->tenantAdmin->refresh();
        $this->assertSame('tenant_admin', $this->tenantAdmin->role);
    }

    public function test_platform_can_enable_user(): void
    {
        $this->operator->update(['is_enabled' => false]);
        $r = $this->asPlatformAdmin()->patchJson(
            '/api/platform/tenants/' . $this->tenant->id . '/users/' . $this->operator->id,
            ['is_enabled' => true]
        );
        $r->assertOk();
        $r->assertJsonPath('is_enabled', true);
        $this->operator->refresh();
        $this->assertTrue($this->operator->is_enabled);
    }

    public function test_user_must_belong_to_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'T2', 'status' => 'active']);
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other',
            'email' => 'other@test.test',
            'password' => Hash::make('secret'),
            'role' => 'operator',
            'is_enabled' => true,
        ]);
        $r = $this->asPlatformAdmin()->patchJson(
            '/api/platform/tenants/' . $this->tenant->id . '/users/' . $otherUser->id,
            ['role' => 'accountant']
        );
        $r->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Models\IdentityAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformTenantInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdminHeaders(?string $userId = null): array
    {
        $h = ['X-User-Role' => 'platform_admin'];
        if ($userId !== null) {
            $h['X-User-Id'] = $userId;
        }
        return $h;
    }

    /** @test */
    public function platform_admin_can_invite_initial_admin_into_empty_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Empty', 'status' => 'active']);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/invitations', [
                'email' => 'first@tenant.test',
            ]);

        $r->assertStatus(201);
        $r->assertJsonPath('email', 'first@tenant.test');
        $r->assertJsonPath('role', 'tenant_admin');
        $r->assertJsonPath('expires_in_hours', 168);
        $r->assertJsonStructure(['invite_link']);
        $this->assertStringContainsString('token=', $r->json('invite_link'));

        $this->assertDatabaseHas('user_invitations', [
            'tenant_id' => $tenant->id,
            'email' => 'first@tenant.test',
            'role' => 'tenant_admin',
        ]);
    }

    /** @test */
    public function platform_admin_can_invite_operator_into_non_empty_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Has Users', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/invitations', [
                'email' => 'op@tenant.test',
            ]);

        $r->assertStatus(201);
        $r->assertJsonPath('role', 'operator');
        $r->assertJsonPath('email', 'op@tenant.test');
    }

    /** @test */
    public function explicit_role_accepted(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/invitations', [
                'email' => 'acc@tenant.test',
                'role' => 'accountant',
            ]);

        $r->assertStatus(201);
        $r->assertJsonPath('role', 'accountant');
    }

    /** @test */
    public function invalid_role_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/invitations', [
                'email' => 'u@tenant.test',
                'role' => 'superadmin',
            ]);

        $r->assertStatus(422);
    }

    /** @test */
    public function existing_user_returns_409(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Existing',
            'email' => 'existing@t.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/invitations', [
                'email' => 'existing@t.test',
                'role' => 'operator',
            ]);

        $r->assertStatus(409);
        $r->assertJsonPath('error', 'User already exists in this tenant');
    }

    /** @test */
    public function platform_admin_email_returns_422(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        User::create([
            'tenant_id' => null,
            'name' => 'Other Platform',
            'email' => 'other@platform.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/invitations', [
                'email' => 'other@platform.test',
                'role' => 'operator',
            ]);

        $r->assertStatus(422);
        $r->assertJsonPath('error', 'Cannot invite a platform admin email to a tenant');
    }

    /** @test */
    public function audit_log_written(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/invitations', [
                'email' => 'audit@tenant.test',
                'role' => 'operator',
            ])
            ->assertStatus(201);

        $log = IdentityAuditLog::where('action', IdentityAuditLog::ACTION_PLATFORM_INVITATION_CREATED)->first();
        $this->assertNotNull($log);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($platformUser->id, $log->actor_user_id);
        $this->assertSame('audit@tenant.test', $log->metadata['email'] ?? null);
        $this->assertSame('operator', $log->metadata['role'] ?? null);
        $this->assertArrayHasKey('invitation_id', $log->metadata);
    }
}

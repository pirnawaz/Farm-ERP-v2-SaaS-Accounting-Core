<?php

namespace Tests\Feature;

use App\Models\IdentityAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\MakesAuthenticatedRequests;

class ManualUserCreateTest extends TestCase
{
    use RefreshDatabase;
    use MakesAuthenticatedRequests;

    /** @test */
    public function tenant_admin_can_create_user_and_gets_temporary_password(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $login = $this->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/auth/login', [
            'email' => 'admin@test.test',
            'password' => 'secret',
        ]);
        $login->assertStatus(200);

        $r = $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson('/api/tenant/users', [
                'name' => 'New User',
                'email' => 'new@test.test',
                'role' => 'operator',
            ]);

        $r->assertStatus(201);
        $r->assertJsonPath('user.name', 'New User');
        $r->assertJsonPath('user.email', 'new@test.test');
        $r->assertJsonPath('user.role', 'operator');
        $r->assertJsonStructure(['temporary_password']);
        $this->assertNotEmpty($r->json('temporary_password'));

        $user = User::where('tenant_id', $tenant->id)->where('email', 'new@test.test')->first();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($r->json('temporary_password'), $user->password));

        $this->assertDatabaseHas('identity_audit_log', [
            'action' => IdentityAuditLog::ACTION_TENANT_USER_CREATED_MANUAL,
            'tenant_id' => $tenant->id,
        ]);
    }

    /** @test */
    public function user_login_returns_must_change_password_true(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Must Change',
            'email' => 'change@test.test',
            'password' => Hash::make('temp12345'),
            'role' => 'operator',
            'is_enabled' => true,
            'must_change_password' => true,
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/auth/login', [
            'email' => 'change@test.test',
            'password' => 'temp12345',
        ]);

        $r->assertStatus(200);
        $r->assertJsonPath('user.must_change_password', true);
    }

    /** @test */
    public function user_blocked_from_tenant_routes_until_password_updated(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Must Change',
            'email' => 'change@test.test',
            'password' => Hash::make('temp12345'),
            'role' => 'operator',
            'is_enabled' => true,
            'must_change_password' => true,
        ]);

        $login = $this->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/auth/login', [
            'email' => 'change@test.test',
            'password' => 'temp12345',
        ]);
        $login->assertStatus(200);

        $dashboard = $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/dashboard/summary');
        $dashboard->assertStatus(403);
        $dashboard->assertJsonPath('error', 'password_update_required');
    }

    /** @test */
    public function auth_me_and_complete_first_login_allowed_when_must_change_password(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Must Change',
            'email' => 'change@test.test',
            'password' => Hash::make('temp12345'),
            'role' => 'operator',
            'is_enabled' => true,
            'must_change_password' => true,
        ]);

        $login = $this->withAuthCookieFrom(
            $this->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/auth/login', [
                'email' => 'change@test.test',
                'password' => 'temp12345',
            ])
        )->withHeader('X-Tenant-Id', $tenant->id);

        $me = $login->getJson('/api/auth/me');
        $me->assertStatus(200);
        $me->assertJsonPath('user.must_change_password', true);

        $complete = $login->postJson('/api/auth/complete-first-login-password', [
            'new_password' => 'newSecurePass12',
            'new_password_confirmation' => 'newSecurePass12',
        ]);
        $complete->assertStatus(200);
    }

    /** @test */
    public function after_password_update_must_change_password_false_and_access_works(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Must Change',
            'email' => 'change@test.test',
            'password' => Hash::make('temp12345'),
            'role' => 'operator',
            'is_enabled' => true,
            'must_change_password' => true,
        ]);

        $login = $this->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/auth/login', [
            'email' => 'change@test.test',
            'password' => 'temp12345',
        ]);
        $login->assertStatus(200);

        $complete = $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson('/api/auth/complete-first-login-password', [
                'new_password' => 'newSecurePass12',
                'new_password_confirmation' => 'newSecurePass12',
            ]);
        $complete->assertStatus(200);

        $user->refresh();
        $this->assertFalse($user->must_change_password);

        $newLogin = $this->withHeader('X-Tenant-Id', $tenant->id)->postJson('/api/auth/login', [
            'email' => 'change@test.test',
            'password' => 'newSecurePass12',
        ]);
        $newLogin->assertStatus(200);
        $newLogin->assertJsonPath('user.must_change_password', false);

        $dashboard = $this->withAuthCookieFrom($newLogin)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/dashboard/summary');
        $dashboard->assertStatus(200);
    }

    /** @test */
    public function platform_admin_can_create_user_for_tenant(): void
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

        $r = $this->withHeaders([
            'X-User-Role' => 'platform_admin',
            'X-User-Id' => $platformUser->id,
        ])->postJson('/api/platform/tenants/' . $tenant->id . '/users', [
            'name' => 'Platform Created',
            'email' => 'pcreated@test.test',
            'role' => 'accountant',
        ]);

        $r->assertStatus(201);
        $r->assertJsonPath('user.email', 'pcreated@test.test');
        $r->assertJsonPath('user.role', 'accountant');
        $r->assertJsonStructure(['temporary_password']);

        $user = User::where('tenant_id', $tenant->id)->where('email', 'pcreated@test.test')->first();
        $this->assertTrue($user->must_change_password);

        $this->assertDatabaseHas('identity_audit_log', [
            'action' => IdentityAuditLog::ACTION_PLATFORM_USER_CREATED_MANUAL,
            'tenant_id' => $tenant->id,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\PasswordResetToken;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformTenantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdminHeaders(string $userId): array
    {
        return [
            'X-User-Id' => $userId,
            'X-User-Role' => 'platform_admin',
        ];
    }

    /** @test */
    public function reset_admin_password_without_new_password_returns_token_and_logs(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => Hash::make('old'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/reset-admin-password', []);

        $r->assertStatus(200);
        $r->assertJsonPath('message', 'Reset token generated. Provide it to the tenant admin to set a new password.');
        $token = $r->json('reset_token');
        $this->assertNotNull($token);
        $this->assertNotEmpty($token);

        $this->assertDatabaseCount('platform_audit_log', 1);
        $log = PlatformAuditLog::first();
        $this->assertSame(PlatformAuditLog::ACTION_TENANT_PASSWORD_RESET, $log->action);
        $this->assertSame($tenant->id, $log->target_tenant_id);
        $this->assertSame($admin->id, $log->target_entity_id);
        $this->assertTrue($log->metadata['token_returned'] ?? false);
    }

    /** @test */
    public function set_password_with_token_consumes_token_and_updates_password(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => Hash::make('old'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $plainToken = 'fixed-test-token-' . \Illuminate\Support\Str::random(24);
        $hash = hash('sha256', $plainToken);
        PasswordResetToken::create([
            'user_id' => $admin->id,
            'token_hash' => $hash,
            'expires_at' => now()->addHours(1),
        ]);

        $r = $this->postJson('/api/auth/set-password-with-token', [
            'token' => $plainToken,
            'new_password' => 'newsecret123',
        ]);

        $r->assertStatus(200);
        $admin->refresh();
        $this->assertTrue(Hash::check('newsecret123', $admin->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    /** @test */
    public function reset_admin_password_with_new_password_updates_and_logs(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => Hash::make('old'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/reset-admin-password', [
                'new_password' => 'newpass123',
            ]);

        $r->assertStatus(200);
        $r->assertJsonPath('message', 'Password updated.');
        $r->assertJsonPath('reset_token', null);

        $admin->refresh();
        $this->assertTrue(Hash::check('newpass123', $admin->password));
        $this->assertDatabaseCount('platform_audit_log', 1);
    }

    /** @test */
    public function archive_tenant_logs_and_blocks_access(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/archive');

        $r->assertStatus(200);
        $r->assertJsonPath('status', 'archived');
        $tenant->refresh();
        $this->assertSame('archived', $tenant->status);

        $this->assertDatabaseCount('platform_audit_log', 1);
        $log = PlatformAuditLog::first();
        $this->assertSame(PlatformAuditLog::ACTION_TENANT_ARCHIVE, $log->action);

        // Tenant API with X-Tenant-Id should get 403
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'a@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $tenantReq = $this->withHeaders([
            'X-Tenant-Id' => $tenant->id,
            'X-User-Id' => $user->id,
            'X-User-Role' => 'tenant_admin',
        ])->getJson('/api/users');
        $tenantReq->assertStatus(403);
        $tenantReq->assertJsonPath('error', 'Tenant suspended. Access is not allowed.');
    }

    /** @test */
    public function unarchive_tenant_restores_active(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'archived']);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/unarchive');

        $r->assertStatus(200);
        $r->assertJsonPath('status', 'active');
        $tenant->refresh();
        $this->assertSame('active', $tenant->status);

        $this->assertDatabaseCount('platform_audit_log', 1);
        $log = PlatformAuditLog::first();
        $this->assertSame(PlatformAuditLog::ACTION_TENANT_UNARCHIVE, $log->action);
    }

    /** @test */
    public function reset_admin_password_404_when_no_tenant_admin(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        // No tenant_admin user
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/reset-admin-password', []);

        $r->assertStatus(404);
        $r->assertJsonPath('error', 'Tenant has no admin user');
    }
}

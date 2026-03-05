<?php

namespace Tests\Feature;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Models\ImpersonationAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminTenantAndImpersonationTest extends TestCase
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
    public function platform_admin_can_list_tenants(): void
    {
        Tenant::create(['name' => 'T1', 'status' => 'active']);
        Tenant::create(['name' => 'T2', 'status' => 'active']);

        $response = $this->withHeaders($this->platformAdminHeaders())
            ->getJson('/api/platform/tenants');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('tenants', $data);
        $this->assertCount(2, $data['tenants']);
    }

    /** @test */
    public function platform_admin_can_suspend_tenant_and_suspended_tenant_is_blocked_by_ensure_tenant_active(): void
    {
        $tenant = Tenant::create(['name' => 'ToSuspend', 'status' => 'active']);

        $response = $this->withHeaders($this->platformAdminHeaders())
            ->putJson('/api/platform/tenants/' . $tenant->id, ['status' => 'suspended']);

        $response->assertStatus(200);
        $tenant->refresh();
        $this->assertSame('suspended', $tenant->status);

        // Calling a tenant-scoped route with that tenant must now return 403 (EnsureTenantActive)
        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/parties');
        $r->assertStatus(403);
        $this->assertStringContainsString('tenant suspended', strtolower($r->getContent()));
    }

    /** @test */
    public function non_platform_admin_cannot_access_platform_admin_endpoints(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/platform/tenants');
        $r->assertStatus(403);

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson('/api/platform/impersonation/start', ['tenant_id' => $tenant->id]);
        $r2->assertStatus(403);
    }

    /** @test */
    public function impersonation_requires_platform_admin(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Id', $user->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson('/api/platform/impersonation/start', ['tenant_id' => $tenant->id]);
        $r->assertStatus(403);
    }

    /** @test */
    public function impersonation_creates_audit_log_entries(): void
    {
        $tenant = Tenant::create(['name' => 'Target', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Target User',
            'email' => 'target@test.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform User',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $this->assertDatabaseCount('impersonation_audit_log', 0);

        $start = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/impersonation/start', ['tenant_id' => $tenant->id]);
        $start->assertStatus(200);

        $this->assertDatabaseCount('impersonation_audit_log', 1);
        $log = ImpersonationAuditLog::first();
        $this->assertSame($platformUser->id, $log->actor_user_id);
        $this->assertSame($tenant->id, $log->target_tenant_id);
        $this->assertSame('START', $log->action);
        $this->assertArrayHasKey('ip', $log->metadata ?? []);
        $this->assertArrayHasKey('user_agent', $log->metadata ?? []);

        $stop = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/impersonation/stop', ['target_tenant_id' => $tenant->id]);
        $stop->assertStatus(200);

        $this->assertDatabaseCount('impersonation_audit_log', 2);
        $stopLog = ImpersonationAuditLog::where('action', ImpersonationAuditLog::ACTION_STOP)->first();
        $this->assertNotNull($stopLog);
        $this->assertSame($tenant->id, $stopLog->target_tenant_id);
        $this->assertSame($platformUser->id, $stopLog->actor_user_id);
    }

    private const IMPERSONATION_COOKIE_NAME = 'farm_erp_impersonation';

    /** @test */
    public function starting_impersonation_while_already_impersonating_returns_409(): void
    {
        $tenant1 = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $tenant2 = Tenant::create(['name' => 'T2', 'status' => 'active']);
        $user1 = User::create([
            'tenant_id' => $tenant1->id,
            'name' => 'U1',
            'email' => 'u1@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        User::create([
            'tenant_id' => $tenant2->id,
            'name' => 'U2',
            'email' => 'u2@t2.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $start1 = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/impersonation/start', ['tenant_id' => $tenant1->id]);
        $start1->assertStatus(200);

        // Manual cookie injection: test client does not forward cookies with postJson unless withCredentials();
        // use unencrypted cookie so server receives raw JSON (no EncryptCookies on API).
        $impersonationCookieValue = json_encode([
            'target_tenant_id' => $tenant1->id,
            'target_user_id' => $user1->id,
            'started_at' => now()->toIso8601String(),
        ]);
        $start2 = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->withCredentials()
            ->withUnencryptedCookies([self::IMPERSONATION_COOKIE_NAME => $impersonationCookieValue])
            ->postJson('/api/platform/impersonation/start', ['tenant_id' => $tenant2->id]);
        $start2->assertStatus(409);
        $start2->assertJsonPath('error', 'impersonation_nesting_not_allowed');
        $start2->assertJsonPath('message', 'Already impersonating. Stop impersonation first.');
    }

    /** @test */
    public function platform_logout_while_impersonating_returns_409(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $targetUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Target',
            'email' => 'target@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $impersonationCookieValue = json_encode([
            'target_tenant_id' => $tenant->id,
            'target_user_id' => $targetUser->id,
            'started_at' => now()->toIso8601String(),
        ]);
        $logout = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->withCredentials()
            ->withUnencryptedCookies([self::IMPERSONATION_COOKIE_NAME => $impersonationCookieValue])
            ->postJson('/api/platform/auth/logout');
        $logout->assertStatus(409);
        $logout->assertJsonPath('error', 'logout_while_impersonating');
    }

    /** @test */
    public function platform_logout_all_while_impersonating_returns_409(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $targetUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Target',
            'email' => 'target@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $impersonationCookieValue = json_encode([
            'target_tenant_id' => $tenant->id,
            'target_user_id' => $targetUser->id,
            'started_at' => now()->toIso8601String(),
        ]);
        $logoutAll = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->withCredentials()
            ->withUnencryptedCookies([self::IMPERSONATION_COOKIE_NAME => $impersonationCookieValue])
            ->postJson('/api/platform/auth/logout-all');
        $logoutAll->assertStatus(409);
        $logoutAll->assertJsonPath('error', 'logout_while_impersonating');
    }

    /** @test */
    public function platform_admin_can_list_tenant_users(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Op',
            'email' => 'op@t1.test',
            'password' => null,
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders())
            ->getJson('/api/platform/tenants/' . $tenant->id . '/users');

        $r->assertStatus(200);
        $r->assertJsonCount(2, 'users');
        $r->assertJsonPath('users.0.role', 'tenant_admin');
        $r->assertJsonPath('users.0.email', 'admin@t1.test');
        $r->assertJsonPath('users.1.role', 'operator');
        $r->assertJsonPath('users.1.email', 'op@t1.test');
    }

    /** @test */
    public function impersonate_with_user_id_impersonates_that_user(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $operator = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Op',
            'email' => 'op@t1.test',
            'password' => null,
            'role' => 'operator',
            'is_enabled' => true,
        ]);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/impersonate', ['user_id' => $operator->id]);

        $r->assertStatus(200);
        $r->assertJsonPath('target_user_id', $operator->id);
        $r->assertJsonPath('target_tenant_id', $tenant->id);
    }

    /** @test */
    public function impersonate_without_user_id_picks_tenant_admin(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Op',
            'email' => 'op@t1.test',
            'password' => null,
            'role' => 'operator',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/impersonate', []);

        $r->assertStatus(200);
        $r->assertJsonPath('target_user_id', $admin->id);
    }

    /** @test */
    public function tenant_with_zero_users_returns_422_no_users_to_impersonate(): void
    {
        $tenant = Tenant::create(['name' => 'Empty', 'status' => 'active']);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/impersonate', []);

        $r->assertStatus(422);
        $r->assertJsonPath('error', 'no_users_to_impersonate');
        $r->assertJsonPath('message', 'No users exist in this tenant to impersonate.');
    }

    /** @test */
    public function get_impersonation_status_returns_is_impersonating_true_after_start(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $start = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->postJson('/api/platform/tenants/' . $tenant->id . '/impersonate', ['user_id' => $admin->id]);

        $start->assertStatus(200);

        // Pass impersonation cookie so status endpoint sees it. getJson() does not send cookies by default
        // (prepareCookiesForJsonRequest returns [] unless withCredentials), so use call() with cookies.
        $impersonationCookieValue = json_encode([
            'target_tenant_id' => $tenant->id,
            'target_user_id' => $admin->id,
            'started_at' => now()->toIso8601String(),
        ]);
        $cookies = [self::IMPERSONATION_COOKIE_NAME => $impersonationCookieValue];
        $server = [
            'HTTP_X_USER_ROLE' => 'platform_admin',
            'HTTP_X_USER_ID' => $platformUser->id,
            'HTTP_ACCEPT' => 'application/json',
        ];
        $status = $this->call('GET', '/api/platform/impersonation/status', [], $cookies, [], $server);

        $status->assertStatus(200);
        $status->assertJsonPath('is_impersonating', true);
        $status->assertJsonPath('tenant.id', $tenant->id);
        $status->assertJsonPath('tenant.name', 'T1');
        $status->assertJsonPath('user.id', $admin->id);
        $status->assertJsonPath('user.email', 'admin@t1.test');
    }

    /** @test */
    public function tenant_whoami_returns_impersonated_user_identity_after_impersonate(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
            'token_version' => 1,
        ]);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        // Build same token as ImpersonationController::doStart (tenant user + impersonator).
        // Use call() with cookies and server so tenant and token are sent (getJson does not send cookies).
        $tenantToken = AuthToken::create($admin, $tenant->id, $platformUser->id, 24);
        $cookies = [AuthCookie::NAME => $tenantToken];
        $server = [
            'HTTP_X_TENANT_ID' => $tenant->id,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tenantToken,
        ];
        $whoami = $this->call('GET', '/api/auth/whoami', [], $cookies, [], $server);
        $whoami->assertStatus(200);
        $whoami->assertJsonPath('user_id', $admin->id);
        $whoami->assertJsonPath('user_role', 'tenant_admin');
        $whoami->assertJsonPath('tenant_id', $tenant->id);
        $whoami->assertJsonPath('impersonator_user_id', $platformUser->id);
    }
}

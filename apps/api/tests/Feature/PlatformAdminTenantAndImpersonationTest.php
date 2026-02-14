<?php

namespace Tests\Feature;

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
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
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
}

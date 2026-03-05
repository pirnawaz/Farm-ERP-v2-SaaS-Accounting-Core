<?php

namespace Tests\Feature;

use App\Models\IdentityAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAuditLogTest extends TestCase
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
    public function audit_logs_require_platform_admin(): void
    {
        $r = $this->getJson('/api/platform/audit-logs');
        $r->assertStatus(401);
    }

    /** @test */
    public function platform_admin_can_list_identity_audit_logs_with_filters(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        IdentityAuditLog::create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $user->id,
            'action' => IdentityAuditLog::ACTION_TENANT_LOGIN_SUCCESS,
            'metadata' => ['email' => $user->email],
            'ip' => '127.0.0.1',
            'user_agent' => 'Test',
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
            ->getJson('/api/platform/audit-logs');

        $r->assertStatus(200);
        $r->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $this->assertCount(1, $r->json('data'));
        $this->assertSame(IdentityAuditLog::ACTION_TENANT_LOGIN_SUCCESS, $r->json('data.0.action'));
        $this->assertArrayHasKey('actor', $r->json('data.0'));
        $this->assertSame($user->id, $r->json('data.0.actor.id'));
        $this->assertSame('127.0.0.1', $r->json('data.0.ip'));
    }

    /** @test */
    public function audit_logs_filter_by_tenant_id(): void
    {
        $t1 = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $t2 = Tenant::create(['name' => 'T2', 'status' => 'active']);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        IdentityAuditLog::create([
            'tenant_id' => $t1->id,
            'actor_user_id' => $platformUser->id,
            'action' => IdentityAuditLog::ACTION_INVITATION_CREATED,
            'metadata' => ['invite_email' => 'a@t1.test'],
            'ip' => null,
            'user_agent' => null,
        ]);
        IdentityAuditLog::create([
            'tenant_id' => $t2->id,
            'actor_user_id' => $platformUser->id,
            'action' => IdentityAuditLog::ACTION_INVITATION_CREATED,
            'metadata' => ['invite_email' => 'b@t2.test'],
            'ip' => null,
            'user_agent' => null,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->getJson('/api/platform/audit-logs?tenant_id=' . $t2->id);

        $r->assertStatus(200);
        $this->assertCount(1, $r->json('data'));
        $this->assertSame($t2->id, $r->json('data.0.tenant_id'));
        $this->assertSame('b@t2.test', $r->json('data.0.metadata.invite_email'));
    }

    /** @test */
    public function audit_logs_pagination(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);
        for ($i = 0; $i < 5; $i++) {
            IdentityAuditLog::create([
                'tenant_id' => $tenant->id,
                'actor_user_id' => $user->id,
                'action' => IdentityAuditLog::ACTION_TENANT_LOGIN_SUCCESS,
                'metadata' => null,
                'ip' => null,
                'user_agent' => null,
            ]);
        }

        $r = $this->withHeaders($this->platformAdminHeaders($user->id))
            ->getJson('/api/platform/audit-logs?per_page=2&page=1');

        $r->assertStatus(200);
        $this->assertCount(2, $r->json('data'));
        $r->assertJsonPath('meta.per_page', 2);
        $r->assertJsonPath('meta.current_page', 1);
        $r->assertJsonPath('meta.total', 5);
    }
}

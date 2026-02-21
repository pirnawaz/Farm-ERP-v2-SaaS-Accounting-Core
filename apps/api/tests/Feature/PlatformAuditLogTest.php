<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
    public function platform_admin_can_list_audit_logs_with_filters(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'entity_type' => 'Sale',
            'entity_id' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'POST',
            'user_id' => $user->id,
            'user_email' => $user->email,
            'metadata' => null,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($user->id))
            ->getJson('/api/platform/audit-logs');

        $r->assertStatus(200);
        $r->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $this->assertCount(1, $r->json('data'));
        $this->assertSame('POST', $r->json('data.0.action'));
        $this->assertSame('Sale', $r->json('data.0.entity_type'));
    }

    /** @test */
    public function audit_logs_filter_by_tenant_id(): void
    {
        $t1 = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $t2 = Tenant::create(['name' => 'T2', 'status' => 'active']);
        $platformUser = User::create([
            'tenant_id' => $t1->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);
        $uid = (string) \Illuminate\Support\Str::uuid();
        AuditLog::create([
            'tenant_id' => $t1->id,
            'entity_type' => 'Sale',
            'entity_id' => $uid,
            'action' => 'POST',
            'user_id' => $platformUser->id,
            'user_email' => $platformUser->email,
            'metadata' => null,
        ]);
        AuditLog::create([
            'tenant_id' => $t2->id,
            'entity_type' => 'Payment',
            'entity_id' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'POST',
            'user_id' => $platformUser->id,
            'user_email' => $platformUser->email,
            'metadata' => null,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->getJson('/api/platform/audit-logs?tenant_id=' . $t2->id);

        $r->assertStatus(200);
        $this->assertCount(1, $r->json('data'));
        $this->assertSame($t2->id, $r->json('data.0.tenant_id'));
        $this->assertSame('Payment', $r->json('data.0.entity_type'));
    }

    /** @test */
    public function audit_logs_pagination(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);
        for ($i = 0; $i < 5; $i++) {
            AuditLog::create([
                'tenant_id' => $tenant->id,
                'entity_type' => 'Sale',
                'entity_id' => (string) \Illuminate\Support\Str::uuid(),
                'action' => 'POST',
                'user_id' => $user->id,
                'user_email' => $user->email,
                'metadata' => null,
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

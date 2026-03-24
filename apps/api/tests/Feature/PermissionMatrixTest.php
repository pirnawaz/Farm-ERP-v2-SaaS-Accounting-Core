<?php

namespace Tests\Feature;

use App\Models\Module;
use Database\Seeders\ModulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTenantWithRoleUsers;

/**
 * Table-driven permission matrix: for each role, assert expected HTTP status on key routes.
 * Update the matrix when permission rules change. Uses dev identity headers (testing env).
 */
class PermissionMatrixTest extends TestCase
{
    use CreatesTenantWithRoleUsers;
    use RefreshDatabase;

    private string $platformUserId = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        (new ModulesSeeder)->run();
        $this->tenantWithRoleUsers();
    }

    /**
     * Each row: [ role, context, route, method, expectedStatus, body? ].
     * @return array<string, array<int, mixed>>
     */
    public static function permissionMatrix(): array
    {
        return [
            'tenant_admin can list users' => ['tenant_admin', 'tenant', '/api/tenant/users', 'GET', 200, []],
            'tenant_admin can list modules' => ['tenant_admin', 'tenant', '/api/tenant/modules', 'GET', 200, []],
            'tenant_admin can update modules' => ['tenant_admin', 'tenant', '/api/tenant/modules', 'PUT', 200, ['modules' => [['key' => 'projects_crop_cycles', 'enabled' => true]]]],
            'tenant_admin cannot access platform tenants' => ['tenant_admin', 'platform', '/api/platform/tenants', 'GET', 403, []],
            'accountant cannot list users' => ['accountant', 'tenant', '/api/tenant/users', 'GET', 403, []],
            'accountant can list modules' => ['accountant', 'tenant', '/api/tenant/modules', 'GET', 200, []],
            'accountant cannot update modules' => ['accountant', 'tenant', '/api/tenant/modules', 'PUT', 403, ['modules' => [['key' => 'projects_crop_cycles', 'enabled' => true]]]],
            'accountant cannot access platform tenants' => ['accountant', 'platform', '/api/platform/tenants', 'GET', 403, []],
            'accountant can access dashboard' => ['accountant', 'tenant', '/api/dashboard/summary', 'GET', 200, []],
            'operator cannot list users' => ['operator', 'tenant', '/api/tenant/users', 'GET', 403, []],
            'operator can list modules' => ['operator', 'tenant', '/api/tenant/modules', 'GET', 200, []],
            'operator can access dashboard' => ['operator', 'tenant', '/api/dashboard/summary', 'GET', 200, []],
            'platform_admin can access platform tenants' => ['platform_admin', 'platform', '/api/platform/tenants', 'GET', 200, []],
        ];
    }

    /** @dataProvider permissionMatrix */
    public function test_permission_matrix(string $role, string $context, string $route, string $method, int $expected, array $body): void
    {
        $headers = $context === 'tenant'
            ? $this->tenantRoleHeaders($role)
            : [
                'X-User-Id' => $this->platformUserId,
                'X-User-Role' => $role,
            ];

        $req = $this->withHeaders($headers);
        if ($method === 'GET') {
            $r = $req->getJson($route);
        } elseif ($method === 'PUT') {
            $r = $req->putJson($route, $body);
        } else {
            $this->fail("Unsupported method: {$method}");
        }
        $r->assertStatus($expected);
    }
}

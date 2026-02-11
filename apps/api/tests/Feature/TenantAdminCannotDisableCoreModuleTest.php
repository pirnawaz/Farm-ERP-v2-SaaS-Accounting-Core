<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAdminCannotDisableCoreModuleTest extends TestCase
{
    use RefreshDatabase;

    private const CORE_MODULE_KEYS = ['accounting_core', 'projects_crop_cycles', 'reports', 'treasury_payments'];

    /**
     * @dataProvider coreModuleKeysProvider
     */
    public function test_tenant_admin_cannot_disable_core_module(string $coreKey): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => $coreKey, 'enabled' => false]],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'MODULE_DEPENDENCY', 'message' => 'Core modules cannot be disabled.']);

        $get = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');
        $get->assertStatus(200);
        $core = collect($get->json('modules'))->firstWhere('key', $coreKey);
        $this->assertNotNull($core);
        $this->assertTrue($core['enabled']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function coreModuleKeysProvider(): array
    {
        $cases = [];
        foreach (self::CORE_MODULE_KEYS as $key) {
            $cases["core_module_{$key}"] = [$key];
        }
        return $cases;
    }
}

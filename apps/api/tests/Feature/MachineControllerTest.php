<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\Machine;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\ModulesSeeder;

class MachineControllerTest extends TestCase
{
    use RefreshDatabase;

    private function enableMachinery(Tenant $tenant): void
    {
        $m = Module::where('key', 'machinery')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    public function test_machine_create_without_code_auto_generates(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        $this->enableMachinery($tenant);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'operator')
            ->postJson('/api/v1/machinery/machines', [
                'name' => 'Tractor 1',
                'machine_type' => 'Tractor',
                'ownership_type' => 'Owned',
                'is_active' => true,
                'meter_unit' => 'HOURS',
                'opening_meter' => 0,
            ]);

        $create->assertStatus(201);
        $this->assertMatchesRegularExpression('/^MCH-\d{6}$/', $create->json('code'));
        $this->assertTrue($create->json('is_active'));
    }

    public function test_machine_create_with_is_active_false(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        $this->enableMachinery($tenant);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'operator')
            ->postJson('/api/v1/machinery/machines', [
                'name' => 'Tractor 2',
                'machine_type' => 'Tractor',
                'ownership_type' => 'Owned',
                'is_active' => false,
                'meter_unit' => 'HOURS',
                'opening_meter' => 0,
            ]);

        $create->assertStatus(201);
        $this->assertFalse($create->json('is_active'));
    }

    public function test_machine_create_sequential_auto_codes_are_unique(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        $this->enableMachinery($tenant);

        $first = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'operator')
            ->postJson('/api/v1/machinery/machines', [
                'name' => 'Tractor A',
                'machine_type' => 'Tractor',
                'ownership_type' => 'Owned',
                'meter_unit' => 'HOURS',
            ]);
        $first->assertStatus(201);
        $code1 = $first->json('code');

        $second = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'operator')
            ->postJson('/api/v1/machinery/machines', [
                'name' => 'Tractor B',
                'machine_type' => 'Tractor',
                'ownership_type' => 'Owned',
                'meter_unit' => 'HOURS',
            ]);
        $second->assertStatus(201);
        $code2 = $second->json('code');

        $this->assertNotSame($code1, $code2);
        $this->assertMatchesRegularExpression('/^MCH-\d{6}$/', $code1);
        $this->assertMatchesRegularExpression('/^MCH-\d{6}$/', $code2);
    }

    public function test_machine_create_duplicate_code_returns_422(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        $this->enableMachinery($tenant);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'operator')
            ->postJson('/api/v1/machinery/machines', [
                'name' => 'Tractor 1',
                'code' => 'MCH-000001',
                'machine_type' => 'Tractor',
                'ownership_type' => 'Owned',
                'meter_unit' => 'HOURS',
            ])->assertStatus(201);

        $duplicate = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'operator')
            ->postJson('/api/v1/machinery/machines', [
                'name' => 'Tractor 2',
                'code' => 'MCH-000001',
                'machine_type' => 'Tractor',
                'ownership_type' => 'Owned',
                'meter_unit' => 'HOURS',
            ]);
        $duplicate->assertStatus(422);
    }
}

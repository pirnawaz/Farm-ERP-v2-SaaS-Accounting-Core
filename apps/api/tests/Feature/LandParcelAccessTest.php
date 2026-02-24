<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\LandParcel;
use App\Models\LandAllocation;
use App\Models\CropCycle;
use App\Models\LandParcelAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandParcelAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private LandParcel $parcel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $landModule = Module::where('key', 'land')->first();
        $this->assertNotNull($landModule, 'Land module must exist from migrations');
        TenantModule::create([
            'tenant_id' => $this->tenant->id,
            'module_id' => $landModule->id,
            'status' => 'ENABLED',
            'enabled_at' => now(),
        ]);
        $this->parcel = LandParcel::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parcel One',
            'total_acres' => 50.00,
        ]);
    }

    private function headers(string $role, ?string $userId = null): array
    {
        $h = [
            'X-Tenant-Id' => $this->tenant->id,
            'X-User-Role' => $role,
        ];
        if ($userId !== null) {
            $h['X-User-Id'] = $userId;
        }
        return $h;
    }

    public function test_operator_can_get_land_parcels_index(): void
    {
        $response = $this->withHeaders($this->headers('operator'))
            ->getJson('/api/land-parcels');
        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Parcel One');
    }

    public function test_operator_can_get_land_parcel_show(): void
    {
        $response = $this->withHeaders($this->headers('operator'))
            ->getJson("/api/land-parcels/{$this->parcel->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('name', 'Parcel One');
        $response->assertJsonPath('total_acres', '50.00');
    }

    public function test_operator_cannot_create_land_parcel(): void
    {
        $response = $this->withHeaders($this->headers('operator'))
            ->postJson('/api/land-parcels', [
                'name' => 'New Parcel',
                'total_acres' => 25.00,
            ]);
        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Insufficient permissions');
    }

    public function test_operator_cannot_update_land_parcel(): void
    {
        $response = $this->withHeaders($this->headers('operator'))
            ->patchJson("/api/land-parcels/{$this->parcel->id}", [
                'name' => 'Updated Name',
                'total_acres' => 60.00,
            ]);
        $response->assertStatus(403);
    }

    public function test_operator_cannot_delete_land_parcel(): void
    {
        $response = $this->withHeaders($this->headers('operator'))
            ->deleteJson("/api/land-parcels/{$this->parcel->id}");
        $response->assertStatus(403);
    }

    public function test_tenant_admin_can_create_land_parcel(): void
    {
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/land-parcels', [
                'name' => 'Admin Parcel',
                'total_acres' => 30.00,
            ]);
        $response->assertStatus(201);
        $response->assertJsonPath('name', 'Admin Parcel');
        $this->assertDatabaseHas('land_parcels', ['name' => 'Admin Parcel']);
    }

    public function test_tenant_admin_can_update_land_parcel(): void
    {
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->patchJson("/api/land-parcels/{$this->parcel->id}", [
                'name' => 'Renamed Parcel',
                'total_acres' => 55.00,
            ]);
        $response->assertStatus(200);
        $response->assertJsonPath('name', 'Renamed Parcel');
    }

    public function test_tenant_admin_can_soft_delete_land_parcel(): void
    {
        $id = $this->parcel->id;
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->deleteJson("/api/land-parcels/{$id}");
        $response->assertStatus(204);
        $this->assertSoftDeleted('land_parcels', ['id' => $id]);

        $indexResponse = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/land-parcels');
        $indexResponse->assertStatus(200);
        $indexResponse->assertJsonCount(0);

        $showResponse = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson("/api/land-parcels/{$id}");
        $showResponse->assertStatus(404);
    }

    public function test_cannot_delete_land_parcel_with_allocations(): void
    {
        $cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'land_parcel_id' => $this->parcel->id,
            'party_id' => null,
            'allocated_acres' => 10.00,
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->deleteJson("/api/land-parcels/{$this->parcel->id}");
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Cannot delete a land parcel that has allocations. Remove or reassign allocations first.');
    }

    public function test_accountant_can_create_and_update_land_parcel(): void
    {
        $create = $this->withHeaders($this->headers('accountant'))
            ->postJson('/api/land-parcels', [
                'name' => 'Accountant Parcel',
                'total_acres' => 20.00,
            ]);
        $create->assertStatus(201);
        $id = $create->json('id');

        $update = $this->withHeaders($this->headers('accountant'))
            ->patchJson("/api/land-parcels/{$id}", ['name' => 'Accountant Parcel Updated']);
        $update->assertStatus(200);
        $update->assertJsonPath('name', 'Accountant Parcel Updated');
    }

    public function test_cannot_reduce_total_acres_below_allocated(): void
    {
        $parcel = LandParcel::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parcel 100',
            'total_acres' => 100.00,
        ]);
        $cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 60.00,
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->patchJson("/api/land-parcels/{$parcel->id}", [
                'name' => $parcel->name,
                'total_acres' => 50,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Total acres cannot be less than allocated acres (allocated: 60).');
    }

    public function test_audit_log_created_when_total_acres_changes(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@test.example',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $response = $this->withHeaders($this->headers('tenant_admin', $user->id))
            ->patchJson("/api/land-parcels/{$this->parcel->id}", [
                'name' => $this->parcel->name,
                'total_acres' => 75.00,
                'notes' => $this->parcel->notes,
            ]);
        $response->assertStatus(200);

        $log = LandParcelAuditLog::where('land_parcel_id', $this->parcel->id)
            ->where('field_name', 'total_acres')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertSame($user->id, $log->changed_by_user_id);
        $this->assertSame('tenant_admin', $log->changed_by_role);
        $this->assertSame('50.00', $log->old_value);
        $this->assertSame('75', $log->new_value);
    }

    public function test_audit_endpoint_returns_logs(): void
    {
        LandParcelAuditLog::create([
            'tenant_id' => $this->tenant->id,
            'land_parcel_id' => $this->parcel->id,
            'changed_by_user_id' => 'u1',
            'changed_by_role' => 'accountant',
            'field_name' => 'name',
            'old_value' => 'Old',
            'new_value' => 'New',
            'changed_at' => now(),
        ]);
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson("/api/land-parcels/{$this->parcel->id}/audit");
        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.field_name', 'name');
        $response->assertJsonPath('0.old_value', 'Old');
        $response->assertJsonPath('0.new_value', 'New');
    }

    public function test_when_land_documents_disabled_document_endpoints_return_404(): void
    {
        // With LAND_DOCUMENTS_ENABLED=false (default), document routes are not registered
        $id = $this->parcel->id;

        $list = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson("/api/land-parcels/{$id}/documents");
        $list->assertStatus(404);

        $store = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson("/api/land-parcels/{$id}/documents", [
                'file_path' => '/some/path.pdf',
                'description' => 'Test',
            ]);
        $store->assertStatus(404);
    }
}

<?php

namespace Tests\Feature;

use App\Models\LivestockEvent;
use App\Models\Module;
use App\Models\ProductionUnit;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LivestockEventTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private string $livestockUnitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Livestock Tenant', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);

        $module = Module::where('key', 'projects_crop_cycles')->first();
        $this->assertNotNull($module, 'projects_crop_cycles module must exist');
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'module_id' => $module->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->otherTenant->id, 'module_id' => $module->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );

        $this->livestockUnitId = ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Goat Herd A',
            'type' => 'LONG_CYCLE',
            'start_date' => '2024-01-01',
            'status' => 'ACTIVE',
            'category' => 'LIVESTOCK',
            'livestock_type' => 'GOAT',
            'herd_start_count' => 10,
        ])->id;
    }

    private function headers(string $role, ?string $tenantId = null): array
    {
        return [
            'X-Tenant-Id' => $tenantId ?? $this->tenant->id,
            'X-User-Role' => $role,
        ];
    }

    public function test_index_returns_only_tenant_events(): void
    {
        LivestockEvent::create([
            'tenant_id' => $this->tenant->id,
            'production_unit_id' => $this->livestockUnitId,
            'event_date' => '2024-06-01',
            'event_type' => 'BIRTH',
            'quantity' => 2,
        ]);
        $otherUnitId = ProductionUnit::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other Herd',
            'type' => 'LONG_CYCLE',
            'start_date' => '2024-01-01',
            'status' => 'ACTIVE',
            'category' => 'LIVESTOCK',
        ])->id;
        LivestockEvent::create([
            'tenant_id' => $this->otherTenant->id,
            'production_unit_id' => $otherUnitId,
            'event_date' => '2024-06-01',
            'event_type' => 'BIRTH',
            'quantity' => 1,
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/livestock-events');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame(2, $data[0]['quantity']);
    }

    public function test_index_filters_by_production_unit_and_dates(): void
    {
        LivestockEvent::create([
            'tenant_id' => $this->tenant->id,
            'production_unit_id' => $this->livestockUnitId,
            'event_date' => '2024-06-15',
            'event_type' => 'PURCHASE',
            'quantity' => 5,
        ]);
        LivestockEvent::create([
            'tenant_id' => $this->tenant->id,
            'production_unit_id' => $this->livestockUnitId,
            'event_date' => '2024-08-01',
            'event_type' => 'SALE',
            'quantity' => -2,
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/livestock-events?production_unit_id=' . $this->livestockUnitId . '&from=2024-06-01&to=2024-07-31');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertSame('2024-06-15', substr($data[0]['event_date'], 0, 10));
    }

    public function test_store_creates_event_and_enforces_livestock_category(): void
    {
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/livestock-events', [
                'production_unit_id' => $this->livestockUnitId,
                'event_date' => '2024-07-01',
                'event_type' => 'BIRTH',
                'quantity' => 3,
            ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertArrayHasKey('id', $data);
        $this->assertSame($this->livestockUnitId, $data['production_unit_id']);
        $this->assertSame('BIRTH', $data['event_type']);
        $this->assertSame(3, $data['quantity']);

        $nonLivestockId = ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sugarcane',
            'type' => 'LONG_CYCLE',
            'start_date' => '2024-01-01',
            'status' => 'ACTIVE',
        ])->id;
        $response2 = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/livestock-events', [
                'production_unit_id' => $nonLivestockId,
                'event_date' => '2024-07-01',
                'event_type' => 'BIRTH',
                'quantity' => 1,
            ]);
        $response2->assertStatus(422);
        $response2->assertJsonFragment(['production_unit_id' => ['Production unit must have category LIVESTOCK.']]);
    }

    public function test_store_sale_death_stores_negative_quantity(): void
    {
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/livestock-events', [
                'production_unit_id' => $this->livestockUnitId,
                'event_date' => '2024-07-01',
                'event_type' => 'SALE',
                'quantity' => 2,
            ]);
        $response->assertStatus(201);
        $this->assertSame(-2, $response->json('quantity'));
    }

    public function test_update_and_destroy_tenant_scoped(): void
    {
        $event = LivestockEvent::create([
            'tenant_id' => $this->tenant->id,
            'production_unit_id' => $this->livestockUnitId,
            'event_date' => '2024-06-01',
            'event_type' => 'BIRTH',
            'quantity' => 1,
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->patchJson('/api/livestock-events/' . $event->id, [
                'event_date' => '2024-06-02',
                'quantity' => 2,
            ]);
        $response->assertStatus(200);
        $this->assertSame(2, $response->json('quantity'));

        $response2 = $this->withHeaders($this->headers('tenant_admin'))
            ->deleteJson('/api/livestock-events/' . $event->id);
        $response2->assertStatus(204);
        $this->assertDatabaseMissing('livestock_events', ['id' => $event->id]);
    }

    public function test_show_returns_404_for_other_tenant_event(): void
    {
        $otherUnitId = ProductionUnit::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other Herd',
            'type' => 'LONG_CYCLE',
            'start_date' => '2024-01-01',
            'status' => 'ACTIVE',
            'category' => 'LIVESTOCK',
        ])->id;
        $otherEvent = LivestockEvent::create([
            'tenant_id' => $this->otherTenant->id,
            'production_unit_id' => $otherUnitId,
            'event_date' => '2024-06-01',
            'event_type' => 'BIRTH',
            'quantity' => 1,
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/livestock-events/' . $otherEvent->id);
        $response->assertStatus(404);
    }
}

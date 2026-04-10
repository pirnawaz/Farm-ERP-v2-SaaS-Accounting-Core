<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\HarvestShareLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\Machine;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3C.6 — Reporting / source-tracking alignment for share-aware harvest (narrow patches only).
 */
class HarvestShareReportingPhase3c6Test extends TestCase
{
    use RefreshDatabase;

    private function enableModule(Tenant $tenant, string $key): void
    {
        $m = Module::where('key', $key)->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    private function seedWip(Tenant $tenant, CropCycle $cropCycle, float $amount, string $postingDate = '2024-05-01'): void
    {
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => Str::uuid()->toString(),
            'posting_date' => $postingDate,
            'idempotency_key' => 'wip-3c6-'.uniqid(),
        ]);

        $wip = Account::where('tenant_id', $tenant->id)->where('code', 'CROP_WIP')->first();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();

        \App\Models\LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $wip->id,
            'debit_amount' => (string) $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        \App\Models\LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $cash->id,
            'debit_amount' => 0,
            'credit_amount' => (string) $amount,
            'currency_code' => 'GBP',
        ]);
    }

    /**
     * cost-per-unit must attribute WIP to lines using HARVEST_PRODUCTION caps only — not HARVEST_IN_KIND_* rows
     * (same value would double-count inventory vs in-kind settlement).
     */
    public function test_cost_per_unit_uses_harvest_production_only_not_in_kind_rows(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Rpt 3C6', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'crop_ops');

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pool',
            'party_types' => ['HARI'],
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'P1',
            'status' => 'ACTIVE',
        ]);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Produce']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Grain',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'type' => 'MAIN', 'is_active' => true]);
        $storeM = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Mach', 'type' => 'MAIN', 'is_active' => true]);

        $this->seedWip($tenant, $cropCycle, 500.0);

        $harvest = Harvest::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $line = HarvestLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $item->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'uom' => 'BAG',
        ]);

        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'M-RPT',
            'name' => 'Harvester',
            'machine_type' => 'HARVESTER',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
            'is_active' => true,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeM->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 1,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->headers($tenant))
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $pg = PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'HARVEST')->where('source_id', $harvest->id)->first();
        $this->assertNotNull($pg);

        $sumProduction = (float) AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'HARVEST_PRODUCTION')->sum('amount');
        $sumInKind = (float) AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'HARVEST_IN_KIND_MACHINE')->sum('amount');
        $this->assertEqualsWithDelta(500.0, $sumProduction, 0.02);
        $this->assertEqualsWithDelta(50.0, $sumInKind, 0.02);

        $res = $this->withHeaders($this->headers($tenant))
            ->getJson('/api/reports/cost-per-unit?crop_cycle_id='.$cropCycle->id);
        $res->assertStatus(200);
        $rows = $res->json();
        $this->assertIsArray($rows);
        $grain = collect($rows)->firstWhere('item_id', $item->id);
        $this->assertNotNull($grain);
        $this->assertEqualsWithDelta(500.0, (float) $grain['total_cost'], 0.05, 'Must not add HARVEST_IN_KIND amounts into line cost');
        $this->assertEqualsWithDelta(10.0, (float) $grain['total_qty'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $grain['cost_per_unit'], 0.05);
    }

    public function test_machinery_profitability_includes_harvest_in_kind_machine_in_revenue(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Rpt Mach', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'machinery');

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pool',
            'party_types' => ['HARI'],
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'P1',
            'status' => 'ACTIVE',
        ]);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Produce']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Grain',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'type' => 'MAIN', 'is_active' => true]);
        $storeM = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Mach', 'type' => 'MAIN', 'is_active' => true]);

        $this->seedWip($tenant, $cropCycle, 500.0);

        $harvest = Harvest::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $line = HarvestLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $item->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'uom' => 'BAG',
        ]);

        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'M-REV',
            'name' => 'Harvester',
            'machine_type' => 'HARVESTER',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
            'is_active' => true,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeM->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 1,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->headers($tenant))
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $ink = (float) AllocationRow::where('tenant_id', $tenant->id)
            ->where('allocation_type', 'HARVEST_IN_KIND_MACHINE')
            ->where('machine_id', $machine->id)
            ->value('amount');
        $this->assertEqualsWithDelta(50.0, $ink, 0.02);

        $res = $this->withHeaders($this->headers($tenant))
            ->getJson('/api/v1/machinery/reports/profitability?from=2024-06-15&to=2024-06-15');
        $res->assertStatus(200);
        $data = $res->json();
        $row = collect($data)->firstWhere('machine_id', $machine->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(50.0, (float) $row['charges_total'], 0.05, 'In-kind harvest share posts machinery service income');
        $this->assertEqualsWithDelta(0.0, (float) $row['costs_total'], 0.05, 'Harvest production caps must not inflate machine costs');
    }
}

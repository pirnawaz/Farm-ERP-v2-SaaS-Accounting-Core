<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\HarvestShareLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\LedgerEntry;
use App\Models\Machine;
use App\Models\MachineRateCard;
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

class DuplicateWorkflowGuardTest extends TestCase
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

    private function seedWip(Tenant $tenant, CropCycle $cropCycle, float $amount = 500.0): void
    {
        $cropWip = Account::where('tenant_id', $tenant->id)->where('code', 'CROP_WIP')->firstOrFail();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->firstOrFail();
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => Str::uuid()->toString(),
            'posting_date' => '2024-05-01',
            'idempotency_key' => 'wip-dup-'.uniqid(),
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $cropWip->id,
            'debit_amount' => (string) $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $cash->id,
            'debit_amount' => 0,
            'credit_amount' => (string) $amount,
            'currency_code' => 'GBP',
        ]);
    }

    public function test_posting_harvest_in_kind_machine_fails_when_field_job_machine_already_posted(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'DupGuard-1', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'machinery');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'B', 'name' => 'B']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'C']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'Grain', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'S', 'type' => 'MAIN', 'is_active' => true]);

        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'C', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'H', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'P', 'status' => 'ACTIVE',
        ]);
        $this->seedWip($tenant, $cc);
        $machine = Machine::create([
            'tenant_id' => $tenant->id, 'code' => 'TR-DG1', 'name' => 'T', 'machine_type' => 'Tractor',
            'ownership_type' => 'Owned', 'status' => 'Active', 'meter_unit' => 'HOURS', 'opening_meter' => 0,
        ]);
        MachineRateCard::create([
            'tenant_id' => $tenant->id, 'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id, 'effective_from' => '2024-01-01', 'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR, 'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 40, 'is_active' => true,
        ]);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15', 'project_id' => $project->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machine->id, 'usage_qty' => 2,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => 'fj-dup-'.uniqid(),
        ])->assertStatus(201);

        $storeMachine = InvStore::create([
            'tenant_id' => $tenant->id, 'name' => 'Machine bin 1', 'type' => 'MAIN', 'is_active' => true,
        ]);

        $harvest = Harvest::create([
            'tenant_id' => $tenant->id, 'crop_cycle_id' => $cc->id, 'project_id' => $project->id,
            'harvest_date' => '2024-06-15', 'status' => 'DRAFT',
        ]);
        $hLine = HarvestLine::create([
            'tenant_id' => $tenant->id, 'harvest_id' => $harvest->id, 'inventory_item_id' => $item->id,
            'store_id' => $store->id, 'quantity' => 100, 'uom' => 'BAG',
        ]);
        HarvestShareLine::create([
            'tenant_id' => $tenant->id, 'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeMachine->id,
            'inventory_item_id' => $item->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 10,
            'sort_order' => 1,
        ]);

        $postHarvest = $this->withHeaders($h)->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => 'hv-dup-'.uniqid(),
        ]);
        $postHarvest->assertStatus(422);
        $postHarvest->assertJsonValidationErrors(['duplicate_workflow']);
    }

    public function test_posting_field_job_machine_fails_when_harvest_in_kind_machine_already_posted(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'DupGuard-2', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'machinery');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'B2', 'name' => 'B']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'C2']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'Grain', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'S2', 'type' => 'MAIN', 'is_active' => true]);

        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'C2', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'H2', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'P2', 'status' => 'ACTIVE',
        ]);
        $this->seedWip($tenant, $cc);
        $machine = Machine::create([
            'tenant_id' => $tenant->id, 'code' => 'TR-DG2', 'name' => 'T2', 'machine_type' => 'Tractor',
            'ownership_type' => 'Owned', 'status' => 'Active', 'meter_unit' => 'HOURS', 'opening_meter' => 0,
        ]);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $storeMachine = InvStore::create([
            'tenant_id' => $tenant->id, 'name' => 'Machine bin 2', 'type' => 'MAIN', 'is_active' => true,
        ]);

        $harvest = Harvest::create([
            'tenant_id' => $tenant->id, 'crop_cycle_id' => $cc->id, 'project_id' => $project->id,
            'harvest_date' => '2024-06-15', 'status' => 'DRAFT',
        ]);
        $hLine = HarvestLine::create([
            'tenant_id' => $tenant->id, 'harvest_id' => $harvest->id, 'inventory_item_id' => $item->id,
            'store_id' => $store->id, 'quantity' => 100, 'uom' => 'BAG',
        ]);
        HarvestShareLine::create([
            'tenant_id' => $tenant->id, 'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeMachine->id,
            'inventory_item_id' => $item->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 10,
            'sort_order' => 1,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => 'hv-first-'.uniqid(),
        ])->assertStatus(200);

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15', 'project_id' => $project->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        MachineRateCard::create([
            'tenant_id' => $tenant->id, 'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id, 'effective_from' => '2024-01-01', 'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR, 'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 40, 'is_active' => true,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machine->id, 'usage_qty' => 2,
        ])->assertStatus(201);

        $postFj = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => 'fj-second-'.uniqid(),
        ]);
        $postFj->assertStatus(422);
        $postFj->assertJsonValidationErrors(['duplicate_workflow']);
    }
}

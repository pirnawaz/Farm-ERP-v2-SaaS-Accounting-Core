<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\HarvestShareLine;
use App\Models\InvStockMovement;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\LedgerEntry;
use App\Models\Machine;
use App\Models\MachineRateCard;
use App\Models\Module;
use App\Models\Party;
use App\Models\AllocationRow;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\SuggestionService;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5G.1 — Operator workflow safety: field job → harvest → suggestions → share lines → post,
 * with duplicate-workflow guard and accounting assertions.
 */
class OperatorWorkflowPhase5g1Test extends TestCase
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
            'idempotency_key' => 'wip-5g1-'.uniqid(),
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

    private function assertLedgerBalancedForPostingGroup(string $postingGroupId): void
    {
        $entries = LedgerEntry::where('posting_group_id', $postingGroupId)->get();
        $this->assertGreaterThan(0, $entries->count(), 'Expected ledger lines for posting group');
        $dr = (float) $entries->sum('debit_amount');
        $cr = (float) $entries->sum('credit_amount');
        $this->assertEqualsWithDelta($dr, $cr, 0.0001, 'Ledger must balance (debits = credits)');
    }

    public function test_field_job_harvest_suggestions_share_post_single_harvest_gl_balanced_inventory_split(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'OpFlow-5G1', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'machinery');

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
        $storeMachine = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Machine bin',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $cc = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Hari', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'North',
            'status' => 'ACTIVE',
        ]);
        $this->seedWip($tenant, $cc, 500.0);

        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TR-5G1',
            'name' => 'Tractor',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);
        MachineRateCard::create([
            'tenant_id' => $tenant->id,
            'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
            'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 40,
            'is_active' => true,
        ]);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-10',
            'project_id' => $project->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machine->id,
            'usage_qty' => 2,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-10',
            'idempotency_key' => 'fj-5g1-'.uniqid(),
        ])->assertStatus(201);

        $this->assertSame(
            1,
            PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->count()
        );

        $harvestRes = $this->withHeaders($h)->postJson('/api/v1/crop-ops/harvests', [
            'crop_cycle_id' => $cc->id,
            'project_id' => $project->id,
            'harvest_date' => '2024-06-15',
        ]);
        $harvestRes->assertStatus(201);
        $harvestId = $harvestRes->json('id');

        $lineRes = $this->withHeaders($h)->postJson("/api/v1/crop-ops/harvests/{$harvestId}/lines", [
            'inventory_item_id' => $item->id,
            'store_id' => $store->id,
            'quantity' => 10,
            'uom' => 'BAG',
        ]);
        $lineRes->assertStatus(201);
        $harvestLineId = $lineRes->json('id');

        $sug = $this->withHeaders($h)->getJson("/api/v1/crop-ops/harvests/{$harvestId}/suggestions");
        $sug->assertOk();
        $sug->assertJsonPath('confidence', SuggestionService::CONFIDENCE_MEDIUM);
        $machineSuggestions = $sug->json('machine_suggestions');
        $this->assertCount(1, $machineSuggestions);
        $this->assertSame($jobId, $machineSuggestions[0]['field_job_id']);
        $m0 = $machineSuggestions[0];
        $this->assertEqualsWithDelta(
            (float) $m0['suggested_ratio_numerator'],
            (float) $m0['suggested_ratio_denominator'],
            0.000001,
            'Single machine line: usage pool ratio numerator should equal denominator'
        );
        $sharePayload = [
            'harvest_line_id' => $harvestLineId,
            'recipient_role' => 'MACHINE',
            'settlement_mode' => 'IN_KIND',
            'share_basis' => 'RATIO',
            'ratio_numerator' => (float) $m0['suggested_ratio_numerator'],
            'ratio_denominator' => (float) $m0['suggested_ratio_denominator'],
            'machine_id' => $m0['machine_id'],
            'source_field_job_id' => $jobId,
            'store_id' => $storeMachine->id,
            'inventory_item_id' => $item->id,
            'sort_order' => 1,
        ];
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/harvests/{$harvestId}/share-lines", $sharePayload)->assertStatus(201);

        $postH = $this->withHeaders($h)->postJson("/api/v1/crop-ops/harvests/{$harvestId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'hv-5g1-'.uniqid(),
        ]);
        $postH->assertStatus(200);

        $harvestPg = PostingGroup::where('tenant_id', $tenant->id)
            ->where('source_type', 'HARVEST')
            ->where('source_id', $harvestId)
            ->get();
        $this->assertCount(1, $harvestPg, 'Exactly one posting group for harvest (no duplicate GL path)');

        $pgId = $harvestPg->first()->id;
        $this->assertLedgerBalancedForPostingGroup($pgId);

        $ink = AllocationRow::where('posting_group_id', $pgId)->where('allocation_type', 'HARVEST_IN_KIND_MACHINE')->count();
        $this->assertSame(1, $ink, 'Machine in-kind allocation recorded');

        $harvestMoves = InvStockMovement::where('posting_group_id', $pgId)->where('movement_type', 'HARVEST')->get();
        $this->assertGreaterThanOrEqual(1, $harvestMoves->count());
        $this->assertEqualsWithDelta(10.0, (float) $harvestMoves->sum(fn ($m) => abs((float) $m->qty_delta)), 0.001);

        $sl = HarvestShareLine::where('harvest_id', $harvestId)->first();
        $this->assertNotNull($sl);
        $this->assertSame($jobId, $sl->source_field_job_id);
        $this->assertGreaterThan(0, (float) $sl->computed_qty);
        $this->assertGreaterThan(0, (float) $sl->computed_value_snapshot);
        $this->assertEqualsWithDelta(
            (float) $sl->computed_qty * 50.0,
            (float) $sl->computed_value_snapshot,
            0.02,
            'Share value should align with WAC ($50/bag over 10 bags)'
        );

        $harvestModel = Harvest::find($harvestId);
        $this->assertSame('POSTED', $harvestModel->status);
    }

    public function test_duplicate_workflow_guard_blocks_harvest_post_without_source_field_job_when_field_job_machine_already_posted(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'OpFlow-Dup', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'machinery');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BX', 'name' => 'Bx']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'C']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'G',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'S', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'C',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'H', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'P',
            'status' => 'ACTIVE',
        ]);
        $this->seedWip($tenant, $cc, 100.0);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TR-D',
            'name' => 'T',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);
        MachineRateCard::create([
            'tenant_id' => $tenant->id,
            'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
            'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 40,
            'is_active' => true,
        ]);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $project->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machine->id,
            'usage_qty' => 2,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-dupg-'.uniqid(),
        ])->assertStatus(201);

        $storeMachine = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'MB',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $harvest = Harvest::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'project_id' => $project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);
        $hLine = HarvestLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $item->id,
            'store_id' => $store->id,
            'quantity' => 100,
            'uom' => 'BAG',
        ]);
        HarvestShareLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest->id,
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

        $fail = $this->withHeaders($h)->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'hv-dup-'.uniqid(),
        ]);
        $fail->assertStatus(422);
        $fail->assertJsonValidationErrors(['duplicate_workflow']);

        $ledgerBefore = LedgerEntry::where('tenant_id', $tenant->id)->count();
        $this->assertSame(
            0,
            PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'HARVEST')->where('source_id', $harvest->id)->count()
        );
        $this->assertSame($ledgerBefore, LedgerEntry::where('tenant_id', $tenant->id)->count());
    }
}

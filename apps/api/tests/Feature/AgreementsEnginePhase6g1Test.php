<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\CropCycle;
use App\Models\FieldJob;
use App\Models\FieldJobMachine;
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
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\AgreementResolver;
use App\Services\SuggestionService;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6G.1 — Agreements engine: selection, conflict resolution, suggestion override, apply, no retroactive edits.
 */
class AgreementsEnginePhase6g1Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CropCycle $cropCycle;

    private Project $project;

    private Party $party;

    private Machine $machine;

    private InvItem $item;

    private InvStore $store;

    private function enableCropOps(Tenant $tenant): void
    {
        foreach (['crop_ops', 'machinery', 'inventory'] as $key) {
            $m = Module::where('key', $key)->first();
            if ($m) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    private function headers(): array
    {
        return [
            'X-Tenant-Id' => $this->tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Agreements-6G1', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableCropOps($this->tenant);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $this->party = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Partner',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->party->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'North Field',
            'status' => 'ACTIVE',
        ]);

        $this->machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'TR-6G1',
            'name' => 'Tractor',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $uom = InvUom::create(['tenant_id' => $this->tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Produce']);
        $this->item = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Grain',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $this->store = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main',
            'type' => 'MAIN',
            'is_active' => true,
        ]);
    }

    private function createDraftHarvestWithLine(string $harvestDate = '2024-06-15'): Harvest
    {
        $h = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => $harvestDate,
            'status' => 'DRAFT',
        ]);
        HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $h->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 100,
            'uom' => 'BAG',
        ]);

        return $h->fresh();
    }

    private function createFieldJobWithMachineUsage(): FieldJob
    {
        $fj = FieldJob::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'job_date' => '2024-06-10',
            'status' => 'DRAFT',
        ]);
        FieldJobMachine::create([
            'tenant_id' => $this->tenant->id,
            'field_job_id' => $fj->id,
            'machine_id' => $this->machine->id,
            'usage_qty' => 5,
            'meter_unit_snapshot' => 'HOURS',
        ]);

        return $fj->load(['machines.machine', 'labour.worker']);
    }

    public function test_agreement_resolver_selects_matching_active_agreement(): void
    {
        $harvest = $this->createDraftHarvestWithLine();
        Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'project_id' => null,
            'crop_cycle_id' => null,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => '12.5'],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 10,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $resolver = app(AgreementResolver::class);
        $out = $resolver->resolveForHarvest($harvest);

        $this->assertCount(1, $out['machine_agreements']);
        $this->assertSame(AgreementResolver::BASIS_PERCENT, $out['machine_agreements'][0]['basis']);
        $this->assertSame('12.5', $out['machine_agreements'][0]['value']);
        $this->assertSame((string) $this->machine->id, (string) $out['machine_agreements'][0]['machine_id']);
    }

    public function test_agreement_resolver_excludes_inactive_and_out_of_range(): void
    {
        $harvest = $this->createDraftHarvestWithLine('2024-06-15');
        Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => 1],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_INACTIVE,
        ]);
        Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => 2],
            'effective_from' => '2024-07-01',
            'effective_to' => null,
            'priority' => 2,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $resolver = app(AgreementResolver::class);
        $out = $resolver->resolveForHarvest($harvest);

        $this->assertSame([], $out['machine_agreements']);
    }

    public function test_agreement_resolver_conflict_higher_priority_wins(): void
    {
        $harvest = $this->createDraftHarvestWithLine();
        Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => 5],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);
        Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => 20],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 100,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $resolver = app(AgreementResolver::class);
        $out = $resolver->resolveForHarvest($harvest);

        $this->assertCount(1, $out['machine_agreements']);
        $this->assertSame('20', $out['machine_agreements'][0]['value']);
    }

    public function test_agreement_resolver_conflict_specificity_wins_when_priority_tied(): void
    {
        $harvest = $this->createDraftHarvestWithLine();
        Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'project_id' => null,
            'crop_cycle_id' => null,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => 25],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 50,
            'status' => Agreement::STATUS_ACTIVE,
        ]);
        Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => 8],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 50,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $resolver = app(AgreementResolver::class);
        $out = $resolver->resolveForHarvest($harvest);

        $this->assertCount(1, $out['machine_agreements']);
        $this->assertSame('8', $out['machine_agreements'][0]['value']);
    }

    public function test_suggestion_service_machine_agreement_overrides_field_job_ratio(): void
    {
        $this->createFieldJobWithMachineUsage();
        $harvest = $this->createDraftHarvestWithLine();

        Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => 15],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 10,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $svc = app(SuggestionService::class);
        $harvest->load('shareLines');
        $s = $svc->forHarvest($harvest);

        $this->assertCount(1, $s['machine_suggestions']);
        $row = $s['machine_suggestions'][0];
        $this->assertSame(SuggestionService::SOURCE_AGREEMENT, $row['suggestion_source']);
        $this->assertSame(HarvestShareLine::BASIS_PERCENT, $row['suggested_share_basis']);
        $this->assertSame('15', (string) $row['suggested_share_value']);
        $this->assertSame(SuggestionService::CONFIDENCE_HIGH, $s['confidence']);
    }

    public function test_suggestion_service_fallback_field_job_ratio_when_no_agreement(): void
    {
        $this->createFieldJobWithMachineUsage();
        $harvest = $this->createDraftHarvestWithLine();

        $svc = app(SuggestionService::class);
        $harvest->load('shareLines');
        $s = $svc->forHarvest($harvest);

        $this->assertCount(1, $s['machine_suggestions']);
        $row = $s['machine_suggestions'][0];
        $this->assertSame(SuggestionService::SOURCE_FIELD_JOB, $row['suggestion_source']);
        $this->assertSame(HarvestShareLine::BASIS_RATIO, $row['suggested_share_basis']);
        $this->assertEqualsWithDelta(5.0, (float) $row['suggested_ratio_numerator'], 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $row['suggested_ratio_denominator'], 0.001);
    }

    public function test_apply_agreements_endpoint_creates_expected_share_lines(): void
    {
        $this->createFieldJobWithMachineUsage();
        $harvest = $this->createDraftHarvestWithLine();

        Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => 15],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 10,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $res = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/apply-agreements", []);

        $res->assertOk();
        $res->assertJsonPath('created_count', 1);
        $harvestFresh = Harvest::with('shareLines')->findOrFail($harvest->id);
        $this->assertCount(1, $harvestFresh->shareLines);
        $line = $harvestFresh->shareLines->first();
        $this->assertSame(HarvestShareLine::RECIPIENT_MACHINE, $line->recipient_role);
        $this->assertSame(HarvestShareLine::BASIS_PERCENT, $line->share_basis);
        $this->assertEqualsWithDelta(15.0, (float) $line->share_value, 0.0001);
        $this->assertSame($this->machine->id, $line->machine_id);
    }

    public function test_apply_agreements_rejects_without_overwrite_when_share_lines_exist(): void
    {
        $harvest = $this->createDraftHarvestWithLine();
        Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => 10],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $this->withHeaders($this->headers())->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-lines", [
            'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'share_basis' => HarvestShareLine::BASIS_PERCENT,
            'share_value' => 50,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ])->assertStatus(201);

        $res = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/apply-agreements", []);

        $res->assertStatus(422);
        $this->assertNotEmpty($res->json('errors.overwrite'));
    }

    public function test_updating_agreement_does_not_change_existing_draft_share_lines(): void
    {
        $this->createFieldJobWithMachineUsage();
        $harvest = $this->createDraftHarvestWithLine();

        $agreement = Agreement::create([
            'tenant_id' => $this->tenant->id,
            'agreement_type' => Agreement::TYPE_MACHINE_USAGE,
            'machine_id' => $this->machine->id,
            'terms' => ['basis' => 'PERCENT', 'percent' => 10],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 10,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/apply-agreements", [])
            ->assertOk();

        $lineId = HarvestShareLine::where('harvest_id', $harvest->id)->firstOrFail()->id;

        $agreement->update([
            'terms' => ['basis' => 'PERCENT', 'percent' => 99],
        ]);

        $line = HarvestShareLine::findOrFail($lineId);
        $this->assertEqualsWithDelta(10.0, (float) $line->share_value, 0.0001);
        $this->assertSame(HarvestShareLine::BASIS_PERCENT, $line->share_basis);
    }
}

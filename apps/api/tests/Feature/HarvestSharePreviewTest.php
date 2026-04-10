<?php

namespace Tests\Feature;

use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\HarvestShareLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\LabWorker;
use App\Models\LedgerEntry;
use App\Models\Machine;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\Account;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HarvestSharePreviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CropCycle $cropCycle;

    private Project $project;

    private InvItem $item;

    private InvStore $store;

    private Account $cropWipAccount;

    private function enableCropOps(Tenant $tenant): void
    {
        $m = Module::where('key', 'crop_ops')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function headers(string $role = 'accountant'): array
    {
        return [
            'X-Tenant-Id' => $this->tenant->id,
            'X-User-Role' => $role,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Preview Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableCropOps($this->tenant);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $party = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Party',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        $uom = InvUom::create(['tenant_id' => $this->tenant->id, 'code' => 'KG', 'name' => 'Kilogram']);
        $cat = InvItemCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Produce']);
        $this->item = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Wheat',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $this->store = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Store',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $this->cropWipAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CROP_WIP')->first();
    }

    private function seedWip(float $amount, string $postingDate = '2024-05-01'): void
    {
        $pg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => \Illuminate\Support\Str::uuid()->toString(),
            'posting_date' => $postingDate,
            'idempotency_key' => 'wip-preview-'.uniqid(),
        ]);

        $cashAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CASH')->first();

        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $this->cropWipAccount->id,
            'debit_amount' => (string) $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);

        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $cashAccount->id,
            'debit_amount' => 0,
            'credit_amount' => (string) $amount,
            'currency_code' => 'GBP',
        ]);
    }

    public function test_preview_matches_percent_labour_example_from_design_notes(): void
    {
        $this->seedWip(12000.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $hLine = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 1000,
        ]);

        $worker = LabWorker::create([
            'tenant_id' => $this->tenant->id,
            'worker_no' => 'W1',
            'name' => 'Worker One',
            'worker_type' => 'HARI',
            'rate_basis' => 'DAILY',
            'is_active' => true,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_LABOUR,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
            'worker_id' => $worker->id,
            'share_basis' => HarvestShareLine::BASIS_PERCENT,
            'share_value' => 2.5,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview?posting_date=2024-06-15");

        $res->assertStatus(200);
        $this->assertEquals(12000.0, (float) $res->json('total_wip_cost'));
        $buckets = $res->json('share_buckets');
        $this->assertCount(2, $buckets);

        $labour = collect($buckets)->firstWhere('recipient_role', HarvestShareLine::RECIPIENT_LABOUR);
        $this->assertEquals(25.0, (float) $labour['computed_qty']);
        $this->assertEquals(300.0, (float) $labour['provisional_value']);

        $implicit = collect($buckets)->firstWhere('implicit_owner', true);
        $this->assertNotNull($implicit);
        $this->assertEquals(975.0, (float) $implicit['computed_qty']);
        $this->assertEquals(11700.0, (float) $implicit['provisional_value']);

        $this->assertEquals(12000.0, (float) $res->json('totals.sum_bucket_value'));
    }

    public function test_preview_rejects_non_draft_harvest(): void
    {
        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'POSTED',
        ]);

        HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview")
            ->assertStatus(422);
    }

    public function test_preview_rejects_over_allocated_percent(): void
    {
        $this->seedWip(1000.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $hLine = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 100,
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M1',
            'name' => 'Tractor',
            'machine_type' => 'TRACTOR',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
            'is_active' => true,
        ]);

        foreach ([1 => 60.0, 2 => 60.0] as $sort => $pct) {
            HarvestShareLine::create([
                'tenant_id' => $this->tenant->id,
                'harvest_id' => $harvest->id,
                'harvest_line_id' => $hLine->id,
                'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'machine_id' => $machine->id,
                'share_basis' => HarvestShareLine::BASIS_PERCENT,
                'share_value' => $pct,
                'remainder_bucket' => false,
                'sort_order' => $sort,
            ]);
        }

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview")
            ->assertStatus(422);
    }

    public function test_preview_requires_in_kind_store(): void
    {
        $this->seedWip(100.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $hLine = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'share_basis' => HarvestShareLine::BASIS_PERCENT,
            'share_value' => 50,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview")
            ->assertStatus(422);
    }
}

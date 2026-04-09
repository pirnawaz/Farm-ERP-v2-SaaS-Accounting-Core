<?php

namespace Tests\Feature;

use App\Domains\Accounting\FixedAssets\FixedAsset;
use App\Domains\Accounting\FixedAssets\FixedAssetBook;
use App\Domains\Accounting\FixedAssets\FixedAssetDepreciationRun;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\TenantContext;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetDepreciationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        TenantContext::clear();
        parent::setUp();
    }

    /** @return array{tenant: Tenant, project: Project, asset: FixedAsset} */
    private function tenantProjectAndActivatedAsset(): array
    {
        $tenant = Tenant::create(['name' => 'Depr Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'P',
            'party_types' => ['SUPPLIER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Block',
            'status' => 'ACTIVE',
        ]);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-assets', [
                'project_id' => $project->id,
                'asset_code' => 'FA-DEP-1',
                'name' => 'Asset',
                'category' => 'Equipment',
                'acquisition_date' => '2026-03-01',
                'in_service_date' => '2026-03-10',
                'currency_code' => 'GBP',
                'acquisition_cost' => 12000.00,
                'residual_value' => 0,
                'useful_life_months' => 120,
                'depreciation_method' => 'STRAIGHT_LINE',
            ]);
        $create->assertStatus(201);
        $assetId = $create->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$assetId}/activate", [
                'posting_date' => '2026-03-15',
                'idempotency_key' => 'fa-act-dep-1',
                'source_account' => 'BANK',
            ])->assertStatus(201);

        $asset = FixedAsset::findOrFail($assetId);

        return ['tenant' => $tenant, 'project' => $project, 'asset' => $asset];
    }

    public function test_generate_depreciation_run_has_no_ledger_impact(): void
    {
        $x = $this->tenantProjectAndActivatedAsset();
        $tenant = $x['tenant'];

        $before = LedgerEntry::count();

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-asset-depreciation-runs', [
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-31',
            ]);

        $res->assertStatus(201);
        $this->assertSame($before, LedgerEntry::count());
        $this->assertGreaterThan(0, $res->json('lines') ? count($res->json('lines')) : 0);
    }

    public function test_post_depreciation_run_creates_one_posting_group_and_updates_book(): void
    {
        $x = $this->tenantProjectAndActivatedAsset();
        $tenant = $x['tenant'];
        $asset = $x['asset'];

        $gen = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-asset-depreciation-runs', [
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-31',
            ]);
        $gen->assertStatus(201);
        $runId = $gen->json('id');

        $bookBefore = FixedAssetBook::where('fixed_asset_id', $asset->id)->first();
        $this->assertNotNull($bookBefore);
        $carryingBefore = (float) $bookBefore->carrying_amount;

        $ledgerBefore = LedgerEntry::count();

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-asset-depreciation-runs/{$runId}/post", [
                'posting_date' => '2026-03-31',
                'idempotency_key' => 'depr-post-1',
            ]);
        $post->assertStatus(201);
        $pgId = $post->json('id');

        $this->assertSame($ledgerBefore + 2, LedgerEntry::count());

        $sumDr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount');
        $sumCr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount');
        $this->assertEqualsWithDelta($sumDr, $sumCr, 0.02);
        $this->assertGreaterThan(0, $sumDr);

        $bookAfter = FixedAssetBook::where('fixed_asset_id', $asset->id)->first();
        $this->assertLessThan($carryingBefore, (float) $bookAfter->carrying_amount);
        $this->assertGreaterThan((float) $bookBefore->accumulated_depreciation, (float) $bookAfter->accumulated_depreciation);

        $run = FixedAssetDepreciationRun::findOrFail($runId);
        $this->assertSame(FixedAssetDepreciationRun::STATUS_POSTED, $run->status);
        $this->assertSame($pgId, $run->posting_group_id);
    }

    public function test_second_run_for_same_period_excludes_asset_with_posted_overlap(): void
    {
        $x = $this->tenantProjectAndActivatedAsset();
        $tenant = $x['tenant'];

        $gen1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-asset-depreciation-runs', [
                'period_start' => '2026-04-01',
                'period_end' => '2026-04-30',
            ]);
        $run1Id = $gen1->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-asset-depreciation-runs/{$run1Id}/post", [
                'posting_date' => '2026-04-30',
                'idempotency_key' => 'depr-post-apr-1',
            ])->assertStatus(201);

        $gen2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-asset-depreciation-runs', [
                'period_start' => '2026-04-01',
                'period_end' => '2026-04-30',
            ]);
        $gen2->assertStatus(201);
        $lines = $gen2->json('lines') ?? [];
        $this->assertCount(0, $lines);
    }

    public function test_post_is_idempotent(): void
    {
        $x = $this->tenantProjectAndActivatedAsset();
        $tenant = $x['tenant'];

        $gen = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-asset-depreciation-runs', [
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
            ]);
        $runId = $gen->json('id');

        $payload = [
            'posting_date' => '2026-05-31',
            'idempotency_key' => 'idem-depr-1',
        ];

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-asset-depreciation-runs/{$runId}/post", $payload);
        $r1->assertStatus(201);
        $pg1 = $r1->json('id');
        $n = LedgerEntry::count();

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-asset-depreciation-runs/{$runId}/post", $payload);
        $r2->assertStatus(201);
        $this->assertSame($pg1, $r2->json('id'));
        $this->assertSame($n, LedgerEntry::count());
    }
}

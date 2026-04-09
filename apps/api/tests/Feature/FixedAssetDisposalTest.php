<?php

namespace Tests\Feature;

use App\Domains\Accounting\FixedAssets\FixedAsset;
use App\Domains\Accounting\FixedAssets\FixedAssetBook;
use App\Domains\Accounting\FixedAssets\FixedAssetDisposal;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\TenantContext;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetDisposalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        TenantContext::clear();
        parent::setUp();
    }

    /** @return array{tenant: Tenant, asset: FixedAsset} */
    private function activatedAsset(float $cost = 10000.0, float $accum = 2000.0): array
    {
        $tenant = Tenant::create(['name' => 'Disp Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
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
                'asset_code' => 'FA-DISP-'.uniqid(),
                'name' => 'Machine',
                'category' => 'Equipment',
                'acquisition_date' => '2026-02-01',
                'in_service_date' => '2026-02-10',
                'currency_code' => 'GBP',
                'acquisition_cost' => $cost,
                'residual_value' => 0,
                'useful_life_months' => 120,
                'depreciation_method' => 'STRAIGHT_LINE',
            ]);
        $create->assertStatus(201);
        $assetId = $create->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$assetId}/activate", [
                'posting_date' => '2026-02-15',
                'idempotency_key' => 'fa-act-'.uniqid(),
                'source_account' => 'BANK',
            ])->assertStatus(201);

        $asset = FixedAsset::findOrFail($assetId);
        $book = FixedAssetBook::where('fixed_asset_id', $assetId)->first();
        $this->assertNotNull($book);
        $book->update([
            'accumulated_depreciation' => $accum,
            'carrying_amount' => round($cost - $accum, 2),
        ]);

        return ['tenant' => $tenant, 'asset' => $asset];
    }

    public function test_disposal_post_balances_and_marks_asset_disposed(): void
    {
        $x = $this->activatedAsset(10000.0, 2000.0);
        $tenant = $x['tenant'];
        $asset = $x['asset'];

        $carrying = 8000.0;
        $proceeds = 7500.0;
        $expectedLoss = 500.0;

        $disp = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$asset->id}/disposals", [
                'disposal_date' => '2026-08-01',
                'proceeds_amount' => $proceeds,
                'proceeds_account' => 'BANK',
            ]);
        $disp->assertStatus(201);
        $disposalId = $disp->json('id');

        $n = LedgerEntry::count();

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-asset-disposals/{$disposalId}/post", [
                'posting_date' => '2026-08-01',
                'idempotency_key' => 'disp-post-1',
            ]);
        $post->assertStatus(201);
        $pgId = $post->json('id');

        $sumDr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount');
        $sumCr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount');
        $this->assertEqualsWithDelta($sumDr, $sumCr, 0.02);

        $this->assertGreaterThan($n, LedgerEntry::count());

        $asset->refresh();
        $this->assertSame(FixedAsset::STATUS_DISPOSED, $asset->status);

        $book = FixedAssetBook::where('fixed_asset_id', $asset->id)->first();
        $this->assertEqualsWithDelta(0.0, (float) $book->carrying_amount, 0.02);
        $this->assertEqualsWithDelta(10000.0, (float) $book->accumulated_depreciation, 0.02);

        $d = FixedAssetDisposal::findOrFail($disposalId);
        $this->assertSame(FixedAssetDisposal::STATUS_POSTED, $d->status);
        $this->assertEqualsWithDelta($carrying, (float) $d->carrying_amount_at_post, 0.02);
        $this->assertEqualsWithDelta($expectedLoss, (float) $d->loss_amount, 0.02);
        $this->assertNull($d->gain_amount);
    }

    public function test_disposal_with_gain(): void
    {
        $x = $this->activatedAsset(10000.0, 2000.0);
        $tenant = $x['tenant'];
        $asset = $x['asset'];

        $disp = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$asset->id}/disposals", [
                'disposal_date' => '2026-08-01',
                'proceeds_amount' => 9000,
                'proceeds_account' => 'CASH',
            ]);
        $disposalId = $disp->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-asset-disposals/{$disposalId}/post", [
                'posting_date' => '2026-08-01',
                'idempotency_key' => 'disp-gain',
            ])->assertStatus(201);

        $d = FixedAssetDisposal::findOrFail($disposalId);
        $this->assertEqualsWithDelta(1000.0, (float) $d->gain_amount, 0.02);
        $this->assertNull($d->loss_amount);
    }

    public function test_post_is_idempotent(): void
    {
        $x = $this->activatedAsset(5000.0, 500.0);
        $tenant = $x['tenant'];
        $asset = $x['asset'];

        $disp = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$asset->id}/disposals", [
                'disposal_date' => '2026-09-01',
                'proceeds_amount' => 0,
            ]);
        $disposalId = $disp->json('id');

        $payload = [
            'posting_date' => '2026-09-01',
            'idempotency_key' => 'disp-idem',
        ];

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-asset-disposals/{$disposalId}/post", $payload);
        $r1->assertStatus(201);
        $pg1 = $r1->json('id');
        $lc = LedgerEntry::count();

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-asset-disposals/{$disposalId}/post", $payload);
        $r2->assertStatus(201);
        $this->assertSame($pg1, $r2->json('id'));
        $this->assertSame($lc, LedgerEntry::count());
    }

    public function test_disposed_asset_not_in_depreciation_run(): void
    {
        $x = $this->activatedAsset(8000.0, 0.0);
        $tenant = $x['tenant'];
        $asset = $x['asset'];

        $disp = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$asset->id}/disposals", [
                'disposal_date' => '2026-10-01',
                'proceeds_amount' => 100,
                'proceeds_account' => 'BANK',
            ]);
        $disposalId = $disp->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-asset-disposals/{$disposalId}/post", [
                'posting_date' => '2026-10-01',
                'idempotency_key' => 'disp-then-depr',
            ])->assertStatus(201);

        $run = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-asset-depreciation-runs', [
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-31',
            ]);
        $run->assertStatus(201);
        $lines = $run->json('lines') ?? [];
        $this->assertCount(0, $lines);
    }
}

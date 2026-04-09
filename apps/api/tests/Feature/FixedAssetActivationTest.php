<?php

namespace Tests\Feature;

use App\Domains\Accounting\MultiCurrency\ExchangeRate;
use App\Domains\Accounting\FixedAssets\FixedAsset;
use App\Domains\Accounting\FixedAssets\FixedAssetBook;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\TenantContext;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FixedAssetActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        TenantContext::clear();
        parent::setUp();
    }

    /** @return array{tenant: Tenant, project: Project} */
    private function tenantProjectFixtures(): array
    {
        $tenant = Tenant::create(['name' => 'FA Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
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
            'name' => 'Partner',
            'party_types' => ['SUPPLIER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Block A',
            'status' => 'ACTIVE',
        ]);

        return ['tenant' => $tenant, 'project' => $project];
    }

    public function test_create_draft_does_not_touch_ledger(): void
    {
        $x = $this->tenantProjectFixtures();
        $tenant = $x['tenant'];
        $project = $x['project'];

        $before = LedgerEntry::count();

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-assets', [
                'project_id' => $project->id,
                'asset_code' => 'FA-DRAFT-1',
                'name' => 'Pump',
                'category' => 'Equipment',
                'acquisition_date' => '2026-03-01',
                'in_service_date' => '2026-03-15',
                'currency_code' => 'GBP',
                'acquisition_cost' => 1200.00,
                'residual_value' => 0,
                'useful_life_months' => 48,
                'depreciation_method' => 'STRAIGHT_LINE',
            ]);

        $res->assertStatus(201);
        $this->assertSame($before, LedgerEntry::count());
    }

    public function test_activate_creates_balanced_posting_group_allocation_and_book(): void
    {
        $x = $this->tenantProjectFixtures();
        $tenant = $x['tenant'];
        $project = $x['project'];

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-assets', [
                'project_id' => $project->id,
                'asset_code' => 'FA-ACT-1',
                'name' => 'Tractor attachment',
                'category' => 'Equipment',
                'acquisition_date' => '2026-03-01',
                'in_service_date' => '2026-03-10',
                'currency_code' => 'GBP',
                'acquisition_cost' => 5000.00,
                'residual_value' => 0,
                'useful_life_months' => 60,
                'depreciation_method' => 'STRAIGHT_LINE',
            ]);
        $create->assertStatus(201);
        $assetId = $create->json('id');

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$assetId}/activate", [
                'posting_date' => '2026-03-20',
                'idempotency_key' => 'fa-act-1',
                'source_account' => 'BANK',
            ]);

        $post->assertStatus(201);
        $pgId = $post->json('id');
        $this->assertNotEmpty($pgId);

        $asset = FixedAsset::findOrFail($assetId);
        $this->assertSame(FixedAsset::STATUS_ACTIVE, $asset->status);
        $this->assertSame($pgId, $asset->activation_posting_group_id);

        $pg = PostingGroup::findOrFail($pgId);
        $this->assertSame('FIXED_ASSET_ACTIVATION', $pg->source_type);
        $this->assertSame($assetId, $pg->source_id);

        $sumDr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount');
        $sumCr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount');
        $this->assertEqualsWithDelta(5000.0, $sumDr, 0.02);
        $this->assertEqualsWithDelta(5000.0, $sumCr, 0.02);

        $this->assertSame(1, AllocationRow::where('posting_group_id', $pgId)->where('allocation_type', 'FIXED_ASSET_ACTIVATION')->count());

        $book = FixedAssetBook::where('fixed_asset_id', $assetId)->where('book_type', FixedAssetBook::BOOK_PRIMARY)->first();
        $this->assertNotNull($book);
        $this->assertEqualsWithDelta(5000.0, (float) $book->carrying_amount, 0.02);
    }

    public function test_activate_is_idempotent_and_does_not_double_post(): void
    {
        $x = $this->tenantProjectFixtures();
        $tenant = $x['tenant'];
        $project = $x['project'];

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-assets', [
                'project_id' => $project->id,
                'asset_code' => 'FA-IDEM',
                'name' => 'Asset',
                'category' => 'Equipment',
                'acquisition_date' => '2026-04-01',
                'in_service_date' => '2026-04-05',
                'currency_code' => 'GBP',
                'acquisition_cost' => 800.00,
                'useful_life_months' => 24,
                'depreciation_method' => 'STRAIGHT_LINE',
            ]);
        $assetId = $create->json('id');

        $payload = [
            'posting_date' => '2026-04-10',
            'idempotency_key' => 'fa-idem-key',
            'source_account' => 'CASH',
        ];

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$assetId}/activate", $payload);
        $r1->assertStatus(201);
        $pg1 = $r1->json('id');
        $ledgerAfterFirst = LedgerEntry::count();

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$assetId}/activate", $payload);
        $r2->assertStatus(201);
        $this->assertSame($pg1, $r2->json('id'));
        $this->assertSame($ledgerAfterFirst, LedgerEntry::count());
    }

    public function test_active_asset_cannot_change_financial_fields(): void
    {
        $x = $this->tenantProjectFixtures();
        $tenant = $x['tenant'];
        $project = $x['project'];

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-assets', [
                'project_id' => $project->id,
                'asset_code' => 'FA-RO',
                'name' => 'RO',
                'category' => 'Equipment',
                'acquisition_date' => '2026-05-01',
                'in_service_date' => '2026-05-02',
                'currency_code' => 'GBP',
                'acquisition_cost' => 100.00,
                'useful_life_months' => 12,
                'depreciation_method' => 'STRAIGHT_LINE',
            ]);
        $assetId = $create->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$assetId}/activate", [
                'posting_date' => '2026-05-10',
                'idempotency_key' => 'fa-ro-1',
                'source_account' => 'EQUITY_INJECTION',
            ])->assertStatus(201);

        $asset = FixedAsset::findOrFail($assetId);

        $this->expectException(ValidationException::class);
        $asset->update(['acquisition_cost' => 200]);
    }

    public function test_activate_foreign_currency_posts_ledger_balanced_in_base_with_stored_original_amount(): void
    {
        $x = $this->tenantProjectFixtures();
        $tenant = $x['tenant'];
        $project = $x['project'];

        $tenant->update(['currency_code' => 'USD']);

        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2026-03-01',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => 1.1,
            'source' => 'test',
        ]);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-assets', [
                'project_id' => $project->id,
                'asset_code' => 'FA-FX-1',
                'name' => 'Imported kit',
                'category' => 'Equipment',
                'acquisition_date' => '2026-03-01',
                'in_service_date' => '2026-03-10',
                'currency_code' => 'EUR',
                'acquisition_cost' => 1000.00,
                'residual_value' => 0,
                'useful_life_months' => 36,
                'depreciation_method' => 'STRAIGHT_LINE',
            ]);
        $create->assertStatus(201);
        $assetId = $create->json('id');

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$assetId}/activate", [
                'posting_date' => '2026-03-20',
                'idempotency_key' => 'fa-fx-1',
                'source_account' => 'BANK',
            ]);
        $post->assertStatus(201);
        $pgId = $post->json('id');

        $pg = PostingGroup::findOrFail($pgId);
        $this->assertSame('EUR', $pg->currency_code);
        $this->assertSame('USD', $pg->base_currency_code);
        $this->assertEqualsWithDelta(1.1, (float) $pg->fx_rate, 0.0001);

        $entries = LedgerEntry::where('posting_group_id', $pgId)->orderBy('debit_amount', 'desc')->get();
        $this->assertCount(2, $entries);
        foreach ($entries as $le) {
            $this->assertSame('EUR', $le->currency_code);
            $this->assertSame('USD', $le->base_currency_code);
        }

        $sumDr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount');
        $sumCr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount');
        $this->assertEqualsWithDelta(1000.0, $sumDr, 0.02);
        $this->assertEqualsWithDelta(1000.0, $sumCr, 0.02);

        $sumDrB = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount_base');
        $sumCrB = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount_base');
        $this->assertEqualsWithDelta(1100.0, $sumDrB, 0.02);
        $this->assertEqualsWithDelta(1100.0, $sumCrB, 0.02);
    }

    public function test_activate_without_exchange_rate_returns_422_for_foreign_document_currency(): void
    {
        $x = $this->tenantProjectFixtures();
        $tenant = $x['tenant'];
        $project = $x['project'];

        $tenant->update(['currency_code' => 'USD']);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fixed-assets', [
                'project_id' => $project->id,
                'asset_code' => 'FA-NORATE',
                'name' => 'No rate',
                'category' => 'Equipment',
                'acquisition_date' => '2026-03-01',
                'in_service_date' => '2026-03-10',
                'currency_code' => 'EUR',
                'acquisition_cost' => 500.00,
                'residual_value' => 0,
                'useful_life_months' => 24,
                'depreciation_method' => 'STRAIGHT_LINE',
            ]);
        $assetId = $create->json('id');

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fixed-assets/{$assetId}/activate", [
                'posting_date' => '2026-03-20',
                'idempotency_key' => 'fa-norate',
                'source_account' => 'BANK',
            ]);
        $post->assertStatus(422);
        $msg = $post->json('errors.exchange_rate.0');
        $this->assertIsString($msg);
        $this->assertStringContainsStringIgnoringCase('exchange rate', $msg);
    }
}

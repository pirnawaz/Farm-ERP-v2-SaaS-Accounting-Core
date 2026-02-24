<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CropCatalogItem;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\LandAllocation;
use App\Models\LandParcel;
use App\Models\Module;
use App\Models\PostingGroup;
use App\Models\Tenant;
use App\Models\TenantCropItem;
use App\Models\TenantModule;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CropProfitabilityTrendReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private CropCycle $cycle;
    private Account $expenseAccount;
    private Account $incomeAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Trend Tenant', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        SystemAccountsSeeder::runForTenant($this->otherTenant->id);

        $mod = Module::where('key', 'projects_crop_cycles')->first();
        $this->assertNotNull($mod);
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'module_id' => $mod->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->otherTenant->id, 'module_id' => $mod->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );

        $catalog = CropCatalogItem::where('code', 'MAIZE')->first();
        $this->assertNotNull($catalog);
        $tci = TenantCropItem::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'crop_catalog_item_id' => $catalog->id],
            ['display_name' => $catalog->default_name, 'is_active' => true, 'sort_order' => 0]
        );
        $this->cycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Maize',
            'tenant_crop_item_id' => $tci->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $this->expenseAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'EXP_SHARED')->firstOrFail();
        $this->incomeAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->firstOrFail();
    }

    private function headers(string $role = 'accountant'): array
    {
        return [
            'X-Tenant-Id' => $this->tenant->id,
            'X-User-Role' => $role,
        ];
    }

    private function createExpensePosting(float $amount, string $postingDate, ?string $cropCycleId = null, ?string $reversalOf = null): PostingGroup
    {
        $pg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $cropCycleId,
            'source_type' => 'OPERATIONAL',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'posting_date' => $postingDate,
            'idempotency_key' => 'trend-exp-' . \Illuminate\Support\Str::uuid(),
            'reversal_of_posting_group_id' => $reversalOf,
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $this->expenseAccount->id,
            'debit_amount' => $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        $bank = Account::where('tenant_id', $this->tenant->id)->where('code', 'BANK')->first();
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $bank->id,
            'debit_amount' => 0,
            'credit_amount' => $amount,
            'currency_code' => 'GBP',
        ]);
        return $pg;
    }

    private function createRevenuePosting(float $amount, string $postingDate, ?string $cropCycleId = null, ?string $reversalOf = null): PostingGroup
    {
        $pg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $cropCycleId,
            'source_type' => 'OPERATIONAL',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'posting_date' => $postingDate,
            'idempotency_key' => 'trend-rev-' . \Illuminate\Support\Str::uuid(),
            'reversal_of_posting_group_id' => $reversalOf,
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $this->incomeAccount->id,
            'debit_amount' => 0,
            'credit_amount' => $amount,
            'currency_code' => 'GBP',
        ]);
        $bank = Account::where('tenant_id', $this->tenant->id)->where('code', 'BANK')->first();
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $bank->id,
            'debit_amount' => $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        return $pg;
    }

    public function test_trend_is_tenant_scoped(): void
    {
        $this->createExpensePosting(100.00, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(200.00, '2024-06-20', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 50]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability-trend?from=2024-01-01&to=2024-12-31');
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('series'));

        $responseOther = $this->withHeaders([
            'X-Tenant-Id' => $this->otherTenant->id,
            'X-User-Role' => 'accountant',
        ])->getJson('/api/reports/crop-profitability-trend?from=2024-01-01&to=2024-12-31');
        $responseOther->assertStatus(200);
        $this->assertEmpty($responseOther->json('series'));
        $this->assertEmpty($responseOther->json('months'));
    }

    public function test_trend_excludes_reversals(): void
    {
        $pgCost = $this->createExpensePosting(200.00, '2024-06-15', $this->cycle->id);
        $this->createExpensePosting(200.00, '2024-06-20', $this->cycle->id, $pgCost->id);
        $pgRev = $this->createRevenuePosting(300.00, '2024-06-25', $this->cycle->id);
        $this->createRevenuePosting(300.00, '2024-06-28', $this->cycle->id, $pgRev->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 40]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 40,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability-trend?from=2024-01-01&to=2024-12-31&group_by=all');
        $response->assertStatus(200);
        $series = $response->json('series');
        $this->assertCount(1, $series);
        $this->assertEquals('0.00', $series[0]['totals']['cost']);
        $this->assertEquals('0.00', $series[0]['totals']['revenue']);
        $this->assertEquals('0.00', $series[0]['totals']['margin']);
    }

    public function test_trend_correct_revenue_and_cost_signs(): void
    {
        $this->createExpensePosting(150.00, '2024-06-10', $this->cycle->id);
        $this->createRevenuePosting(400.00, '2024-06-15', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 100]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 100,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability-trend?from=2024-01-01&to=2024-12-31&group_by=category');
        $response->assertStatus(200);
        $series = $response->json('series');
        $this->assertCount(1, $series);
        $this->assertEquals('150.00', $series[0]['totals']['cost']);
        $this->assertEquals('400.00', $series[0]['totals']['revenue']);
        $this->assertEquals('250.00', $series[0]['totals']['margin']);
    }

    public function test_trend_month_grouping_two_months(): void
    {
        $this->createExpensePosting(100.00, '2024-05-15', $this->cycle->id);
        $this->createRevenuePosting(200.00, '2024-05-20', $this->cycle->id);
        $this->createExpensePosting(50.00, '2024-06-10', $this->cycle->id);
        $this->createRevenuePosting(150.00, '2024-06-25', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 50]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability-trend?from=2024-01-01&to=2024-12-31&group_by=all');
        $response->assertStatus(200);
        $months = $response->json('months');
        $this->assertContains('2024-05', $months);
        $this->assertContains('2024-06', $months);
        $series = $response->json('series');
        $this->assertCount(1, $series);
        $points = $series[0]['points'];
        $this->assertCount(2, $points);
        $byMonth = collect($points)->keyBy('month');
        $this->assertEquals('100.00', $byMonth['2024-05']['cost']);
        $this->assertEquals('200.00', $byMonth['2024-05']['revenue']);
        $this->assertEquals('100.00', $byMonth['2024-05']['margin']);
        $this->assertEquals('50.00', $byMonth['2024-06']['cost']);
        $this->assertEquals('150.00', $byMonth['2024-06']['revenue']);
        $this->assertEquals('100.00', $byMonth['2024-06']['margin']);
    }

    public function test_trend_group_by_category_aggregates(): void
    {
        $this->createExpensePosting(300.00, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(800.00, '2024-06-20', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 30]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 30,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability-trend?from=2024-01-01&to=2024-12-31&group_by=category');
        $response->assertStatus(200);
        $series = $response->json('series');
        $this->assertCount(1, $series);
        $this->assertEquals('cereal', $series[0]['key']);
        $this->assertEquals('Cereal', $series[0]['label']);
        $this->assertEquals('300.00', $series[0]['totals']['cost']);
        $this->assertEquals('800.00', $series[0]['totals']['revenue']);
        $this->assertEquals('500.00', $series[0]['totals']['margin']);
    }

    public function test_trend_group_by_crop_aggregates(): void
    {
        $this->createExpensePosting(100.00, '2024-06-01', $this->cycle->id);
        $this->createRevenuePosting(250.00, '2024-06-15', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 60]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 60,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability-trend?from=2024-01-01&to=2024-12-31&group_by=crop');
        $response->assertStatus(200);
        $series = $response->json('series');
        $this->assertCount(1, $series);
        $this->assertEquals($this->cycle->tenant_crop_item_id, $series[0]['key']);
        $this->assertEquals('150.00', $series[0]['totals']['margin']);
        $point = $series[0]['points'][0];
        $this->assertEquals('100.00', $point['cost']);
        $this->assertEquals('250.00', $point['revenue']);
        $this->assertEquals('60.00', $point['acres']);
        $this->assertNotNull($point['margin_per_acre']);
    }

    public function test_trend_group_by_all_returns_single_series(): void
    {
        $this->createExpensePosting(400.00, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(900.00, '2024-06-20', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 80]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 80,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability-trend?from=2024-01-01&to=2024-12-31&group_by=all');
        $response->assertStatus(200);
        $series = $response->json('series');
        $this->assertCount(1, $series);
        $this->assertEquals('all', $series[0]['key']);
        $this->assertEquals('Overall', $series[0]['label']);
        $this->assertEquals('400.00', $series[0]['totals']['cost']);
        $this->assertEquals('900.00', $series[0]['totals']['revenue']);
        $this->assertEquals('500.00', $series[0]['totals']['margin']);
    }

    public function test_trend_include_unassigned_false_excludes_null_crop_cycle(): void
    {
        $this->createExpensePosting(100.00, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(200.00, '2024-06-20', $this->cycle->id);
        $this->createExpensePosting(50.00, '2024-06-25', null);
        $this->createRevenuePosting(75.00, '2024-06-28', null);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 50]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability-trend?from=2024-01-01&to=2024-12-31&group_by=all&include_unassigned=0');
        $response->assertStatus(200);
        $series = $response->json('series');
        $this->assertCount(1, $series);
        $this->assertEquals('100.00', $series[0]['totals']['cost']);
        $this->assertEquals('200.00', $series[0]['totals']['revenue']);
    }

    public function test_trend_decimals_formatted_2dp(): void
    {
        $this->createExpensePosting(33.333, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(66.666, '2024-06-20', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 10]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 10,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability-trend?from=2024-01-01&to=2024-12-31&group_by=all');
        $response->assertStatus(200);
        $point = $response->json('series.0.points.0');
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $point['cost']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $point['revenue']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $point['margin_per_acre']);
    }
}

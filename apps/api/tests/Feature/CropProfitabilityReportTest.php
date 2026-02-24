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

class CropProfitabilityReportTest extends TestCase
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
        $this->tenant = Tenant::create(['name' => 'Profitability Tenant', 'status' => 'active']);
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
            'idempotency_key' => 'test-exp-' . \Illuminate\Support\Str::uuid(),
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
            'idempotency_key' => 'test-rev-' . \Illuminate\Support\Str::uuid(),
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

    public function test_crop_profitability_is_tenant_scoped(): void
    {
        $this->createExpensePosting(100.00, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(200.00, '2024-07-01', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 50]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability?from=2024-01-01&to=2024-12-31');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotEmpty($data['rows']);
        $this->assertEquals('100.00', $data['totals']['cost']);
        $this->assertEquals('200.00', $data['totals']['revenue']);
        $this->assertEquals('100.00', $data['totals']['margin']);

        $responseOther = $this->withHeaders([
            'X-Tenant-Id' => $this->otherTenant->id,
            'X-User-Role' => 'accountant',
        ])->getJson('/api/reports/crop-profitability?from=2024-01-01&to=2024-12-31');
        $responseOther->assertStatus(200);
        $this->assertEmpty($responseOther->json('rows'));
        $this->assertEquals('0.00', $responseOther->json('totals.cost'));
        $this->assertEquals('0.00', $responseOther->json('totals.revenue'));
        $this->assertEquals('0.00', $responseOther->json('totals.margin'));
    }

    public function test_crop_profitability_excludes_reversals(): void
    {
        $pgCost = $this->createExpensePosting(200.00, '2024-06-15', $this->cycle->id);
        $this->createExpensePosting(200.00, '2024-06-20', $this->cycle->id, $pgCost->id);
        $pgRev = $this->createRevenuePosting(300.00, '2024-07-01', $this->cycle->id);
        $this->createRevenuePosting(300.00, '2024-07-05', $this->cycle->id, $pgRev->id);

        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 40]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 40,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability?from=2024-01-01&to=2024-12-31');
        $response->assertStatus(200);
        $totals = $response->json('totals');
        $this->assertEquals('0.00', $totals['cost'], 'Original and reversal expense excluded');
        $this->assertEquals('0.00', $totals['revenue'], 'Original and reversal revenue excluded');
        $this->assertEquals('0.00', $totals['margin']);
    }

    public function test_computes_revenue_and_cost_with_correct_signs(): void
    {
        $this->createExpensePosting(150.00, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(400.00, '2024-07-01', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 100]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 100,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability?from=2024-01-01&to=2024-12-31&group_by=crop');
        $response->assertStatus(200);
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals('150.00', $rows[0]['cost']);
        $this->assertEquals('400.00', $rows[0]['revenue']);
        $this->assertEquals('250.00', $rows[0]['margin']);
    }

    public function test_computes_margin_and_per_acre_correctly(): void
    {
        $this->createExpensePosting(500.00, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(1200.00, '2024-07-01', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 100]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability?from=2024-01-01&to=2024-12-31&group_by=crop');
        $response->assertStatus(200);
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals('500.00', $rows[0]['cost']);
        $this->assertEquals('1200.00', $rows[0]['revenue']);
        $this->assertEquals('700.00', $rows[0]['margin']);
        $this->assertEquals('50.00', $rows[0]['acres']);
        $this->assertEquals('10.00', $rows[0]['cost_per_acre']);
        $this->assertEquals('24.00', $rows[0]['revenue_per_acre']);
        $this->assertEquals('14.00', $rows[0]['margin_per_acre']);
        $this->assertEquals('500.00', $response->json('totals.cost'));
        $this->assertEquals('1200.00', $response->json('totals.revenue'));
        $this->assertEquals('700.00', $response->json('totals.margin'));
        $this->assertEquals('10.00', $response->json('totals.cost_per_acre'));
        $this->assertEquals('24.00', $response->json('totals.revenue_per_acre'));
        $this->assertEquals('14.00', $response->json('totals.margin_per_acre'));
    }

    public function test_group_by_crop_returns_aggregation_with_crop_display_name_and_catalog_code(): void
    {
        $this->createExpensePosting(100.00, '2024-06-01', $this->cycle->id);
        $this->createRevenuePosting(250.00, '2024-07-01', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 60]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 60,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability?from=2024-01-01&to=2024-12-31&group_by=crop');
        $response->assertStatus(200);
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]['crop_display_name']);
        $this->assertEquals('MAIZE', $rows[0]['catalog_code']);
        $this->assertEquals('150.00', $rows[0]['margin']);
    }

    public function test_group_by_category_returns_category_aggregation(): void
    {
        $this->createExpensePosting(300.00, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(800.00, '2024-07-01', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 30]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 30,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability?from=2024-01-01&to=2024-12-31&group_by=category');
        $response->assertStatus(200);
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals('cereal', $rows[0]['key']);
        $this->assertEquals('300.00', $rows[0]['cost']);
        $this->assertEquals('800.00', $rows[0]['revenue']);
        $this->assertEquals('500.00', $rows[0]['margin']);
        $this->assertEquals('30.00', $rows[0]['acres']);
    }

    public function test_group_by_cycle_returns_cycle_rows_with_crop_cycle_name(): void
    {
        $this->createExpensePosting(400.00, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(900.00, '2024-07-01', $this->cycle->id);
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 80]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 80,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability?from=2024-01-01&to=2024-12-31&group_by=cycle');
        $response->assertStatus(200);
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($this->cycle->id, $rows[0]['crop_cycle_id']);
        $this->assertEquals('2024 Maize', $rows[0]['crop_cycle_name']);
        $this->assertEquals('400.00', $rows[0]['cost']);
        $this->assertEquals('900.00', $rows[0]['revenue']);
        $this->assertEquals('500.00', $rows[0]['margin']);
        $this->assertEquals('80.00', $rows[0]['acres']);
        $this->assertEquals('5.00', $rows[0]['cost_per_acre']);
        $this->assertEquals('11.25', $rows[0]['revenue_per_acre']);
        $this->assertEquals('6.25', $rows[0]['margin_per_acre']);
    }

    public function test_include_unassigned_false_excludes_null_crop_cycle_id_postings(): void
    {
        $this->createExpensePosting(100.00, '2024-06-15', $this->cycle->id);
        $this->createRevenuePosting(200.00, '2024-07-01', $this->cycle->id);
        $this->createExpensePosting(50.00, '2024-06-20', null);
        $this->createRevenuePosting(75.00, '2024-07-10', null);

        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 50]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability?from=2024-01-01&to=2024-12-31&include_unassigned=0');
        $response->assertStatus(200);
        $this->assertEquals('100.00', $response->json('totals.cost'));
        $this->assertEquals('200.00', $response->json('totals.revenue'));
        $this->assertEquals('100.00', $response->json('totals.margin'));
    }

    public function test_validation_requires_from_and_to(): void
    {
        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability');
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_validation_to_must_be_after_or_equal_from(): void
    {
        $response = $this->withHeaders($this->headers())
            ->getJson('/api/reports/crop-profitability?from=2024-06-01&to=2024-05-01');
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['to']);
    }
}

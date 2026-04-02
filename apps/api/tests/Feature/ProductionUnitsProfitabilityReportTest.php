<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\ProductionUnit;
use App\Models\Tenant;
use App\Models\TenantModule;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionUnitsProfitabilityReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private Account $expenseAccount;
    private Account $incomeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Units Profitability Tenant', 'status' => 'active']);
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

        $this->expenseAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'EXP_SHARED')->firstOrFail();
        $this->incomeAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->firstOrFail();
    }

    private function headers(Tenant $t): array
    {
        return [
            'X-Tenant-Id' => $t->id,
            'X-User-Role' => 'accountant',
        ];
    }

    private function seedUnitPosting(string $tenantId, string $postingDate, ProductionUnit $unit, float $cost, float $revenue): void
    {
        // Create posting group + ledger entries
        $pg = PostingGroup::create([
            'tenant_id' => $tenantId,
            'crop_cycle_id' => null,
            'source_type' => 'OPERATIONAL',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'posting_date' => $postingDate,
            'idempotency_key' => 'unit-profit-' . \Illuminate\Support\Str::uuid(),
            'reversal_of_posting_group_id' => null,
        ]);

        \App\Models\LedgerEntry::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $pg->id,
            'account_id' => $this->expenseAccount->id,
            'debit_amount' => $cost,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        \App\Models\LedgerEntry::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $pg->id,
            'account_id' => $this->incomeAccount->id,
            'debit_amount' => 0,
            'credit_amount' => $revenue,
            'currency_code' => 'GBP',
        ]);

        // Link posting_group_id + production_unit_id via an operational record (sale is simplest)
        $buyer = Party::create(['tenant_id' => $tenantId, 'name' => 'Buyer', 'party_types' => ['CUSTOMER']]);
        \App\Models\Sale::create([
            'tenant_id' => $tenantId,
            'buyer_party_id' => $buyer->id,
            'project_id' => null,
            'crop_cycle_id' => null,
            'production_unit_id' => $unit->id,
            'amount' => max(1, $revenue), // sale table has amount > 0 check
            'posting_date' => $postingDate,
            'status' => 'POSTED',
            'posting_group_id' => $pg->id,
            'posted_at' => now(),
            'notes' => null,
            'idempotency_key' => null,
        ]);
    }

    public function test_production_units_profitability_is_tenant_scoped_and_filters_by_category(): void
    {
        $orchard = ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Orchard A',
            'type' => ProductionUnit::TYPE_LONG_CYCLE,
            'status' => ProductionUnit::STATUS_ACTIVE,
            'start_date' => '2024-01-01',
            'end_date' => null,
            'notes' => null,
            'category' => ProductionUnit::CATEGORY_ORCHARD,
        ]);
        $livestock = ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Herd B',
            'type' => ProductionUnit::TYPE_LONG_CYCLE,
            'status' => ProductionUnit::STATUS_ACTIVE,
            'start_date' => '2024-01-01',
            'end_date' => null,
            'notes' => null,
            'category' => ProductionUnit::CATEGORY_LIVESTOCK,
        ]);

        $this->seedUnitPosting($this->tenant->id, '2024-06-15', $orchard, 100.00, 250.00);
        $this->seedUnitPosting($this->tenant->id, '2024-06-20', $livestock, 50.00, 70.00);

        $respAll = $this->withHeaders($this->headers($this->tenant))
            ->getJson('/api/reports/production-units-profitability?from=2024-06-01&to=2024-06-30');
        $respAll->assertStatus(200);
        $this->assertCount(2, $respAll->json('rows'));
        $this->assertEquals('150.00', $respAll->json('totals.cost'));
        $this->assertEquals('320.00', $respAll->json('totals.revenue'));
        $this->assertEquals('170.00', $respAll->json('totals.margin'));

        $respOrchards = $this->withHeaders($this->headers($this->tenant))
            ->getJson('/api/reports/production-units-profitability?from=2024-06-01&to=2024-06-30&category=ORCHARD');
        $respOrchards->assertStatus(200);
        $this->assertCount(1, $respOrchards->json('rows'));
        $this->assertEquals('100.00', $respOrchards->json('totals.cost'));
        $this->assertEquals('250.00', $respOrchards->json('totals.revenue'));

        $respOtherTenant = $this->withHeaders($this->headers($this->otherTenant))
            ->getJson('/api/reports/production-units-profitability?from=2024-06-01&to=2024-06-30');
        $respOtherTenant->assertStatus(200);
        $this->assertEmpty($respOtherTenant->json('rows'));
        $this->assertEquals('0.00', $respOtherTenant->json('totals.cost'));
    }
}


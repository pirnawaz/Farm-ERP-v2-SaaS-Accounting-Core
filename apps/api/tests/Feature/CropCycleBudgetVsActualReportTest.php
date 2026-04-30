<?php

namespace Tests\Feature;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\ProjectPlan;
use App\Models\ProjectPlanCost;
use App\Models\ProjectPlanYield;
use App\Models\Harvest;
use App\Models\Tenant;
use App\Models\TenantModule;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CropCycleBudgetVsActualReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function enableModules(Tenant $tenant, array $keys): void
    {
        (new ModulesSeeder)->run();
        foreach ($keys as $key) {
            $m = Module::where('key', $key)->first();
            if ($m) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    private function auth(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    private function postExpense(Tenant $tenant, Project $project, string $postingDate, array $expenseLines, string $cropCycleId): void
    {
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycleId,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => (string) Str::uuid(),
            'posting_date' => $postingDate,
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);

        foreach ($expenseLines as $line) {
            \App\Models\LedgerEntry::create([
                'tenant_id' => $tenant->id,
                'posting_group_id' => $pg->id,
                'account_id' => $line['account_id'],
                'debit_amount' => $line['debit_amount'],
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);
        }

        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $project->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 0,
            'amount_base' => 0,
            'rule_snapshot' => ['fixture' => true],
        ]);
    }

    private function addPremium(Tenant $tenant, string $cropCycleId, Project $project, string $postingDate, float $amount, string $status): void
    {
        $party = Party::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Vendor'],
            ['party_types' => ['VENDOR']]
        );
        $inv = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'project_id' => $project->id,
            'cost_center_id' => null,
            'invoice_date' => $postingDate,
            'currency_code' => 'GBP',
            'payment_terms' => 'CREDIT',
            'subtotal_amount' => number_format($amount, 2, '.', ''),
            'tax_amount' => '0.00',
            'total_amount' => number_format($amount, 2, '.', ''),
            'status' => $status,
            'posted_at' => $status === 'POSTED' ? now() : null,
        ]);
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycleId,
            'source_type' => 'SUPPLIER_INVOICE',
            'source_id' => $inv->id,
            'posting_date' => $postingDate,
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $project->id,
            'allocation_type' => 'SUPPLIER_INVOICE_CREDIT_PREMIUM',
            'amount' => $amount,
            'amount_base' => $amount,
            'rule_snapshot' => ['fixture' => 'premium'],
        ]);
    }

    public function test_crop_cycle_budget_vs_actual_rollup_series_and_project_totals(): void
    {
        $tenant = Tenant::create(['name' => 'BvA2', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['projects_crop_cycles', 'reports']);
        $headers = $this->auth($tenant);

        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['HARI']]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $p1 = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'A', 'status' => 'ACTIVE']);
        $p2 = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'B', 'status' => 'ACTIVE']);

        // Plans: p1 inputs 200, yield 10 @ 5; p2 labour 100, yield 20 @ 4.
        $plan1 = ProjectPlan::create(['tenant_id' => $tenant->id, 'project_id' => $p1->id, 'crop_cycle_id' => $cc->id, 'name' => 'Plan1', 'status' => ProjectPlan::STATUS_ACTIVE]);
        ProjectPlanCost::create(['project_plan_id' => $plan1->id, 'cost_type' => ProjectPlanCost::COST_TYPE_INPUT, 'expected_cost' => 200]);
        ProjectPlanYield::create(['project_plan_id' => $plan1->id, 'expected_quantity' => 10, 'expected_unit_value' => 5]);
        $plan2 = ProjectPlan::create(['tenant_id' => $tenant->id, 'project_id' => $p2->id, 'crop_cycle_id' => $cc->id, 'name' => 'Plan2', 'status' => ProjectPlan::STATUS_ACTIVE]);
        ProjectPlanCost::create(['project_plan_id' => $plan2->id, 'cost_type' => ProjectPlanCost::COST_TYPE_LABOUR, 'expected_cost' => 100]);
        ProjectPlanYield::create(['project_plan_id' => $plan2->id, 'expected_quantity' => 20, 'expected_unit_value' => 4]);

        $inputsExpense = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->firstOrFail();
        $labourExpense = Account::where('tenant_id', $tenant->id)->where('code', 'LABOUR_EXPENSE')->firstOrFail();

        // Actuals: Jan p1 inputs 60, Feb p2 labour 40.
        $this->postExpense($tenant, $p1, '2026-01-10', [['account_id' => $inputsExpense->id, 'debit_amount' => 60]], $cc->id);
        $this->postExpense($tenant, $p2, '2026-02-05', [['account_id' => $labourExpense->id, 'debit_amount' => 40]], $cc->id);

        // Premium: Feb p1 12 POSTED; Feb p2 5 DRAFT (excluded).
        $this->addPremium($tenant, $cc->id, $p1, '2026-02-15', 12.0, 'POSTED');
        $this->addPremium($tenant, $cc->id, $p2, '2026-02-20', 5.0, 'DRAFT');

        // Harvest production: Jan p1 posted 100 qty, 250 value (included); Feb p2 draft 50 qty, 80 value (excluded).
        $pgHarvestPosted = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'source_type' => 'HARVEST',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2026-01-25',
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);
        Harvest::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'project_id' => $p1->id,
            'harvest_date' => '2026-01-25',
            'posting_date' => '2026-01-25',
            'status' => 'POSTED',
            'posting_group_id' => $pgHarvestPosted->id,
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgHarvestPosted->id,
            'project_id' => $p1->id,
            'allocation_type' => 'HARVEST_PRODUCTION',
            'quantity' => 100,
            'amount' => 250,
            'amount_base' => 250,
            'rule_snapshot' => ['recipient_role' => 'OWNER'],
        ]);

        $pgHarvestDraft = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'source_type' => 'HARVEST',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2026-02-12',
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);
        Harvest::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'project_id' => $p2->id,
            'harvest_date' => '2026-02-12',
            'posting_date' => '2026-02-12',
            'status' => 'DRAFT',
            'posting_group_id' => $pgHarvestDraft->id,
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgHarvestDraft->id,
            'project_id' => $p2->id,
            'allocation_type' => 'HARVEST_PRODUCTION',
            'quantity' => 50,
            'amount' => 80,
            'amount_base' => 80,
            'rule_snapshot' => ['recipient_role' => 'OWNER'],
        ]);

        $res = $this->withHeaders($headers)->getJson(
            '/api/reports/budget-vs-actual/crop-cycle?crop_cycle_id='.$cc->id.'&from=2026-01-01&to=2026-02-28&bucket=month'
        );
        $res->assertStatus(200);

        // Monthly buckets.
        $this->assertCount(2, $res->json('series'));
        $this->assertSame('2026-01', $res->json('series.0.month'));
        $this->assertSame('2026-02', $res->json('series.1.month'));

        // Planned totals: 300 over 2 months => 150/month.
        $this->assertSame('150.00', $res->json('series.0.planned.planned_total_cost'));

        // Actual: Jan 60; Feb 40 + 12 premium => 52 total.
        $this->assertSame('60.00', $res->json('series.0.actual.actual_total_cost'));
        $this->assertSame('12.00', $res->json('series.1.actual.actual_credit_premium_cost'));
        $this->assertSame('52.00', $res->json('series.1.actual.actual_total_cost'));
        $this->assertSame('100.000', $res->json('series.0.actual.actual_yield_qty'));
        $this->assertSame('250.00', $res->json('series.0.actual.actual_yield_value'));
        $this->assertNull($res->json('series.1.actual.actual_yield_qty')); // draft harvest excluded

        // Totals: planned 300, actual 112.
        $this->assertSame('300.00', $res->json('totals.planned.planned_total_cost'));
        $this->assertSame('112.00', $res->json('totals.actual.actual_total_cost'));
        $this->assertSame('100.000', $res->json('totals.actual.actual_yield_qty'));
        $this->assertSame('250.00', $res->json('totals.actual.actual_yield_value'));

        // Per-project totals exist for both projects.
        $this->assertCount(2, $res->json('projects'));
        $this->assertSame($p1->id, $res->json('projects.0.project_id'));
        $this->assertSame('100.000', $res->json('projects.0.actual.actual_yield_qty'));
    }
}


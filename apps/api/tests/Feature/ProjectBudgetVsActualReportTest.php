<?php

namespace Tests\Feature;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
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

class ProjectBudgetVsActualReportTest extends TestCase
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

    private function postExpense(Tenant $tenant, Project $project, string $postingDate, array $expenseLines, string $cropCycleId): PostingGroup
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

        // Allocation row links posting group to project for eligibility (amount is irrelevant for profitability service).
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $project->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 0,
            'amount_base' => 0,
            'rule_snapshot' => ['fixture' => true],
        ]);

        return $pg;
    }

    public function test_project_budget_vs_actual_monthly_series_and_totals_and_premium(): void
    {
        $tenant = Tenant::create(['name' => 'BvA', 'status' => 'active', 'currency_code' => 'GBP']);
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
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'Field',
            'status' => 'ACTIVE',
        ]);

        // Latest ACTIVE plan (totals: inputs 200, labour 100, machinery 0, yield 10 @ 5 = 50).
        $plan = ProjectPlan::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'Plan A',
            'status' => ProjectPlan::STATUS_ACTIVE,
        ]);
        ProjectPlanCost::create(['project_plan_id' => $plan->id, 'cost_type' => ProjectPlanCost::COST_TYPE_INPUT, 'expected_cost' => 200]);
        ProjectPlanCost::create(['project_plan_id' => $plan->id, 'cost_type' => ProjectPlanCost::COST_TYPE_LABOUR, 'expected_cost' => 100]);
        ProjectPlanYield::create(['project_plan_id' => $plan->id, 'expected_quantity' => 10, 'expected_unit_value' => 5]);

        $inputsExpense = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->firstOrFail();
        $labourExpense = Account::where('tenant_id', $tenant->id)->where('code', 'LABOUR_EXPENSE')->firstOrFail();

        // Actuals: Jan inputs 60, Feb labour 40 (eligible via allocation rows).
        $this->postExpense($tenant, $project, '2026-01-10', [['account_id' => $inputsExpense->id, 'debit_amount' => 60]], $cc->id);
        $this->postExpense($tenant, $project, '2026-02-05', [['account_id' => $labourExpense->id, 'debit_amount' => 40]], $cc->id);

        // Posted credit premium in Feb: 12.00 (should be separate and included in actual total).
        $invPosted = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'project_id' => $project->id,
            'cost_center_id' => null,
            'invoice_date' => '2026-02-01',
            'currency_code' => 'GBP',
            'payment_terms' => 'CREDIT',
            'subtotal_amount' => '12.00',
            'tax_amount' => '0.00',
            'total_amount' => '12.00',
            'status' => SupplierInvoice::STATUS_POSTED,
            'posted_at' => now(),
        ]);
        $invLine = SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invPosted->id,
            'line_no' => 1,
            'description' => 'Premium line',
            'qty' => 1,
            'unit_price' => 12,
            'line_total' => '12.00',
            'tax_amount' => '0.00',
            'credit_premium_amount' => '12.00',
        ]);
        $pgPrem = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'source_type' => 'SUPPLIER_INVOICE',
            'source_id' => $invPosted->id,
            'posting_date' => '2026-02-15',
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgPrem->id,
            'project_id' => $project->id,
            'allocation_type' => 'SUPPLIER_INVOICE_CREDIT_PREMIUM',
            'amount' => 12,
            'amount_base' => 12,
            'rule_snapshot' => ['supplier_invoice_line_id' => $invLine->id],
        ]);

        // Draft/unposted premium must be excluded (invoice status DRAFT even if allocation exists).
        $invDraft = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'project_id' => $project->id,
            'cost_center_id' => null,
            'invoice_date' => '2026-02-01',
            'currency_code' => 'GBP',
            'payment_terms' => 'CREDIT',
            'subtotal_amount' => '5.00',
            'tax_amount' => '0.00',
            'total_amount' => '5.00',
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        $pgPremDraft = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'source_type' => 'SUPPLIER_INVOICE',
            'source_id' => $invDraft->id,
            'posting_date' => '2026-02-20',
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgPremDraft->id,
            'project_id' => $project->id,
            'allocation_type' => 'SUPPLIER_INVOICE_CREDIT_PREMIUM',
            'amount' => 5,
            'amount_base' => 5,
            'rule_snapshot' => ['fixture' => 'draft-premium'],
        ]);

        // Posted harvest production in Feb should appear as actual yield (qty/value).
        $pgHarvestPosted = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'source_type' => 'HARVEST',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2026-02-18',
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);
        Harvest::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'project_id' => $project->id,
            'harvest_date' => '2026-02-18',
            'posting_date' => '2026-02-18',
            'status' => 'POSTED',
            'posting_group_id' => $pgHarvestPosted->id,
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgHarvestPosted->id,
            'project_id' => $project->id,
            'allocation_type' => 'HARVEST_PRODUCTION',
            'quantity' => 100,
            'amount' => 250,
            'amount_base' => 250,
            'rule_snapshot' => ['recipient_role' => 'OWNER'],
        ]);

        // Draft/unposted harvest production must be excluded.
        $pgHarvestDraft = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'source_type' => 'HARVEST',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2026-01-20',
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);
        Harvest::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'project_id' => $project->id,
            'harvest_date' => '2026-01-20',
            'posting_date' => '2026-01-20',
            'status' => 'DRAFT',
            'posting_group_id' => $pgHarvestDraft->id,
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgHarvestDraft->id,
            'project_id' => $project->id,
            'allocation_type' => 'HARVEST_PRODUCTION',
            'quantity' => 50,
            'amount' => 100,
            'amount_base' => 100,
            'rule_snapshot' => ['recipient_role' => 'OWNER'],
        ]);

        $res = $this->withHeaders($headers)->getJson(
            '/api/reports/budget-vs-actual/project?project_id='.$project->id.'&from=2026-01-01&to=2026-02-28&bucket=month'
        );
        $res->assertStatus(200);

        // Monthly buckets: Jan and Feb.
        $this->assertCount(2, $res->json('series'));
        $this->assertSame('2026-01', $res->json('series.0.month'));
        $this->assertSame('2026-02', $res->json('series.1.month'));

        // Planned distributed evenly over 2 months: inputs 100/month, labour 50/month, total 150/month.
        $this->assertSame('100.00', $res->json('series.0.planned.planned_input_cost'));
        $this->assertSame('50.00', $res->json('series.0.planned.planned_labour_cost'));
        $this->assertSame('150.00', $res->json('series.0.planned.planned_total_cost'));

        // Actual: Jan inputs 60, Feb labour 40. Premium only Feb (12). Draft premium excluded.
        $this->assertSame('60.00', $res->json('series.0.actual.actual_input_cost'));
        $this->assertSame('0.00', $res->json('series.0.actual.actual_credit_premium_cost'));
        $this->assertSame('60.00', $res->json('series.0.actual.actual_total_cost'));

        $this->assertSame('40.00', $res->json('series.1.actual.actual_labour_cost'));
        $this->assertSame('12.00', $res->json('series.1.actual.actual_credit_premium_cost'));
        $this->assertSame('52.00', $res->json('series.1.actual.actual_total_cost')); // 40 + 12
        $this->assertSame('100.000', $res->json('series.1.actual.actual_yield_qty'));
        $this->assertSame('250.00', $res->json('series.1.actual.actual_yield_value'));
        $this->assertNull($res->json('series.0.actual.actual_yield_qty')); // draft harvest excluded

        // Variance = actual - planned.
        $this->assertSame('-40.00', $res->json('series.0.variance.variance_input_cost')); // 60 - 100
        $this->assertSame('-98.00', $res->json('series.1.variance.variance_total_cost')); // 52 - 150

        // Totals reconcile: planned 300, actual 60 + 40 + 12 = 112.
        $this->assertSame('300.00', $res->json('totals.planned.planned_total_cost'));
        $this->assertSame('12.00', $res->json('totals.actual.actual_credit_premium_cost'));
        $this->assertSame('112.00', $res->json('totals.actual.actual_total_cost'));
        $this->assertSame('-188.00', $res->json('totals.variance.variance_total_cost'));
        $this->assertSame('100.000', $res->json('totals.actual.actual_yield_qty'));
        $this->assertSame('250.00', $res->json('totals.actual.actual_yield_value'));
    }
}


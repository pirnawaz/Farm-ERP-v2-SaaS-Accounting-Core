<?php

namespace Tests\Feature;

use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvIssue;
use App\Models\InvIssueLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\Machine;
use App\Models\MachineMaintenanceJob;
use App\Models\MachineMaintenanceJobLine;
use App\Models\MachineRateCard;
use App\Models\MachineWorkLog;
use App\Models\LabWorker;
use App\Models\LabWorkLog;
use App\Models\AllocationRow;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\HarvestEconomicsService;
use App\Services\InventoryPostingService;
use App\Services\Machinery\MachineryChargePostingService;
use App\Services\Machinery\MachineryChargeService;
use App\Services\Machinery\MachineMaintenancePostingService;
use App\Services\Machinery\MachineryPostingService;
use App\Services\LabourPostingService;
use App\Services\MachineProfitabilityService;
use App\Services\ProjectProfitabilityService;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 7G.1 — Profitability reporting matches underlying ledger & allocation facts (no double counting).
 */
class Phase7G1ProfitabilityAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function enableModules(Tenant $tenant, array $keys): void
    {
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

    /**
     * Net P&amp;L-style totals on ledger for posting groups (matches {@see ProjectProfitabilityService} aggregates).
     *
     * @param  list<string>  $pgIds
     * @return array{income: float, expense: float}
     */
    private function ledgerNetIncomeAndExpenseForPostingGroups(string $tenantId, array $pgIds): array
    {
        if ($pgIds === []) {
            return ['income' => 0.0, 'expense' => 0.0];
        }

        $placeholders = implode(',', array_fill(0, count($pgIds), '?'));
        $bindings = array_merge([$tenantId], $pgIds);

        $income = (float) (DB::selectOne("
            SELECT COALESCE(SUM(
                COALESCE(le.credit_amount_base, le.credit_amount) - COALESCE(le.debit_amount_base, le.debit_amount)
            ), 0) AS v
            FROM ledger_entries le
            INNER JOIN accounts a ON a.id = le.account_id AND a.tenant_id = le.tenant_id
            WHERE le.tenant_id = ? AND le.posting_group_id IN ({$placeholders}) AND a.type = 'income'
        ", $bindings)->v ?? 0);

        $expense = (float) (DB::selectOne("
            SELECT COALESCE(SUM(
                COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount)
            ), 0) AS v
            FROM ledger_entries le
            INNER JOIN accounts a ON a.id = le.account_id AND a.tenant_id = le.tenant_id
            WHERE le.tenant_id = ? AND le.posting_group_id IN ({$placeholders}) AND a.type = 'expense'
        ", $bindings)->v ?? 0);

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
        ];
    }

    /**
     * @return list<string>
     */
    private function eligiblePostingGroupIdsForTest(
        ProjectProfitabilityService $svc,
        string $projectId,
        string $tenantId,
        ?string $from,
        ?string $to,
        ?string $cropCycleId
    ): array {
        $m = new ReflectionMethod($svc, 'eligiblePostingGroupIds');
        $m->setAccessible(true);

        return $m->invoke($svc, $projectId, $tenantId, $from, $to, $cropCycleId);
    }

    public function test_project_profitability_totals_match_ledger_for_posted_inventory_issue(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P7G1', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['reports', 'inventory', 'projects_crop_cycles']);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fertilizer']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fertilizer Bag',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Store',
            'type' => 'MAIN',
            'is_active' => true,
        ]);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'North field',
            'status' => 'ACTIVE',
        ]);

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $item->id,
            'qty' => 100,
            'unit_cost' => 50.00,
            'line_total' => 5000.00,
        ]);
        app(InventoryPostingService::class)->postGRN($grn->id, $tenant->id, '2024-06-01', 'grn-p7g1');

        $issue = InvIssue::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'ISS-1',
            'store_id' => $store->id,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 60,
            'hari_share_pct' => 40,
        ]);
        InvIssueLine::create([
            'tenant_id' => $tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $item->id,
            'qty' => 2,
        ]);
        app(InventoryPostingService::class)->postIssue($issue->id, $tenant->id, '2024-06-15', 'issue-p7g1');

        $filters = ['from' => '2024-06-01', 'to' => '2024-06-30'];
        $svc = app(ProjectProfitabilityService::class);
        $report = $svc->getProjectProfitability($project->id, $tenant->id, $filters);

        $pgIds = $this->eligiblePostingGroupIdsForTest($svc, $project->id, $tenant->id, $filters['from'], $filters['to'], null);
        $this->assertNotEmpty($pgIds, 'Eligible posting groups should include the posted issue');

        $ledger = $this->ledgerNetIncomeAndExpenseForPostingGroups($tenant->id, $pgIds);

        $this->assertEqualsWithDelta($ledger['income'], $report['totals']['revenue'], 0.02, 'Project revenue total must match ledger income on eligible posting groups');
        $this->assertEqualsWithDelta($ledger['expense'], $report['totals']['cost'], 0.02, 'Project cost total must match ledger expense on eligible posting groups');
        $this->assertEqualsWithDelta(
            round($ledger['income'] - $ledger['expense'], 2),
            $report['totals']['profit'],
            0.02,
            'Profit must match ledger net (income - expense)'
        );
        $this->assertEqualsWithDelta(100.00, $report['totals']['cost'], 0.02);
    }

    public function test_project_profitability_no_double_count_when_multiple_allocation_rows_same_posting_group(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P7G1-2', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['reports', 'inventory', 'projects_crop_cycles']);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fertilizer']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Bag',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Store',
            'type' => 'MAIN',
            'is_active' => true,
        ]);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'F1',
            'status' => 'ACTIVE',
        ]);

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $item->id,
            'qty' => 100,
            'unit_cost' => 50.00,
            'line_total' => 5000.00,
        ]);
        app(InventoryPostingService::class)->postGRN($grn->id, $tenant->id, '2024-06-01', 'grn-2');

        $issue = InvIssue::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'ISS-1',
            'store_id' => $store->id,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 60,
            'hari_share_pct' => 40,
        ]);
        InvIssueLine::create([
            'tenant_id' => $tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $item->id,
            'qty' => 2,
        ]);
        app(InventoryPostingService::class)->postIssue($issue->id, $tenant->id, '2024-06-15', 'issue-2');

        $issue->refresh();
        $pgId = $issue->posting_group_id;
        $this->assertNotNull($pgId);
        $rowCount = AllocationRow::where('posting_group_id', $pgId)->where('project_id', $project->id)->count();
        $this->assertGreaterThan(1, $rowCount, 'Shared issue should produce multiple allocation rows for one posting group');

        $filters = ['from' => '2024-06-01', 'to' => '2024-06-30'];
        $svc = app(ProjectProfitabilityService::class);
        $report = $svc->getProjectProfitability($project->id, $tenant->id, $filters);
        $pgIds = $this->eligiblePostingGroupIdsForTest($svc, $project->id, $tenant->id, $filters['from'], $filters['to'], null);

        $this->assertEquals(1, count(array_unique($pgIds)), 'Same economic event must not add duplicate posting groups');
        $ledger = $this->ledgerNetIncomeAndExpenseForPostingGroups($tenant->id, $pgIds);
        $this->assertEqualsWithDelta($ledger['expense'], $report['totals']['cost'], 0.02);
        $this->assertEqualsWithDelta(100.00, $report['totals']['cost'], 0.02, 'Cost must be expense once, not per allocation row');
    }

    /**
     * Same economic scenario as {@see MachineryProfitabilityReportsTest::test_profitability_report_combines_usage_charges_and_costs}:
     * charge revenue vs fuel + labour + maintenance costs — machine report must not double-count charge expense as machine cost.
     */
    public function test_machine_profitability_matches_service_api_and_expected_accounting_totals(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P7G1-M', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['reports', 'machinery', 'inventory', 'labour', 'projects_crop_cycles']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TRK-01',
            'name' => 'Tractor 1',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $postingDate = '2024-06-15';

        $workLog = MachineWorkLog::create([
            'tenant_id' => $tenant->id,
            'work_log_no' => 'MWL-P7G1',
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'work_date' => $postingDate,
            'meter_start' => 100,
            'meter_end' => 106,
            'usage_qty' => 6,
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
        ]);
        app(MachineryPostingService::class)->postWorkLog($workLog->id, $tenant->id, $postingDate);

        MachineRateCard::create([
            'tenant_id' => $tenant->id,
            'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id,
            'machine_type' => null,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
            'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 50.00,
            'cost_plus_percent' => null,
            'includes_fuel' => true,
            'includes_operator' => true,
            'includes_maintenance' => true,
            'is_active' => true,
        ]);

        $chargeService = new MachineryChargeService;
        $charge = $chargeService->generateDraftChargeForProject(
            $tenant->id,
            $project->id,
            $landlordParty->id,
            $postingDate,
            $postingDate,
            MachineWorkLog::POOL_SCOPE_SHARED
        );
        app(MachineryChargePostingService::class)->postCharge($charge->id, $tenant->id, $postingDate);
        $charge->refresh();

        $expectedRevenue = 6.0 * 50.00;

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'L', 'name' => 'Liter']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fuel']);
        $fuelItem = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Diesel',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Store',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-P7G1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $fuelItem->id,
            'qty' => 100,
            'unit_cost' => 1.50,
            'line_total' => 150,
        ]);
        $inventoryPostingService = app(InventoryPostingService::class);
        $inventoryPostingService->postGRN($grn->id, $tenant->id, '2024-06-01', 'grn-p7g1-m');

        $issue = InvIssue::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'ISS-P7G1',
            'store_id' => $store->id,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'machine_id' => $machine->id,
            'doc_date' => $postingDate,
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 50,
            'hari_share_pct' => 50,
        ]);
        InvIssueLine::create([
            'tenant_id' => $tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $fuelItem->id,
            'qty' => 20,
        ]);
        $inventoryPostingService->postIssue($issue->id, $tenant->id, $postingDate, 'issue-p7g1-m');
        $expectedFuelCost = 30.00;

        $worker = LabWorker::create([
            'tenant_id' => $tenant->id,
            'name' => 'Worker 1',
            'worker_no' => 'W-P7G1',
            'worker_type' => 'STAFF',
            'rate_basis' => 'DAILY',
            'default_rate' => 100,
            'is_active' => true,
        ]);
        $labWorkLog = LabWorkLog::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'WL-P7G1',
            'worker_id' => $worker->id,
            'work_date' => $postingDate,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'machine_id' => $machine->id,
            'rate_basis' => 'DAILY',
            'units' => 1,
            'rate' => 100,
            'amount' => 100,
            'status' => 'DRAFT',
        ]);
        app(LabourPostingService::class)->postWorkLog($labWorkLog->id, $tenant->id, $postingDate);
        $expectedLabourCost = 100.00;

        $maintenanceJob = MachineMaintenanceJob::create([
            'tenant_id' => $tenant->id,
            'job_no' => 'MMJ-P7G1',
            'status' => MachineMaintenanceJob::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'maintenance_type_id' => null,
            'vendor_party_id' => null,
            'job_date' => $postingDate,
            'notes' => 'Oil change',
            'total_amount' => 150.00,
        ]);
        MachineMaintenanceJobLine::create([
            'tenant_id' => $tenant->id,
            'job_id' => $maintenanceJob->id,
            'description' => 'Oil change',
            'amount' => 150.00,
        ]);
        app(MachineMaintenancePostingService::class)->postJob($maintenanceJob->id, $tenant->id, $postingDate);

        // MachineProfitabilityService::costByMachine attributes issues + labour to machine; maintenance is not summed here
        // (same split as legacy machinery margin report vs internal machine P&amp;L scope).
        $expectedMachineAttributedCost = $expectedFuelCost + $expectedLabourCost;
        $expectedProfit = $expectedRevenue - $expectedMachineAttributedCost;

        $filters = ['from' => $postingDate, 'to' => $postingDate, 'project_id' => $project->id];

        $svcRows = app(MachineProfitabilityService::class)->getMachineProfitability($tenant->id, $filters);
        $api = $this->withHeaders($this->auth($tenant))
            ->getJson('/api/reports/machine-profitability?'.http_build_query($filters));
        $api->assertStatus(200);
        $this->assertEquals($svcRows, $api->json(), 'HTTP machine-profitability must match MachineProfitabilityService');

        $row = collect($svcRows)->firstWhere('machine_id', $machine->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta($expectedRevenue, $row['revenue'], 0.02, 'Machine revenue = posted charge allocation (no duplicate from expense side)');
        $this->assertEqualsWithDelta($expectedMachineAttributedCost, $row['cost'], 0.02, 'Machine cost = operating allocations to machine_id (charge AP not double-counted as machine opex)');
        $this->assertEqualsWithDelta($expectedProfit, $row['profit'], 0.02);
        $this->assertEqualsWithDelta(round($row['revenue'] - $row['cost'], 2), $row['profit'], 0.01);

        // If charge expense were wrongly included as machine opex, cost would approach fuel+labour+full AP; actual is fuel+labour only.
        $this->assertLessThan(200.0, $row['cost'], 'Machine cost bucket excludes charge-side pool expense double-count');
    }

    public function test_harvest_economics_matches_harvest_production_allocations_and_api(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P7G1-H', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['reports', 'crop_ops', 'inventory']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'H', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'F',
            'status' => 'ACTIVE',
        ]);
        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'KG', 'name' => 'Kg']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Produce']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Wheat',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        // WIP capital for harvest valuation (same pattern as HarvestSharePhase3bTest).
        $cropWip = \App\Models\Account::where('tenant_id', $tenant->id)->where('code', 'CROP_WIP')->first();
        $cash = \App\Models\Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $pgWip = \App\Models\PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => \Illuminate\Support\Str::uuid()->toString(),
            'posting_date' => '2024-05-01',
            'idempotency_key' => 'wip-p7g1-'.uniqid(),
        ]);
        \App\Models\LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgWip->id,
            'account_id' => $cropWip->id,
            'debit_amount' => '100',
            'credit_amount' => '0',
            'currency_code' => 'GBP',
        ]);
        \App\Models\LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgWip->id,
            'account_id' => $cash->id,
            'debit_amount' => '0',
            'credit_amount' => '100',
            'currency_code' => 'GBP',
        ]);

        $harvest = Harvest::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);
        HarvestLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $item->id,
            'store_id' => $store->id,
            'quantity' => 10,
        ]);

        $this->withHeaders($this->auth($tenant))
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $harvest->refresh();
        $this->assertEquals('POSTED', $harvest->status);
        $this->assertNotNull($harvest->posting_group_id);

        $eco = app(HarvestEconomicsService::class)->getHarvestEconomics($harvest->id, $tenant->id);
        $prodRows = AllocationRow::query()
            ->where('tenant_id', $tenant->id)
            ->where('posting_group_id', $harvest->posting_group_id)
            ->where('allocation_type', 'HARVEST_PRODUCTION')
            ->get();

        $sumQty = (float) $prodRows->sum(fn ($r) => (float) ($r->quantity ?? 0));
        $sumVal = (float) $prodRows->sum(fn ($r) => (float) ($r->amount_base ?? $r->amount ?? 0));

        $this->assertEqualsWithDelta($sumQty, $eco['total_output_qty'], 0.001);
        $this->assertEqualsWithDelta($sumVal, $eco['total_output_value'], 0.02);

        $doc = $this->withHeaders($this->auth($tenant))
            ->getJson('/api/reports/harvest-economics?harvest_id='.$harvest->id);
        $doc->assertStatus(200);
        $payload = $doc->json();
        $this->assertEqualsWithDelta((float) $eco['total_output_value'], (float) $payload['economics']['total_output_value'], 0.02);
        $this->assertEqualsWithDelta((float) $eco['total_output_qty'], (float) $payload['economics']['total_output_qty'], 0.001);
    }
}

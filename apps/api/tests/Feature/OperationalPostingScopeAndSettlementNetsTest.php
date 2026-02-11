<?php

namespace Tests\Feature;

use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\OperationalTransaction;
use App\Models\PostingGroup;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Tenant;
use App\Services\PostingService;
use App\Services\SettlementService;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalPostingScopeAndSettlementNetsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Project $project;
    private ProjectRule $projectRule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $hariParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        $this->projectRule = ProjectRule::create([
            'project_id' => $this->project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);
    }

    private function createAndPostExpense(string $classification, float $amount = 100.00): OperationalTransaction
    {
        $txn = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => $amount,
            'classification' => $classification,
        ]);
        app(PostingService::class)->postOperationalTransaction($txn->id, $this->tenant->id, '2024-06-15', 'idem-' . $txn->id);
        return $txn->fresh();
    }

    public function test_posting_shared_expense_creates_allocation_scope_shared(): void
    {
        $txn = $this->createAndPostExpense('SHARED', 75.00);
        $this->assertEquals('POSTED', $txn->status);
        $row = AllocationRow::where('posting_group_id', $txn->posting_group_id)->first();
        $this->assertNotNull($row);
        $this->assertEquals('SHARED', $row->allocation_scope);
        $this->assertEquals('POOL_SHARE', $row->allocation_type);
    }

    public function test_posting_hari_only_expense_creates_allocation_scope_hari_only(): void
    {
        $txn = $this->createAndPostExpense('HARI_ONLY', 50.00);
        $this->assertEquals('POSTED', $txn->status);
        $row = AllocationRow::where('posting_group_id', $txn->posting_group_id)->first();
        $this->assertNotNull($row);
        $this->assertEquals('HARI_ONLY', $row->allocation_scope);
        $this->assertEquals('HARI_ONLY', $row->allocation_type);
    }

    public function test_posting_landlord_only_expense_creates_allocation_scope_landlord_only(): void
    {
        $txn = $this->createAndPostExpense('LANDLORD_ONLY', 30.00);
        $this->assertEquals('POSTED', $txn->status);
        $row = AllocationRow::where('posting_group_id', $txn->posting_group_id)->first();
        $this->assertNotNull($row);
        $this->assertEquals('LANDLORD_ONLY', $row->allocation_scope);
        $this->assertEquals('LANDLORD_ONLY', $row->allocation_type);
    }

    public function test_settlement_hari_net_less_than_landlord_net_when_hari_only_expense_exists(): void
    {
        OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-01',
            'amount' => 200.00,
            'classification' => 'SHARED',
        ]);
        $incomeTxn = OperationalTransaction::where('project_id', $this->project->id)->where('type', 'INCOME')->first();
        app(PostingService::class)->postOperationalTransaction($incomeTxn->id, $this->tenant->id, '2024-06-01', 'idem-income');

        $this->createAndPostExpense('SHARED', 60.00);
        $this->createAndPostExpense('HARI_ONLY', 20.00);

        $settlementService = app(SettlementService::class);
        $preview = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');

        $poolProfit = (float) $preview['pool_profit'];
        $this->assertEqualsWithDelta(200.0 - 60.0, $poolProfit, 0.01, 'pool_profit = revenue - shared_pool_expenses only');
        $landlordGross = (float) $preview['landlord_gross'];
        $hariGross = (float) $preview['hari_gross'];
        $landlordNet = (float) $preview['landlord_net'];
        $hariNet = (float) $preview['hari_net'];
        $hariOnlyDeductions = (float) $preview['hari_only_deductions'];

        $this->assertEqualsWithDelta(20.0, $hariOnlyDeductions, 0.01);
        $this->assertEqualsWithDelta($hariGross - 20.0, $hariNet, 0.01, 'hari_net = hari_gross - hari_only_deductions');
        $this->assertLessThan($landlordNet, $hariNet, 'With hari_only expense, hari_net should be less than landlord_net');
    }

    public function test_settlement_totals_reconcile(): void
    {
        $this->createAndPostExpense('SHARED', 40.00);
        $this->createAndPostExpense('HARI_ONLY', 25.00);
        $this->createAndPostExpense('LANDLORD_ONLY', 15.00);

        $preview = app(SettlementService::class)->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');

        $totalExpenses = (float) $preview['total_expenses'];
        $shared = (float) ($preview['shared_pool_expenses'] ?? $preview['shared_costs'] ?? 0);
        $hariOnly = (float) $preview['hari_only_deductions'];
        $landlordOnly = (float) $preview['landlord_only_costs'];

        $this->assertEqualsWithDelta(80.0, $totalExpenses, 0.01);
        $this->assertEqualsWithDelta($shared + $hariOnly + $landlordOnly, $totalExpenses, 0.01,
            'total_expenses must equal shared_pool_expenses + hari_only_deductions + landlord_only_costs');
    }

    /**
     * Crop cycle settlement posting creates a posting_group with source_type CROP_CYCLE_SETTLEMENT.
     * Ensures posting_group_source_type enum includes CROP_CYCLE_SETTLEMENT.
     */
    public function test_crop_cycle_settlement_posting_creates_posting_group_with_crop_cycle_settlement_source_type(): void
    {
        OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-01',
            'amount' => 300.00,
            'classification' => 'SHARED',
        ]);
        $incomeTxn = OperationalTransaction::where('project_id', $this->project->id)->where('type', 'INCOME')->first();
        app(PostingService::class)->postOperationalTransaction($incomeTxn->id, $this->tenant->id, '2024-06-01', 'idem-cc-income');

        $this->createAndPostExpense('SHARED', 100.00);

        $settlementService = app(SettlementService::class);
        $result = $settlementService->settleCropCycle(
            $this->cropCycle->id,
            $this->tenant->id,
            '2024-06-30',
            'crop-cycle-settlement-idem-1'
        );

        $postingGroup = $result['posting_group'];
        $this->assertInstanceOf(PostingGroup::class, $postingGroup);
        $this->assertEquals('CROP_CYCLE_SETTLEMENT', $postingGroup->source_type);
    }
}

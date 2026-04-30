<?php

namespace Tests\Feature;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\LedgerEntry;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettlementPackPhase1ProjectReportTest extends TestCase
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

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    public function test_settlement_pack_phase1_project_totals_and_registers(): void
    {
        $tenant = Tenant::create(['name' => 'SP1', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['reports', 'projects_crop_cycles']);

        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'party_types' => ['HARI']]);
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
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);

        $inputsAcc = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->firstOrFail();
        $labourAcc = Account::where('tenant_id', $tenant->id)->where('code', 'LABOUR_EXPENSE')->firstOrFail();

        $mappedCodes = [
            'INPUTS_EXPENSE', 'STOCK_VARIANCE', 'COGS_PRODUCE', 'LOAN_INTEREST_EXPENSE',
            'LABOUR_EXPENSE', 'EXP_KAMDARI', 'EXP_HARI_ONLY',
            'MACHINERY_FUEL_EXPENSE', 'MACHINERY_OPERATOR_EXPENSE', 'MACHINERY_MAINTENANCE_EXPENSE',
            'MACHINERY_OTHER_EXPENSE', 'MACHINERY_SERVICE_EXPENSE', 'EXP_SHARED', 'EXP_FARM_OVERHEAD',
            'FIXED_ASSET_DEPRECIATION_EXPENSE', 'LOSS_ON_FIXED_ASSET_DISPOSAL',
            'EXP_LANDLORD_ONLY',
        ];
        $otherAcc = Account::where('tenant_id', $tenant->id)
            ->where('type', 'expense')
            ->whereNotIn('code', $mappedCodes)
            ->firstOrFail();

        // Post costs in period (eligible via allocation row).
        $pg1 = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2026-01-10',
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg1->id,
            'account_id' => $inputsAcc->id,
            'debit_amount' => 60,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg1->id,
            'account_id' => $labourAcc->id,
            'debit_amount' => 40,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg1->id,
            'account_id' => $otherAcc->id,
            'debit_amount' => 5,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg1->id,
            'project_id' => $project->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 0,
            'amount_base' => 0,
            'rule_snapshot' => ['fixture' => true],
        ]);

        // Credit premium in period (POSTED invoice, allocation type).
        $inv = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'project_id' => $project->id,
            'invoice_date' => '2026-01-15',
            'currency_code' => 'GBP',
            'payment_terms' => 'CREDIT',
            'subtotal_amount' => '12.00',
            'tax_amount' => '0.00',
            'total_amount' => '12.00',
            'status' => SupplierInvoice::STATUS_POSTED,
            'posted_at' => now(),
        ]);
        $pgPrem = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'source_type' => 'SUPPLIER_INVOICE',
            'source_id' => $inv->id,
            'posting_date' => '2026-01-20',
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgPrem->id,
            'project_id' => $project->id,
            'allocation_type' => 'SUPPLIER_INVOICE_CREDIT_PREMIUM',
            'amount' => 12,
            'amount_base' => 12,
            'rule_snapshot' => ['fixture' => 'premium'],
        ]);

        // Posted harvest production should appear as harvest production qty/value.
        $pgHarvest = PostingGroup::create([
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
            'project_id' => $project->id,
            'harvest_date' => '2026-01-25',
            'posting_date' => '2026-01-25',
            'status' => 'POSTED',
            'posting_group_id' => $pgHarvest->id,
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgHarvest->id,
            'project_id' => $project->id,
            'allocation_type' => 'HARVEST_PRODUCTION',
            'quantity' => 100,
            'amount' => 250,
            'amount_base' => 250,
            'rule_snapshot' => ['recipient_role' => 'OWNER'],
        ]);

        // Reversed posting group should be excluded (would add extra cost if included).
        $pgRevBase = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2026-01-30',
            'idempotency_key' => 'test-' . (string) Str::uuid(),
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgRevBase->id,
            'account_id' => $inputsAcc->id,
            'debit_amount' => 999,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgRevBase->id,
            'project_id' => $project->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 0,
            'amount_base' => 0,
            'rule_snapshot' => ['fixture' => 'reversed'],
        ]);
        PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cc->id,
            'source_type' => 'REVERSAL',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2026-02-01',
            'idempotency_key' => 'test-' . (string) Str::uuid(),
            'reversal_of_posting_group_id' => $pgRevBase->id,
        ]);

        $res = $this->withHeaders($this->headers($tenant))
            ->getJson('/api/reports/settlement-pack/project?project_id='.$project->id.'&from=2026-01-01&to=2026-01-31&include_register=both');

        $res->assertStatus(200);
        $this->assertSame('project', $res->json('scope.kind'));
        $this->assertSame('100.000', $res->json('totals.harvest_production.qty'));
        $this->assertSame('250.00', $res->json('totals.harvest_production.value'));

        // Costs: 60 + 40 + 5 + 12 premium = 117.
        $this->assertSame('60.00', $res->json('totals.costs.inputs'));
        $this->assertSame('40.00', $res->json('totals.costs.labour'));
        $this->assertSame('5.00', $res->json('totals.costs.other'));
        $this->assertSame('12.00', $res->json('totals.costs.credit_premium'));
        $this->assertSame('117.00', $res->json('totals.costs.total'));

        // Nets.
        $this->assertSame('-117.00', $res->json('totals.net.net_ledger_result'));
        $this->assertSame('133.00', $res->json('totals.net.net_harvest_production_result')); // 250 - 117

        // Register present.
        $this->assertNotEmpty($res->json('register.allocation_rows.rows'));
        $this->assertNotEmpty($res->json('register.ledger_lines.rows'));
    }
}


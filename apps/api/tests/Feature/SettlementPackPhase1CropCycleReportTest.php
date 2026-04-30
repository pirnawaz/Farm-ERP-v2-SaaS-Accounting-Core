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

class SettlementPackPhase1CropCycleReportTest extends TestCase
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

    public function test_settlement_pack_phase1_crop_cycle_rollup(): void
    {
        $tenant = Tenant::create(['name' => 'SP1CC', 'status' => 'active', 'currency_code' => 'GBP']);
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
        $p1 = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'A', 'status' => 'ACTIVE']);
        $p2 = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'B', 'status' => 'ACTIVE']);

        $inputsAcc = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->firstOrFail();

        // Costs: p1 inputs 10, p2 inputs 20.
        foreach ([[$p1, 10.0], [$p2, 20.0]] as [$p, $amt]) {
            $pg = PostingGroup::create([
                'tenant_id' => $tenant->id,
                'crop_cycle_id' => $cc->id,
                'source_type' => 'JOURNAL_ENTRY',
                'source_id' => (string) Str::uuid(),
                'posting_date' => '2026-01-10',
                'idempotency_key' => 'test-' . (string) Str::uuid(),
            ]);
            LedgerEntry::create([
                'tenant_id' => $tenant->id,
                'posting_group_id' => $pg->id,
                'account_id' => $inputsAcc->id,
                'debit_amount' => $amt,
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);
            AllocationRow::create([
                'tenant_id' => $tenant->id,
                'posting_group_id' => $pg->id,
                'project_id' => $p->id,
                'allocation_type' => 'POOL_SHARE',
                'amount' => 0,
                'amount_base' => 0,
                'rule_snapshot' => ['fixture' => true],
            ]);
        }

        // Premium: p1 only (12).
        $inv = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'project_id' => $p1->id,
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
            'project_id' => $p1->id,
            'allocation_type' => 'SUPPLIER_INVOICE_CREDIT_PREMIUM',
            'amount' => 12,
            'amount_base' => 12,
            'rule_snapshot' => ['fixture' => 'premium'],
        ]);

        // Harvest: p2 only (qty 50 value 80).
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
            'project_id' => $p2->id,
            'harvest_date' => '2026-01-25',
            'posting_date' => '2026-01-25',
            'status' => 'POSTED',
            'posting_group_id' => $pgHarvest->id,
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgHarvest->id,
            'project_id' => $p2->id,
            'allocation_type' => 'HARVEST_PRODUCTION',
            'quantity' => 50,
            'amount' => 80,
            'amount_base' => 80,
            'rule_snapshot' => ['recipient_role' => 'OWNER'],
        ]);

        $res = $this->withHeaders($this->headers($tenant))
            ->getJson('/api/reports/settlement-pack/crop-cycle?crop_cycle_id='.$cc->id.'&from=2026-01-01&to=2026-01-31&include_register=allocation');

        $res->assertStatus(200);
        $this->assertSame('crop_cycle', $res->json('scope.kind'));
        $this->assertCount(2, $res->json('scope.project_ids'));

        $this->assertSame('50.000', $res->json('totals.harvest_production.qty'));
        $this->assertSame('80.00', $res->json('totals.harvest_production.value'));

        // Total costs: inputs 30 + premium 12 = 42.
        $this->assertSame('30.00', $res->json('totals.costs.inputs'));
        $this->assertSame('12.00', $res->json('totals.costs.credit_premium'));
        $this->assertSame('42.00', $res->json('totals.costs.total'));

        $this->assertSame('38.00', $res->json('totals.net.net_harvest_production_result')); // 80 - 42
        $this->assertNotEmpty($res->json('register.allocation_rows.rows'));
    }
}


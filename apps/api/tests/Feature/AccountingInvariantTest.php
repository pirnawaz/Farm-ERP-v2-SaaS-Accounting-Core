<?php

namespace Tests\Feature;

use App\Domains\Accounting\Loans\LoanAgreement;
use App\Domains\Accounting\Loans\LoanDrawdown;
use App\Domains\Accounting\Loans\LoanRepayment;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Exceptions\PostedSourceDocumentImmutableException;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Module;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1H.9 — Architecture regression tests for accounting invariants (API-only, no browser).
 */
class AccountingInvariantTest extends TestCase
{
    use RefreshDatabase;

    private function enableModule(Tenant $tenant, string $key): void
    {
        $m = Module::where('key', $key)->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function enableAccountingModules(Tenant $tenant): void
    {
        foreach (['settlements', 'loans', 'treasury_payments'] as $key) {
            $this->enableModule($tenant, $key);
        }
    }

    public function test_double_post_does_not_duplicate_posting_group(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Inv Idem', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'loans');

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Lender',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);
        $agreement = LoanAgreement::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'lender_party_id' => $party->id,
            'reference_no' => 'LA-ID',
            'principal_amount' => 5000,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);
        $drawdown = LoanDrawdown::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'drawdown_date' => '2024-06-15',
            'amount' => 800.00,
            'status' => LoanDrawdown::STATUS_DRAFT,
        ]);

        $payload = [
            'posting_date' => '2024-06-20',
            'idempotency_key' => 'invariant-double-post',
            'funding_account' => 'BANK',
        ];

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$drawdown->id}/post", $payload);
        $r1->assertStatus(201);
        $pg1 = $r1->json('id');

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$drawdown->id}/post", $payload);
        $r2->assertStatus(201);
        $this->assertSame($pg1, $r2->json('id'));

        $this->assertSame(
            1,
            PostingGroup::where('tenant_id', $tenant->id)
                ->where('source_type', 'LOAN_DRAWDOWN')
                ->where('source_id', $drawdown->id)
                ->count()
        );
    }

    public function test_posting_requires_posting_date(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Inv No Date', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendor',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'P1',
            'status' => 'ACTIVE',
        ]);
        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'INV-ND',
            'invoice_date' => '2024-06-10',
            'currency_code' => 'GBP',
            'subtotal_amount' => 50,
            'tax_amount' => 0,
            'total_amount' => 50,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 50,
            'tax_amount' => 0,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'idempotency_key' => 'no-date',
            ])
            ->assertStatus(422);
    }

    public function test_posting_blocked_when_crop_cycle_closed(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Inv Closed', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'loans');

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Lender',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);
        $agreement = LoanAgreement::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'lender_party_id' => $party->id,
            'reference_no' => 'LA-CC',
            'principal_amount' => 10000,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);
        $drawdown = LoanDrawdown::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'drawdown_date' => '2024-06-15',
            'amount' => 500.00,
            'status' => LoanDrawdown::STATUS_DRAFT,
        ]);

        CropCycle::where('id', $project->crop_cycle_id)->update(['status' => 'CLOSED']);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$drawdown->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'closed-cc',
                'funding_account' => 'CASH',
            ])
            ->assertStatus(422);
    }

    public function test_posted_records_cannot_be_modified(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Inv Immut', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendor',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'P1',
            'status' => 'ACTIVE',
        ]);
        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'INV-IM',
            'invoice_date' => '2024-06-10',
            'currency_code' => 'GBP',
            'subtotal_amount' => 40,
            'tax_amount' => 0,
            'total_amount' => 40,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 40,
            'tax_amount' => 0,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'immut-1',
            ])
            ->assertStatus(201);

        $invoice->refresh();
        $this->assertSame(SupplierInvoice::STATUS_POSTED, $invoice->status);

        $this->expectException(PostedSourceDocumentImmutableException::class);
        $invoice->update(['notes' => 'must not persist']);
    }

    public function test_settlement_pack_generate_does_not_create_ledger_entries(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Inv Pack', 'status' => 'active', 'currency_code' => 'GBP']);
        $this->enableModule($tenant, 'settlements');

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-06-01',
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 100.00,
        ]);

        $before = LedgerEntry::count();

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", [])
            ->assertStatus(201);

        $this->assertSame($before, LedgerEntry::count(), 'Settlement pack generation must not insert ledger rows');
    }

    public function test_loan_full_flow_drawdown_and_repayment_post(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Inv Loan', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'loans');

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Lender',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);
        $agreement = LoanAgreement::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'lender_party_id' => $party->id,
            'reference_no' => 'LA-FLOW',
            'principal_amount' => 10000,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);

        $drawdown = LoanDrawdown::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'drawdown_date' => '2024-06-15',
            'amount' => 1200.00,
            'status' => LoanDrawdown::STATUS_DRAFT,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$drawdown->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'flow-dd',
                'funding_account' => 'BANK',
            ])
            ->assertStatus(201);

        $drawdown->refresh();
        $this->assertSame(LoanDrawdown::STATUS_POSTED, $drawdown->status);
        $this->assertNotNull($drawdown->posting_group_id);
        $ddPg = $drawdown->posting_group_id;
        $ddDr = (float) LedgerEntry::where('posting_group_id', $ddPg)->sum('debit_amount');
        $ddCr = (float) LedgerEntry::where('posting_group_id', $ddPg)->sum('credit_amount');
        $this->assertEqualsWithDelta(1200.0, $ddDr, 0.02);
        $this->assertEqualsWithDelta(1200.0, $ddCr, 0.02);

        $repayment = LoanRepayment::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'repayment_date' => '2024-07-01',
            'amount' => 400.00,
            'principal_amount' => 300.00,
            'interest_amount' => 100.00,
            'status' => LoanRepayment::STATUS_DRAFT,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-repayments/{$repayment->id}/post", [
                'posting_date' => '2024-07-05',
                'idempotency_key' => 'flow-rp',
                'funding_account' => 'BANK',
            ])
            ->assertStatus(201);

        $repayment->refresh();
        $this->assertSame(LoanRepayment::STATUS_POSTED, $repayment->status);
        $rpPg = $repayment->posting_group_id;
        $this->assertNotNull($rpPg);
        $rpDr = (float) LedgerEntry::where('posting_group_id', $rpPg)->sum('debit_amount');
        $rpCr = (float) LedgerEntry::where('posting_group_id', $rpPg)->sum('credit_amount');
        $this->assertEqualsWithDelta(400.0, $rpDr, 0.02);
        $this->assertEqualsWithDelta(400.0, $rpCr, 0.02);

        $this->assertSame(1, PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'LOAN_DRAWDOWN')->where('source_id', $drawdown->id)->count());
        $this->assertSame(1, PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'LOAN_REPAYMENT')->where('source_id', $repayment->id)->count());
    }

    public function test_ap_full_flow_supplier_invoice_post_balances_ledger(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Inv AP', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier Co',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'INV-FLOW',
            'invoice_date' => '2024-06-10',
            'currency_code' => 'GBP',
            'subtotal_amount' => 250,
            'tax_amount' => 0,
            'total_amount' => 250,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Stock',
            'line_total' => 250,
            'tax_amount' => 0,
        ]);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'ap-flow',
            ]);
        $res->assertStatus(201);
        $pgId = $res->json('id');

        $invoice->refresh();
        $this->assertSame(SupplierInvoice::STATUS_POSTED, $invoice->status);
        $this->assertSame($pgId, $invoice->posting_group_id);

        $this->assertGreaterThan(0, AllocationRow::where('posting_group_id', $pgId)->count());

        $sumDr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount');
        $sumCr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount');
        $this->assertEqualsWithDelta(250.0, $sumDr, 0.02);
        $this->assertEqualsWithDelta(250.0, $sumCr, 0.02);
    }

    public function test_tenant_isolation_enforced_for_cross_tenant_reads(): void
    {
        (new ModulesSeeder)->run();
        $tenantA = Tenant::create(['name' => 'Inv A', 'status' => 'active', 'currency_code' => 'GBP']);
        $tenantB = Tenant::create(['name' => 'Inv B', 'status' => 'active', 'currency_code' => 'GBP']);
        $this->enableAccountingModules($tenantA);
        $this->enableAccountingModules($tenantB);

        $cycle = CropCycle::create([
            'tenant_id' => $tenantB->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $partyB = Party::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Party B',
            'party_types' => ['LANDLORD'],
        ]);
        $projectB = Project::create([
            'tenant_id' => $tenantB->id,
            'party_id' => $partyB->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Project B',
            'status' => 'ACTIVE',
        ]);

        $gen = $this->withHeader('X-Tenant-Id', $tenantB->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$projectB->id}/settlement-pack", []);
        $gen->assertStatus(201);
        $packId = $gen->json('id');

        $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/settlement-packs/{$packId}")
            ->assertStatus(404);

        $agreement = LoanAgreement::create([
            'tenant_id' => $tenantB->id,
            'project_id' => $projectB->id,
            'lender_party_id' => $partyB->id,
            'reference_no' => 'LA-B',
            'principal_amount' => 1000,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);

        $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/loan-agreements/{$agreement->id}")
            ->assertStatus(404);

        $supplier = Party::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Vendor B',
            'party_types' => ['VENDOR'],
        ]);
        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenantB->id,
            'party_id' => $supplier->id,
            'project_id' => $projectB->id,
            'reference_no' => 'SINV-B',
            'invoice_date' => '2024-06-10',
            'currency_code' => 'GBP',
            'subtotal_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);

        $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertStatus(404);

        $payment = Payment::create([
            'tenant_id' => $tenantB->id,
            'party_id' => $partyB->id,
            'direction' => 'OUT',
            'amount' => 50,
            'payment_date' => '2024-06-01',
            'method' => 'CASH',
            'status' => 'DRAFT',
            'purpose' => 'GENERAL',
        ]);

        $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/payments/{$payment->id}")
            ->assertStatus(404);
    }
}

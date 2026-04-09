<?php

namespace Tests\Feature;

use App\Domains\Accounting\Loans\LoanAgreement;
use App\Domains\Accounting\Loans\LoanDrawdown;
use App\Domains\Accounting\Loans\LoanRepayment;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Exceptions\PostedSourceDocumentImmutableException;
use App\Models\CropCycle;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvUom;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostedRecordImmutabilityPhase1H7Test extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_triggers_exist_for_ledger_entries_and_posting_groups(): void
    {
        $ledger = DB::selectOne("
            SELECT 1 AS ok FROM pg_trigger
            WHERE tgrelid = 'ledger_entries'::regclass
              AND tgname = 'trg_block_ledger_entries_mutation'
        ");
        $this->assertNotNull($ledger, 'Expected trg_block_ledger_entries_mutation on ledger_entries');

        $pg = DB::selectOne("
            SELECT 1 AS ok FROM pg_trigger
            WHERE tgrelid = 'posting_groups'::regclass
              AND tgname = 'trg_block_posting_groups_mutation'
        ");
        $this->assertNotNull($pg, 'Expected trg_block_posting_groups_mutation on posting_groups');
    }

    private function enableLoans(Tenant $tenant): void
    {
        $m = Module::where('key', 'loans')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    /** @return array{tenant: Tenant, drawdown: LoanDrawdown} */
    private function loanDrawdownFixtures(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Immut DD', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLoans($tenant);

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
            'reference_no' => 'LA-IM',
            'principal_amount' => 10000,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);

        $drawdown = LoanDrawdown::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'drawdown_date' => '2024-06-15',
            'amount' => 1500.00,
            'status' => LoanDrawdown::STATUS_DRAFT,
        ]);

        return ['tenant' => $tenant, 'drawdown' => $drawdown];
    }

    public function test_posted_loan_drawdown_cannot_be_updated_or_deleted(): void
    {
        $data = $this->loanDrawdownFixtures();
        $tenant = $data['tenant'];
        $drawdown = $data['drawdown'];

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$drawdown->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'immut-dd-1',
                'funding_account' => 'BANK',
            ])
            ->assertStatus(201);

        $drawdown->refresh();

        $this->expectException(PostedSourceDocumentImmutableException::class);
        $drawdown->update(['notes' => 'tamper']);
    }

    public function test_posted_loan_drawdown_delete_throws(): void
    {
        $data = $this->loanDrawdownFixtures();
        $tenant = $data['tenant'];
        $drawdown = $data['drawdown'];

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$drawdown->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'immut-dd-2',
                'funding_account' => 'BANK',
            ])
            ->assertStatus(201);

        $drawdown->refresh();

        $this->expectException(PostedSourceDocumentImmutableException::class);
        $drawdown->delete();
    }

    /** @return array{tenant: Tenant, repayment: LoanRepayment} */
    private function loanRepaymentFixtures(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Immut RP', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLoans($tenant);

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
            'reference_no' => 'LA-RP',
            'principal_amount' => 10000,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);

        $repayment = LoanRepayment::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'repayment_date' => '2024-07-01',
            'amount' => 1000.00,
            'principal_amount' => 700.00,
            'interest_amount' => 300.00,
            'status' => LoanRepayment::STATUS_DRAFT,
        ]);

        return ['tenant' => $tenant, 'repayment' => $repayment];
    }

    public function test_posted_loan_repayment_update_throws(): void
    {
        $data = $this->loanRepaymentFixtures();
        $tenant = $data['tenant'];
        $repayment = $data['repayment'];

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-repayments/{$repayment->id}/post", [
                'posting_date' => '2024-07-05',
                'idempotency_key' => 'immut-rp-1',
                'funding_account' => 'BANK',
            ])
            ->assertStatus(201);

        $repayment->refresh();

        $this->expectException(PostedSourceDocumentImmutableException::class);
        $repayment->update(['notes' => 'x']);
    }

    public function test_posted_loan_repayment_delete_throws(): void
    {
        $data = $this->loanRepaymentFixtures();
        $tenant = $data['tenant'];
        $repayment = $data['repayment'];

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-repayments/{$repayment->id}/post", [
                'posting_date' => '2024-07-05',
                'idempotency_key' => 'immut-rp-2',
                'funding_account' => 'BANK',
            ])
            ->assertStatus(201);

        $repayment->refresh();

        $this->expectException(PostedSourceDocumentImmutableException::class);
        $repayment->delete();
    }

    /** @return array{tenant: Tenant, invoice: SupplierInvoice} */
    private function supplierInvoiceFixtures(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Immut SI', 'status' => 'active', 'currency_code' => 'GBP']);
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

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'KG', 'name' => 'Kg']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Inputs']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Seed',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'INV-IM',
            'invoice_date' => '2024-06-10',
            'currency_code' => 'GBP',
            'subtotal_amount' => 1000,
            'tax_amount' => 0,
            'total_amount' => 1000,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);

        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Stock line',
            'item_id' => $item->id,
            'qty' => 10,
            'unit_price' => 40,
            'line_total' => 400,
            'tax_amount' => 0,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 2,
            'description' => 'Service',
            'item_id' => null,
            'qty' => 1,
            'unit_price' => 600,
            'line_total' => 600,
            'tax_amount' => 0,
        ]);

        return ['tenant' => $tenant, 'invoice' => $invoice];
    }

    public function test_posted_supplier_invoice_update_throws(): void
    {
        $data = $this->supplierInvoiceFixtures();
        $tenant = $data['tenant'];
        $invoice = $data['invoice'];

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'immut-si-1',
            ])
            ->assertStatus(201);

        $invoice->refresh();

        $this->expectException(PostedSourceDocumentImmutableException::class);
        $invoice->update(['notes' => 'changed']);
    }

    public function test_posted_supplier_invoice_delete_throws(): void
    {
        $data = $this->supplierInvoiceFixtures();
        $tenant = $data['tenant'];
        $invoice = $data['invoice'];

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'immut-si-2',
            ])
            ->assertStatus(201);

        $invoice->refresh();

        $this->expectException(PostedSourceDocumentImmutableException::class);
        $invoice->delete();
    }
}

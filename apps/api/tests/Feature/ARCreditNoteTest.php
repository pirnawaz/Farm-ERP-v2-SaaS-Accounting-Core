<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\Sale;
use App\Models\Payment;
use App\Models\SalePaymentAllocation;
use App\Models\PostingGroup;
use App\Models\LedgerEntry;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class ARCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
        (new ModulesSeeder)->run();
    }

    private function enableModules(Tenant $tenant, array $keys): void
    {
        foreach ($keys as $key) {
            $module = Module::where('key', $key)->first();
            if ($module) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    /**
     * Posting a credit note must DR Revenue / CR AR → reduces AR control in ledger.
     */
    public function test_posting_credit_note_reduces_ar_in_ledger(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales', 'reports']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $invoice = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 500.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$invoice->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cn-test-inv-1',
            ])
            ->assertStatus(201);

        $arAccount = Account::where('tenant_id', $tenant->id)->where('code', 'AR')->first();
        $this->assertNotNull($arAccount);
        $arBalanceAfterInvoice = (float) LedgerEntry::where('tenant_id', $tenant->id)
            ->where('account_id', $arAccount->id)
            ->selectRaw('COALESCE(SUM(debit_amount), 0) - COALESCE(SUM(credit_amount), 0) as net')
            ->value('net');
        $this->assertSame(500.0, round($arBalanceAfterInvoice, 2), 'AR should be 500 after invoice');

        $creditNote = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 150.00,
            'posting_date' => '2024-06-15',
            'sale_date' => '2024-06-15',
            'sale_kind' => Sale::SALE_KIND_CREDIT_NOTE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'cn-test-cn-1',
            ])
            ->assertStatus(201);

        $arBalanceAfterCreditNote = (float) LedgerEntry::where('tenant_id', $tenant->id)
            ->where('account_id', $arAccount->id)
            ->selectRaw('COALESCE(SUM(debit_amount), 0) - COALESCE(SUM(credit_amount), 0) as net')
            ->value('net');
        $this->assertSame(350.0, round($arBalanceAfterCreditNote, 2), 'AR should be 350 after credit note (500 - 150)');
    }

    /**
     * Credit note cannot be reversed if it has ACTIVE allocations (applied to invoices).
     */
    public function test_credit_note_cannot_be_reversed_if_has_active_allocations(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales', 'reports']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $invoice = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 400.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$invoice->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cn-guard-inv-1',
            ])
            ->assertStatus(201);
        $invoice->refresh();

        $creditNote = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 100.00,
            'posting_date' => '2024-06-10',
            'sale_date' => '2024-06-10',
            'sale_kind' => Sale::SALE_KIND_CREDIT_NOTE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'cn-guard-cn-1',
            ])
            ->assertStatus(201);
        $creditNote->refresh();
        $this->assertNotNull($creditNote->credit_note_payment_id);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/apply-to-invoices", [
                'allocation_date' => '2024-06-12',
                'allocations' => [['sale_id' => $invoice->id, 'amount' => '100.00']],
            ])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/reverse", [
                'reversal_date' => '2024-06-20',
                'reason' => 'Test',
            ]);
        $response->assertStatus(409);
        $this->assertStringContainsString('Unapply before reversing', $response->json('error') ?? '');
    }

    /**
     * Reversal excludes reversed docs from aging; aging at as_of is historically stable (allocation_date cutoff).
     */
    public function test_reversed_credit_note_excluded_from_aging(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales', 'reports']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $invoice = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 200.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'due_date' => '2024-07-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$invoice->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cn-aging-inv-1',
            ])
            ->assertStatus(201);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ar/aging?as_of=2024-07-01');
        $res->assertStatus(200);
        $this->assertSame('200.00', $res->json('grand_totals.total'));

        $creditNote = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 50.00,
            'posting_date' => '2024-06-15',
            'sale_date' => '2024-06-15',
            'sale_kind' => Sale::SALE_KIND_CREDIT_NOTE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'cn-aging-cn-1',
            ])
            ->assertStatus(201);
        $creditNote->refresh();

        $res2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ar/aging?as_of=2024-07-01');
        $res2->assertStatus(200);
        $this->assertSame('200.00', $res2->json('grand_totals.total'), 'Aging is invoice-centric; credit notes do not reduce invoice open balance in aging');

        $reversalPg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'source_type' => 'REVERSAL',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'posting_date' => '2024-06-20',
            'reversal_of_posting_group_id' => $creditNote->posting_group_id,
        ]);
        $creditNote->update([
            'status' => 'REVERSED',
            'reversal_posting_group_id' => $reversalPg->id,
        ]);
        Payment::where('id', $creditNote->credit_note_payment_id)->update([
            'reversed_at' => now(),
            'reversal_posting_group_id' => $reversalPg->id,
        ]);

        $res3 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ar/aging?as_of=2024-07-01');
        $res3->assertStatus(200);
        $this->assertSame('200.00', $res3->json('grand_totals.total'));
    }

    /**
     * Apply creates ACTIVE allocation; unapply sets VOID with audit fields; no ledger mutation.
     */
    public function test_apply_unapply_retains_audit_trail_and_does_not_mutate_ledger(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $invoice = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 300.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$invoice->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cn-audit-inv-1',
            ])
            ->assertStatus(201);
        $invoice->refresh();

        $creditNote = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 80.00,
            'posting_date' => '2024-06-10',
            'sale_date' => '2024-06-10',
            'sale_kind' => Sale::SALE_KIND_CREDIT_NOTE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'cn-audit-cn-1',
            ])
            ->assertStatus(201);
        $creditNote->refresh();
        $paymentId = $creditNote->credit_note_payment_id;
        $this->assertNotNull($paymentId);

        $ledgerCountBeforeApply = LedgerEntry::where('tenant_id', $tenant->id)->count();

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/apply-to-invoices", [
                'allocation_date' => '2024-06-12',
                'allocations' => [['sale_id' => $invoice->id, 'amount' => '80.00']],
            ])
            ->assertStatus(200);

        $ledgerCountAfterApply = LedgerEntry::where('tenant_id', $tenant->id)->count();
        $this->assertSame($ledgerCountBeforeApply, $ledgerCountAfterApply, 'Apply must not create ledger entries');

        $active = SalePaymentAllocation::where('tenant_id', $tenant->id)
            ->where('payment_id', $paymentId)
            ->where('sale_id', $invoice->id)
            ->where('status', SalePaymentAllocation::STATUS_ACTIVE)
            ->first();
        $this->assertNotNull($active);
        $this->assertSame('80.00', (string) $active->amount);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$paymentId}/unapply-sales", []);

        $voided = SalePaymentAllocation::where('tenant_id', $tenant->id)
            ->where('payment_id', $paymentId)
            ->where('sale_id', $invoice->id)
            ->where('status', SalePaymentAllocation::STATUS_VOID)
            ->first();
        $this->assertNotNull($voided);
        $this->assertNotNull($voided->voided_at);
        $ledgerCountAfterUnapply = LedgerEntry::where('tenant_id', $tenant->id)->count();
        $this->assertSame($ledgerCountBeforeApply, $ledgerCountAfterUnapply, 'Unapply must not create ledger entries');
    }
}

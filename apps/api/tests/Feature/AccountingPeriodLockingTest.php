<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class AccountingPeriodLockingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function tenantWithAccounts(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Period Lock Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        return $tenant;
    }

    /**
     * Posting blocked when period is closed.
     */
    public function test_posting_blocked_when_period_closed(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->first();

        $period = AccountingPeriod::create([
            'tenant_id' => $tenant->id,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'name' => '2026-02',
            'status' => AccountingPeriod::STATUS_OPEN,
        ]);
        $period->update(['status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => now()]);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2026-02-15',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 100, 'credit_amount' => 0],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 100],
                ],
            ]);
        $create->assertStatus(201);
        $journalId = $create->json('id');

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId}/post");
        $post->assertStatus(409);
        $this->assertStringContainsString('closed', strtolower($post->json('message') ?? ''));
    }

    /**
     * Posting allowed when period is open.
     */
    public function test_posting_allowed_when_period_open(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->first();

        AccountingPeriod::create([
            'tenant_id' => $tenant->id,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'name' => '2026-02',
            'status' => AccountingPeriod::STATUS_OPEN,
        ]);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2026-02-15',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 100, 'credit_amount' => 0],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 100],
                ],
            ]);
        $create->assertStatus(201);
        $journalId = $create->json('id');

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId}/post");
        $post->assertStatus(200);
        $journal = JournalEntry::where('id', $journalId)->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($journal->posting_group_id);
        $pg = PostingGroup::where('id', $journal->posting_group_id)->first();
        $this->assertNotNull($pg);
    }

    /**
     * Auto-create period if missing (Option B).
     */
    public function test_auto_create_period_if_missing(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->first();

        $periodBefore = AccountingPeriod::where('tenant_id', $tenant->id)
            ->where('period_start', '<=', '2026-03-05')
            ->where('period_end', '>=', '2026-03-05')
            ->first();
        $this->assertNull($periodBefore);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2026-03-05',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 50, 'credit_amount' => 0],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 50],
                ],
            ]);
        $create->assertStatus(201);
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals/' . $create->json('id') . '/post');
        $post->assertStatus(200);

        $period = AccountingPeriod::where('tenant_id', $tenant->id)
            ->where('period_start', '<=', '2026-03-05')
            ->where('period_end', '>=', '2026-03-05')
            ->first();
        $this->assertNotNull($period);
        $this->assertEquals(AccountingPeriod::STATUS_OPEN, $period->status);
        $this->assertEquals('2026-03', $period->name);
        $event = \App\Models\AccountingPeriodEvent::where('accounting_period_id', $period->id)->where('event_type', 'CREATED')->first();
        $this->assertNotNull($event);
    }

    /**
     * Overlap prevented when creating period.
     */
    public function test_overlap_prevented(): void
    {
        $tenant = $this->tenantWithAccounts();
        AccountingPeriod::create([
            'tenant_id' => $tenant->id,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'name' => '2026-02',
            'status' => AccountingPeriod::STATUS_OPEN,
        ]);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/accounting-periods', [
                'period_start' => '2026-02-15',
                'period_end' => '2026-03-15',
                'name' => 'Overlap',
            ]);
        $res->assertStatus(409);
        $this->assertStringContainsString('overlap', strtolower($res->json('message') ?? ''));
    }

    /**
     * Reversal policy: same date in closed period allowed; different date (e.g. March) blocked.
     */
    public function test_reversal_policy_enforced(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->first();

        AccountingPeriod::create([
            'tenant_id' => $tenant->id,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'name' => '2026-02',
            'status' => AccountingPeriod::STATUS_OPEN,
        ]);

        $create1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2026-02-10',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 100, 'credit_amount' => 0],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 100],
                ],
            ]);
        $create1->assertStatus(201);
        $journalId1 = $create1->json('id');
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId1}/post")
            ->assertStatus(200);

        $create2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2026-02-10',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 50, 'credit_amount' => 0],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 50],
                ],
            ]);
        $create2->assertStatus(201);
        $journalId2 = $create2->json('id');
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId2}/post")
            ->assertStatus(200);

        AccountingPeriod::create([
            'tenant_id' => $tenant->id,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'name' => '2026-03',
            'status' => AccountingPeriod::STATUS_CLOSED,
        ]);

        $period = AccountingPeriod::where('tenant_id', $tenant->id)->where('name', '2026-02')->first();
        $period->update(['status' => AccountingPeriod::STATUS_CLOSED, 'closed_at' => now()]);

        $reverseSameDate = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId1}/reverse", ['memo' => 'Reversal']);
        $reverseSameDate->assertStatus(200);

        $reverseMarch = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId2}/reverse", ['reversal_date' => '2026-03-01', 'memo' => 'Late reversal']);
        $reverseMarch->assertStatus(409);
        $this->assertStringContainsString('closed', strtolower($reverseMarch->json('message') ?? ''));
    }

    /**
     * Enforcement shared: payment posting blocked in closed period.
     */
    public function test_payment_posting_blocked_when_period_closed(): void
    {
        $tenant = $this->tenantWithAccounts();
        \App\Models\Module::firstOrCreate(['key' => 'treasury_payments'], ['name' => 'Payments', 'is_core' => true]);
        \App\Models\Module::firstOrCreate(['key' => 'inventory'], ['name' => 'Inventory', 'is_core' => true]);
        $pmModule = \App\Models\Module::where('key', 'treasury_payments')->first();
        $invModule = \App\Models\Module::where('key', 'inventory')->first();
        \App\Models\TenantModule::firstOrCreate(
            ['tenant_id' => $tenant->id, 'module_id' => $pmModule->id],
            ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null]
        );
        \App\Models\TenantModule::firstOrCreate(
            ['tenant_id' => $tenant->id, 'module_id' => $invModule->id],
            ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null]
        );
        $party = \App\Models\Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['VENDOR']]);
        $cropCycle = \App\Models\CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'C1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $store = \App\Models\InvStore::create(['tenant_id' => $tenant->id, 'name' => 'S', 'type' => 'MAIN', 'is_active' => true]);
        $uom = \App\Models\InvUom::create(['tenant_id' => $tenant->id, 'code' => 'EA', 'name' => 'Each']);
        $cat = \App\Models\InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'C']);
        $item = \App\Models\InvItem::create(['tenant_id' => $tenant->id, 'name' => 'I', 'uom_id' => $uom->id, 'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true]);

        AccountingPeriod::create([
            'tenant_id' => $tenant->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'name' => '2026-01',
            'status' => AccountingPeriod::STATUS_OPEN,
        ]);
        $grn = \App\Models\InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'G1',
            'store_id' => $store->id,
            'doc_date' => '2026-01-10',
            'status' => 'DRAFT',
            'supplier_party_id' => $party->id,
        ]);
        \App\Models\InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 1, 'unit_cost' => 10, 'line_total' => 10]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", ['posting_date' => '2026-01-10', 'idempotency_key' => 'period-lock-grn-1'])
            ->assertStatus(201);

        AccountingPeriod::create([
            'tenant_id' => $tenant->id,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'name' => '2026-02',
            'status' => AccountingPeriod::STATUS_CLOSED,
        ]);

        $payment = \App\Models\Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'OUT',
            'amount' => 10,
            'payment_date' => '2026-02-15',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2026-02-15',
                'idempotency_key' => 'period-lock-pmt-1',
                'crop_cycle_id' => $cropCycle->id,
            ]);
        $post->assertStatus(409);
        $this->assertStringContainsString('closed', strtolower($post->json('message') ?? ''));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\Party;
use App\Models\Machine;
use App\Models\MachineMaintenanceJob;
use App\Models\MachineMaintenanceJobLine;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Services\TenantContext;
use App\Services\Machinery\MachineMaintenancePostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class MachineMaintenanceJobPostingTest extends TestCase
{
    use RefreshDatabase;

    private function enableMachinery(Tenant $tenant): void
    {
        $m = Module::where('key', 'machinery')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function createDraftJob(Tenant $tenant, Machine $machine, ?Party $vendorParty = null): MachineMaintenanceJob
    {
        $job = MachineMaintenanceJob::create([
            'tenant_id' => $tenant->id,
            'job_no' => 'MMJ-000001',
            'status' => MachineMaintenanceJob::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'maintenance_type_id' => null,
            'vendor_party_id' => $vendorParty?->id,
            'job_date' => '2024-06-15',
            'notes' => 'Oil change and filter replacement',
            'total_amount' => 150.00,
        ]);

        MachineMaintenanceJobLine::create([
            'tenant_id' => $tenant->id,
            'job_id' => $job->id,
            'description' => 'Oil change',
            'amount' => 100.00,
        ]);

        MachineMaintenanceJobLine::create([
            'tenant_id' => $tenant->id,
            'job_id' => $job->id,
            'description' => 'Filter replacement',
            'amount' => 50.00,
        ]);

        return $job;
    }

    public function test_post_creates_posting_group_allocation_row_with_machine_id_and_balanced_ledger(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);

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

        $vendorParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendor ABC',
            'party_types' => ['VENDOR'],
        ]);

        $postingDate = '2024-06-15';
        $job = $this->createDraftJob($tenant, $machine, $vendorParty);
        $expectedAmount = 150.00;

        // Post job
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/maintenance-jobs/{$job->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => 'job-post-1',
            ]);
        $post->assertStatus(201);

        $job->refresh();
        $this->assertEquals(MachineMaintenanceJob::STATUS_POSTED, $job->status);
        $this->assertNotNull($job->posting_group_id);
        $this->assertNotNull($job->posting_date);
        $this->assertNotNull($job->posted_at);

        $pg = $post->json('posting_group');
        $this->assertNotNull($pg);
        $pgId = $pg['id'];
        $this->assertEquals('MACHINE_MAINTENANCE_JOB', $pg['source_type']);
        $this->assertEquals($job->id, $pg['source_id']);

        // Assert exactly one AllocationRow with MACHINERY_MAINTENANCE
        $allocationRows = AllocationRow::where('posting_group_id', $pgId)->get();
        $this->assertCount(1, $allocationRows);
        
        $allocation = $allocationRows->first();
        $this->assertEquals('MACHINERY_MAINTENANCE', $allocation->allocation_type);
        $this->assertEqualsWithDelta($expectedAmount, (float) $allocation->amount, 0.01);
        $this->assertNull($allocation->quantity);
        $this->assertNull($allocation->unit);
        $this->assertNull($allocation->project_id); // Maintenance not tied to project
        $this->assertEquals($job->vendor_party_id, $allocation->party_id);
        $this->assertEquals($job->machine_id, $allocation->machine_id); // Machine ID must be set

        // Assert balanced ledger entries
        $entries = LedgerEntry::where('posting_group_id', $pgId)->get();
        $this->assertCount(2, $entries);
        
        $debitTotal = 0;
        $creditTotal = 0;
        foreach ($entries as $entry) {
            $debitTotal += (float) $entry->debit_amount;
            $creditTotal += (float) $entry->credit_amount;
        }
        $this->assertEqualsWithDelta($expectedAmount, $debitTotal, 0.01);
        $this->assertEqualsWithDelta($expectedAmount, $creditTotal, 0.01);
        $this->assertEqualsWithDelta($debitTotal, $creditTotal, 0.01);
    }

    public function test_posting_is_idempotent_with_same_idempotency_key(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);

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
        $job = $this->createDraftJob($tenant, $machine);
        $idempotencyKey = 'job-post-idempotent';

        // Post first time
        $post1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/maintenance-jobs/{$job->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => $idempotencyKey,
            ]);
        $post1->assertStatus(201);
        $pgId1 = $post1->json('posting_group')['id'];

        // Post second time with same idempotency key
        $post2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/maintenance-jobs/{$job->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => $idempotencyKey,
            ]);
        $post2->assertStatus(201);
        $pgId2 = $post2->json('posting_group')['id'];

        // Should return same posting group
        $this->assertEquals($pgId1, $pgId2);

        // Should only have one posting group
        $pgCount = PostingGroup::where('tenant_id', $tenant->id)
            ->where('source_type', 'MACHINE_MAINTENANCE_JOB')
            ->where('source_id', $job->id)
            ->count();
        $this->assertEquals(1, $pgCount);
    }

    public function test_reverse_nets_allocations_and_ledger_to_zero(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);

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
        $job = $this->createDraftJob($tenant, $machine);
        $expectedAmount = 150.00;

        // Post job
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/maintenance-jobs/{$job->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => 'job-post-reverse',
            ]);
        $post->assertStatus(201);
        $originalPgId = $post->json('posting_group')['id'];

        // Reverse job
        $reverse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/maintenance-jobs/{$job->id}/reverse", [
                'posting_date' => '2024-06-16',
                'reason' => 'Error in job',
            ]);
        $reverse->assertStatus(201);

        $job->refresh();
        $this->assertEquals(MachineMaintenanceJob::STATUS_REVERSED, $job->status);
        $this->assertNotNull($job->reversal_posting_group_id);

        // Check allocations net to zero
        $originalAllocation = AllocationRow::where('posting_group_id', $originalPgId)->first();
        $reversalPgId = $job->reversal_posting_group_id;
        $reversalAllocation = AllocationRow::where('posting_group_id', $reversalPgId)->first();

        $this->assertNotNull($originalAllocation);
        $this->assertNotNull($reversalAllocation);
        $this->assertEqualsWithDelta($expectedAmount, (float) $originalAllocation->amount, 0.01);
        $this->assertEqualsWithDelta(-$expectedAmount, (float) $reversalAllocation->amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $originalAllocation->amount + (float) $reversalAllocation->amount, 0.01);

        // Check ledger entries net to zero
        $originalEntries = LedgerEntry::where('posting_group_id', $originalPgId)->get();
        $reversalEntries = LedgerEntry::where('posting_group_id', $reversalPgId)->get();

        $originalDebitTotal = 0;
        $originalCreditTotal = 0;
        foreach ($originalEntries as $entry) {
            $originalDebitTotal += (float) $entry->debit_amount;
            $originalCreditTotal += (float) $entry->credit_amount;
        }

        $reversalDebitTotal = 0;
        $reversalCreditTotal = 0;
        foreach ($reversalEntries as $entry) {
            $reversalDebitTotal += (float) $entry->debit_amount;
            $reversalCreditTotal += (float) $entry->credit_amount;
        }

        // Combined totals should net to zero
        $netDebit = $originalDebitTotal + $reversalDebitTotal;
        $netCredit = $originalCreditTotal + $reversalCreditTotal;
        $this->assertEqualsWithDelta(0, $netDebit, 0.01);
        $this->assertEqualsWithDelta(0, $netCredit, 0.01);
    }

    public function test_post_without_vendor_uses_accrued_expenses(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);

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
        $job = $this->createDraftJob($tenant, $machine, null); // No vendor party

        // Post job
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/maintenance-jobs/{$job->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => 'job-post-no-vendor',
            ]);
        $post->assertStatus(201);

        $pgId = $post->json('posting_group')['id'];

        // Check that ACCRUED_EXPENSES is used (not AP)
        $entries = LedgerEntry::where('posting_group_id', $pgId)->get();
        $creditEntry = $entries->firstWhere('credit_amount', '>', 0);
        $this->assertNotNull($creditEntry);

        // The credit account should be ACCRUED_EXPENSES
        $accruedExpensesAccount = \App\Models\Account::where('tenant_id', $tenant->id)
            ->where('code', 'ACCRUED_EXPENSES')
            ->first();
        $this->assertNotNull($accruedExpensesAccount);
        $this->assertEquals($accruedExpensesAccount->id, $creditEntry->account_id);
    }
}

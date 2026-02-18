<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\SettlementPack;
use App\Models\SettlementPackApproval;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettlementPackApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function enableSettlements(Tenant $tenant): void
    {
        $m = Module::where('key', 'settlements')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function createTenantWithProjectAndUsers(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Approval Tenant', 'status' => 'active']);
        $this->enableSettlements($tenant);
        SystemAccountsSeeder::runForTenant($tenant->id);

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

        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'EXP_SHARED')->first();
        $this->assertNotNull($bank);
        $this->assertNotNull($revenue);
        $this->assertNotNull($expense);

        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'approval-test-' . \Illuminate\Support\Str::uuid(),
        ]);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0, 'currency_code' => 'GBP']);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 500, 'currency_code' => 'GBP']);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 1,
        ]);

        $userAdmin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin-approval@test.' . $tenant->id,
            'password' => Hash::make('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $userAccountant = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Accountant',
            'email' => 'accountant-approval@test.' . $tenant->id,
            'password' => Hash::make('password'),
            'role' => 'accountant',
            'is_enabled' => true,
        ]);

        return ['tenant' => $tenant, 'project' => $project, 'party' => $party, 'cycle' => $cycle, 'user_admin' => $userAdmin, 'user_accountant' => $userAccountant];
    }

    public function test_submit_for_approval_creates_approval_rows(): void
    {
        $data = $this->createTenantWithProjectAndUsers();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');
        $this->assertSame('DRAFT', $createRes->json('status'));

        $submitRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/submit-for-approval");
        $submitRes->assertStatus(200);
        $this->assertSame('PENDING_APPROVAL', $submitRes->json('status'));
        $approvals = $submitRes->json('approvals');
        $this->assertCount(2, $approvals);
        foreach ($approvals as $a) {
            $this->assertSame('PENDING', $a['status']);
            $this->assertContains($a['approver_role'], ['tenant_admin', 'accountant']);
        }

        $this->assertCount(2, SettlementPackApproval::where('settlement_pack_id', $packId)->where('tenant_id', $tenant->id)->get());
    }

    public function test_approval_flow_finalizes_on_last_approval(): void
    {
        $data = $this->createTenantWithProjectAndUsers();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/submit-for-approval")
            ->assertStatus(200);

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $data['user_admin']->id]);
        $r1->assertStatus(200);
        $this->assertSame('PENDING_APPROVAL', $r1->json('status'));

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $data['user_accountant']->id]);
        $r2->assertStatus(200);
        $this->assertSame('FINAL', $r2->json('status'));
        $this->assertNotNull($r2->json('finalized_at'));

        $project->refresh();
        $this->assertSame('CLOSED', $project->status);
    }

    public function test_snapshot_tampering_blocks_approval(): void
    {
        $data = $this->createTenantWithProjectAndUsers();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/submit-for-approval")
            ->assertStatus(200);

        $pack = SettlementPack::where('id', $packId)->where('tenant_id', $tenant->id)->first();
        $summary = $pack->summary_json ?? [];
        $summary['tampered'] = true;
        $pack->update(['summary_json' => $summary]);

        $approveRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $data['user_admin']->id]);
        $approveRes->assertStatus(422);
        $approveRes->assertJsonPath('errors.snapshot.0', 'Pack snapshot has changed since submission; approval rejected for integrity.');
    }

    public function test_reject_keeps_pack_pending(): void
    {
        $data = $this->createTenantWithProjectAndUsers();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/submit-for-approval")
            ->assertStatus(200);

        $rejectRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/settlement-packs/{$packId}/reject", [
                'approver_user_id' => $data['user_admin']->id,
                'comment' => 'Need to review',
            ]);
        $rejectRes->assertStatus(200);
        $this->assertSame('PENDING_APPROVAL', $rejectRes->json('status'));
        $approvals = $rejectRes->json('approvals');
        $adminApproval = collect($approvals)->firstWhere('approver_user_id', $data['user_admin']->id);
        $this->assertSame('REJECTED', $adminApproval['status']);
        $this->assertNotNull($adminApproval['rejected_at']);

        $project->refresh();
        $this->assertSame('ACTIVE', $project->status);
    }

    public function test_tenant_isolation_approval(): void
    {
        $data = $this->createTenantWithProjectAndUsers();
        $tenant1 = $data['tenant'];
        $project1 = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project1->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');
        $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/submit-for-approval")
            ->assertStatus(200);

        $tenant2 = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);
        $this->enableSettlements($tenant2);
        $user2 = User::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Other',
            'email' => 'other@test.' . $tenant2->id,
            'password' => Hash::make('password'),
            'role' => 'accountant',
            'is_enabled' => true,
        ]);

        $approveOther = $this->withHeader('X-Tenant-Id', $tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $user2->id]);
        $approveOther->assertStatus(404);
    }

    public function test_cannot_approve_twice(): void
    {
        $data = $this->createTenantWithProjectAndUsers();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/submit-for-approval")
            ->assertStatus(200);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $data['user_admin']->id])
            ->assertStatus(200);

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $data['user_admin']->id]);
        $r2->assertStatus(422);
        $r2->assertJsonPath('errors.approval.0', 'You have already recorded a decision for this pack.');
    }

    public function test_cannot_finalize_without_full_approvals(): void
    {
        $data = $this->createTenantWithProjectAndUsers();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/submit-for-approval")
            ->assertStatus(200);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $data['user_admin']->id])
            ->assertStatus(200);

        $finalizeRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/finalize");
        $finalizeRes->assertStatus(422);
        $finalizeRes->assertJsonPath('errors.status.0', 'All required approvers must approve before finalization.');
    }
}

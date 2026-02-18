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
use App\Models\SettlementPackDocument;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use Database\Seeders\ModulesSeeder;
use Illuminate\Support\Facades\Hash;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Settlement Pack v3: PDF bundle export, versioning, hash, tenant isolation.
 */
class SettlementPackV3ExportTest extends TestCase
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

    /** Tenant + project + ledger postings (same pattern as SettlementPackV2Test). */
    private function createTenantWithProjectAndLedgerPostings(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Pack V3 Tenant', 'status' => 'active']);
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
            'idempotency_key' => 'v3-test-' . \Illuminate\Support\Str::uuid(),
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

        $pg2 = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'posting_date' => '2024-06-20',
            'idempotency_key' => 'v3-test-2-' . \Illuminate\Support\Str::uuid(),
        ]);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pg2->id, 'account_id' => $expense->id, 'debit_amount' => 200, 'credit_amount' => 0, 'currency_code' => 'GBP']);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pg2->id, 'account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => 200, 'currency_code' => 'GBP']);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg2->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 1,
        ]);

        $userAdmin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin-v3@test.' . $tenant->id,
            'password' => Hash::make('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $userAccountant = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Accountant',
            'email' => 'accountant-v3@test.' . $tenant->id,
            'password' => Hash::make('password'),
            'role' => 'accountant',
            'is_enabled' => true,
        ]);

        return ['tenant' => $tenant, 'project' => $project, 'party' => $party, 'cycle' => $cycle, 'user_admin' => $userAdmin, 'user_accountant' => $userAccountant];
    }

    private function submitAndApproveAll(string $tenantId, string $packId, array $data): void
    {
        $this->withHeader('X-Tenant-Id', $tenantId)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/submit-for-approval")
            ->assertStatus(200);
        $this->withHeader('X-Tenant-Id', $tenantId)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $data['user_admin']->id])
            ->assertStatus(200);
        $this->withHeader('X-Tenant-Id', $tenantId)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $data['user_accountant']->id])
            ->assertStatus(200);
    }

    private function createFinalizedPack(): array
    {
        $data = $this->createTenantWithProjectAndLedgerPostings();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');

        $this->submitAndApproveAll($tenant->id, $packId, $data);

        return ['tenant' => $tenant, 'project' => $project, 'pack_id' => $packId, 'data' => $data];
    }

    public function test_export_generates_document_and_file(): void
    {
        Storage::fake('local');
        $base = $this->createFinalizedPack();
        $tenant = $base['tenant'];
        $packId = $base['pack_id'];

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/export/pdf");

        $response->assertStatus(201);
        $response->assertJsonPath('pack_id', $packId);
        $response->assertJsonPath('version', 1);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('sha256_hex'));
        $this->assertNotNull($response->json('generated_at'));

        $doc = SettlementPackDocument::where('tenant_id', $tenant->id)
            ->where('settlement_pack_id', $packId)
            ->where('version', 1)
            ->first();
        $this->assertNotNull($doc);
        $this->assertSame(64, strlen($doc->sha256_hex));
        $this->assertNotNull($doc->storage_key);
        $this->assertGreaterThan(0, $doc->file_size_bytes);

        Storage::disk('local')->assertExists($doc->storage_key);
    }

    public function test_version_increments(): void
    {
        Storage::fake('local');
        $base = $this->createFinalizedPack();
        $tenant = $base['tenant'];
        $packId = $base['pack_id'];

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/export/pdf");
        $r1->assertStatus(201);
        $hash1 = $r1->json('sha256_hex');
        $this->assertSame(1, $r1->json('version'));

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/export/pdf");
        $r2->assertStatus(201);
        $hash2 = $r2->json('sha256_hex');
        $this->assertSame(2, $r2->json('version'));

        $this->assertNotSame($hash1, $hash2);

        $doc1 = SettlementPackDocument::where('tenant_id', $tenant->id)->where('settlement_pack_id', $packId)->where('version', 1)->first();
        $doc2 = SettlementPackDocument::where('tenant_id', $tenant->id)->where('settlement_pack_id', $packId)->where('version', 2)->first();
        $this->assertNotNull($doc1);
        $this->assertNotNull($doc2);
        $this->assertNotSame($doc1->storage_key, $doc2->storage_key);
    }

    public function test_tenant_isolation(): void
    {
        Storage::fake('local');
        $base = $this->createFinalizedPack();
        $tenant1 = $base['tenant'];
        $packId = $base['pack_id'];

        $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/export/pdf")
            ->assertStatus(201);

        $tenant2 = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);
        $this->enableSettlements($tenant2);

        $listOther = $this->withHeader('X-Tenant-Id', $tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/settlement-packs/{$packId}/documents");
        $listOther->assertStatus(404);

        $downloadOther = $this->withHeader('X-Tenant-Id', $tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get("/api/settlement-packs/{$packId}/documents/1");
        $downloadOther->assertStatus(404);
    }

    public function test_export_on_draft_returns_422(): void
    {
        $data = $this->createTenantWithProjectAndLedgerPostings();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');
        $this->assertSame('DRAFT', $createRes->json('status'));

        $exportRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/export/pdf");
        $exportRes->assertStatus(422);
        $exportRes->assertJsonPath('errors.status.0', 'PDF export is only allowed for finalized settlement packs.');
    }

    public function test_invalid_version_returns_404(): void
    {
        Storage::fake('local');
        $base = $this->createFinalizedPack();
        $tenant = $base['tenant'];
        $packId = $base['pack_id'];

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/settlement-packs/{$packId}/documents/99")
            ->assertStatus(404);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get("/api/settlement-packs/{$packId}/documents/99")
            ->assertStatus(404);
    }

    public function test_list_documents_and_download_after_export(): void
    {
        Storage::fake('local');
        $base = $this->createFinalizedPack();
        $tenant = $base['tenant'];
        $packId = $base['pack_id'];

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/export/pdf")
            ->assertStatus(201);

        $list = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/settlement-packs/{$packId}/documents");
        $list->assertStatus(200);
        $list->assertJsonPath('documents.0.version', 1);
        $list->assertJsonPath('documents.0.sha256_hex', fn ($v) => strlen($v) === 64);

        $download = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get("/api/settlement-packs/{$packId}/documents/1");
        $download->assertStatus(200);
        $download->assertHeader('Content-Type', 'application/pdf');
    }
}

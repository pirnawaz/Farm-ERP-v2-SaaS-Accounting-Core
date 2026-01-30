<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Settlement;
use App\Models\PostingGroup;
use App\Models\InvUom;
use App\Models\InvItemCategory;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvIssue;
use App\Models\InvIssueLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\OperationalTransaction;
use App\Services\TenantContext;
use App\Services\InventoryPostingService;
use App\Services\SaleCOGSService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class CropCycleCloseTest extends TestCase
{
    use RefreshDatabase;

    private function enableProjectsCropCycles(Tenant $tenant): void
    {
        $m = Module::where('key', 'projects_crop_cycles')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function enableInventory(Tenant $tenant): void
    {
        $m = Module::where('key', 'inventory')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

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

    public function test_close_preview_returns_expected_shape(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableProjectsCropCycles($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/crop-cycles/{$cycle->id}/close-preview");

        $r->assertStatus(200);
        $data = $r->json();
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('has_posted_settlement', $data);
        $this->assertArrayHasKey('reconciliation_summary', $data);
        $this->assertArrayHasKey('blocking_reasons', $data);
        $this->assertArrayHasKey('reconciliation', $data);
        $this->assertIsArray($data['blocking_reasons']);
        $this->assertSame('OPEN', $data['status']);
        $this->assertFalse($data['has_posted_settlement']);
        $this->assertContains('At least one POSTED settlement is required for this crop cycle.', $data['blocking_reasons']);

        $recon = $data['reconciliation'];
        $this->assertArrayHasKey('from', $recon);
        $this->assertArrayHasKey('to', $recon);
        $this->assertArrayHasKey('counts', $recon);
        $this->assertArrayHasKey('pass', $recon['counts']);
        $this->assertArrayHasKey('warn', $recon['counts']);
        $this->assertArrayHasKey('fail', $recon['counts']);
        $this->assertArrayHasKey('checks', $recon);
        $this->assertIsArray($recon['checks']);
        foreach ($recon['checks'] as $check) {
            $this->assertArrayHasKey('key', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('summary', $check);
            $this->assertArrayHasKey('title', $check);
        }
    }

    public function test_cannot_close_without_posted_settlement(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableProjectsCropCycles($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/crop-cycles/{$cycle->id}/close", ['note' => 'Test']);

        $r->assertStatus(422);
        $this->assertStringContainsString('settlement', strtolower($r->json('message') ?? ''));
    }

    public function test_close_blocked_when_reconciliation_has_fail(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableProjectsCropCycles($tenant);
        $this->enableInventory($tenant);
        $this->enableModule($tenant, 'ar_sales');

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['LANDLORD']]);
        $buyerParty = Party::create(['tenant_id' => $tenant->id, 'name' => 'Buyer', 'party_types' => ['BUYER']]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Proj',
            'status' => 'CLOSED',
        ]);
        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 60,
            'profit_split_hari_pct' => 40,
            'kamdari_pct' => 0,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fert']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fert',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'type' => 'MAIN', 'is_active' => true]);
        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $item->id,
            'qty' => 100,
            'unit_cost' => 10,
            'line_total' => 1000,
        ]);
        app(InventoryPostingService::class)->postGRN($grn->id, $tenant->id, '2024-06-01', 'grn-1');

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $buyerParty->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cycle->id,
            'amount' => 2000.00,
            'posting_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);
        SaleLine::create([
            'tenant_id' => $tenant->id,
            'sale_id' => $sale->id,
            'inventory_item_id' => $item->id,
            'store_id' => $store->id,
            'quantity' => 50,
            'unit_price' => 40.00,
            'line_total' => 2000.00,
        ]);
        $sale->refresh();
        app(SaleCOGSService::class)->postSaleWithCOGS($sale, '2024-06-15', 'close-fail-sale-1');

        $settlement = Settlement::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'project_id' => $project->id,
            'status' => 'DRAFT',
            'pool_revenue' => 0,
            'shared_costs' => 0,
            'pool_profit' => 0,
            'kamdari_amount' => 0,
            'landlord_share' => 0,
            'hari_share' => 0,
            'hari_only_deductions' => 0,
        ]);
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'SETTLEMENT',
            'source_id' => $settlement->id,
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'close-fail-settle-1',
        ]);
        $settlement->update([
            'posting_group_id' => $pg->id,
            'status' => 'POSTED',
            'posting_date' => '2024-06-15',
            'posted_at' => now(),
        ]);

        $ot = OperationalTransaction::where('tenant_id', $tenant->id)
            ->where('project_id', $project->id)
            ->where('type', 'INCOME')
            ->first();
        $this->assertNotNull($ot, 'OT should exist after posting sale');
        $ot->update(['amount' => $ot->amount + 100]);

        $preview = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/crop-cycles/{$cycle->id}/close-preview");
        $preview->assertStatus(200);
        $previewData = $preview->json();
        $failCount = $previewData['reconciliation']['counts']['fail'] ?? $previewData['reconciliation_summary']['fail'] ?? 0;
        $this->assertGreaterThan(0, $failCount, 'Preview should show at least one reconciliation FAIL');

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/crop-cycles/{$cycle->id}/close", ['note' => 'Should be blocked']);
        $r->assertStatus(422);
        $msg = $r->json('message') ?? '';
        $this->assertStringContainsString('Reconciliation', $msg);
        $this->assertTrue(
            str_contains(strtolower($msg), 'failure') || str_contains(strtolower($msg), 'failures'),
            'Message should mention reconciliation failure: ' . $msg
        );
    }

    public function test_close_sets_status_and_audit_fields(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableProjectsCropCycles($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['LANDLORD']]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Proj',
            'status' => 'CLOSED',
        ]);
        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 60,
            'profit_split_hari_pct' => 40,
            'kamdari_pct' => 0,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        $settlement = Settlement::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'project_id' => $project->id,
            'status' => 'DRAFT',
            'pool_revenue' => 0,
            'shared_costs' => 0,
            'pool_profit' => 0,
            'kamdari_amount' => 0,
            'landlord_share' => 0,
            'hari_share' => 0,
            'hari_only_deductions' => 0,
        ]);
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'SETTLEMENT',
            'source_id' => $settlement->id,
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'settle-1',
        ]);
        $settlement->update([
            'posting_group_id' => $pg->id,
            'status' => 'POSTED',
            'posting_date' => '2024-06-15',
            'posted_at' => now(),
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/crop-cycles/{$cycle->id}/close", ['note' => 'Final close']);

        $r->assertStatus(200);
        $data = $r->json();
        $this->assertSame('CLOSED', $data['status']);
        $this->assertNotNull($data['closed_at']);
        $this->assertSame('Final close', $data['close_note'] ?? null);

        $cycle->refresh();
        $this->assertSame('CLOSED', $cycle->status);
        $this->assertNotNull($cycle->closed_at);
        $this->assertSame('Final close', $cycle->close_note);
    }

    public function test_posting_to_closed_cycle_is_rejected(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableProjectsCropCycles($tenant);
        $this->enableInventory($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'CLOSED',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Hari', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Proj',
            'status' => 'ACTIVE',
        ]);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fert']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fert',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'type' => 'MAIN', 'is_active' => true]);

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $item->id,
            'qty' => 10,
            'unit_cost' => 50,
            'line_total' => 500,
        ]);
        app(InventoryPostingService::class)->postGRN($grn->id, $tenant->id, '2024-06-01', 'grn-1');

        $issue = InvIssue::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'ISS-1',
            'store_id' => $store->id,
            'crop_cycle_id' => $cycle->id,
            'project_id' => $project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'HARI_ONLY',
            'hari_id' => $party->id,
        ]);
        InvIssueLine::create([
            'tenant_id' => $tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $item->id,
            'qty' => 2,
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/issues/{$issue->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'issue-closed',
            ]);

        $r->assertStatus(422);
        $msg = $r->json('message') ?? '';
        $this->assertStringContainsString('CLOSED', $msg);
        $this->assertStringContainsString('Reopen', $msg);
    }

    public function test_reopen_restores_ability_to_post(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableProjectsCropCycles($tenant);
        $this->enableInventory($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'CLOSED',
            'closed_at' => now(),
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Hari', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Proj',
            'status' => 'ACTIVE',
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/crop-cycles/{$cycle->id}/reopen");

        $r->assertStatus(200);
        $this->assertSame('OPEN', $r->json('status'));

        $cycle->refresh();
        $this->assertSame('OPEN', $cycle->status);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fert']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fert',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'type' => 'MAIN', 'is_active' => true]);
        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $item->id,
            'qty' => 10,
            'unit_cost' => 50,
            'line_total' => 500,
        ]);
        app(InventoryPostingService::class)->postGRN($grn->id, $tenant->id, '2024-06-01', 'grn-reopen');

        $issue = InvIssue::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'ISS-1',
            'store_id' => $store->id,
            'crop_cycle_id' => $cycle->id,
            'project_id' => $project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'HARI_ONLY',
            'hari_id' => $party->id,
        ]);
        InvIssueLine::create([
            'tenant_id' => $tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $item->id,
            'qty' => 2,
        ]);

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/issues/{$issue->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'issue-after-reopen',
            ]);

        $post->assertStatus(201);
    }
}

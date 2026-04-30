<?php

namespace Tests\Feature;

use App\Domains\Accounting\Loans\LoanDrawdown;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\Project;
use App\Models\ProjectPlan;
use App\Models\PostingGroup;
use App\Models\PurchaseOrder;
use App\Models\SettlementPack;
use App\Models\SupplierPaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\MakesAuthenticatedRequests;

class DemoTenantSeederTest extends TestCase
{
    use RefreshDatabase;
    use MakesAuthenticatedRequests;

    public function test_demo_seed_creates_tenant_and_is_idempotent(): void
    {
        $code = Artisan::call('demo:seed-tenant', [
            '--tenant-name' => 'Test Demo Farm',
            '--tenant-slug' => 'test-demo-seed',
        ]);
        $this->assertSame(0, $code);

        $this->assertSame(1, Tenant::where('slug', 'test-demo-seed')->count());
        $this->assertSame(1, User::where('email', 'demo.admin@terrava.local')->count());

        $code2 = Artisan::call('demo:seed-tenant', [
            '--tenant-name' => 'Test Demo Farm',
            '--tenant-slug' => 'test-demo-seed',
        ]);
        $this->assertSame(0, $code2);

        $this->assertSame(1, Tenant::where('slug', 'test-demo-seed')->count());
        $this->assertSame(1, User::where('email', 'demo.admin@terrava.local')->count());
    }

    public function test_tenant_admin_unified_login_and_reports_return_data(): void
    {
        Artisan::call('demo:seed-tenant', [
            '--tenant-name' => 'Report Demo Farm',
            '--tenant-slug' => 'report-demo',
        ]);

        $tenant = Tenant::where('slug', 'report-demo')->firstOrFail();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'demo.admin@terrava.local',
            'password' => 'Demo@12345',
        ]);
        $login->assertStatus(200);
        $login->assertJsonPath('mode', 'tenant');

        $tb = $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/reports/trial-balance?as_of=2026-12-31');
        $tb->assertStatus(200);
        $payload = $tb->json();
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload);

        $pl = $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/reports/profit-loss?from=2026-01-01&to=2026-12-31');
        $pl->assertStatus(200);
        $this->assertNotEmpty($pl->json());
    }

    public function test_posted_groups_are_balanced_for_demo_tenant(): void
    {
        Artisan::call('demo:seed-tenant', [
            '--tenant-name' => 'Balance Demo Farm',
            '--tenant-slug' => 'balance-demo',
        ]);

        $tenant = Tenant::where('slug', 'balance-demo')->firstOrFail();

        $pgs = PostingGroup::where('tenant_id', $tenant->id)->get();
        $this->assertNotEmpty($pgs);

        foreach ($pgs as $pg) {
            $debit = (float) LedgerEntry::where('posting_group_id', $pg->id)->sum('debit_amount');
            $credit = (float) LedgerEntry::where('posting_group_id', $pg->id)->sum('credit_amount');
            $this->assertEqualsWithDelta($debit, $credit, 0.02, 'Posting group ' . $pg->id . ' should balance');
        }
    }

    public function test_demo_seed_command_prints_coverage_matrices(): void
    {
        $code = Artisan::call('demo:seed-tenant', ['--tenant-slug' => 'matrix-cli-demo']);
        $this->assertSame(0, $code);
        $out = Artisan::output();
        $this->assertStringContainsString('MODULE COVERAGE MATRIX', $out);
        $this->assertStringContainsString('REPORT COVERAGE MATRIX', $out);
        $this->assertStringContainsString('ROLE JOURNEY', $out);
    }

    public function test_demo_seed_creates_loan_drawdown_and_settlement_pack(): void
    {
        Artisan::call('demo:seed-tenant', ['--tenant-slug' => 'pack-loan-demo']);

        $tenant = Tenant::where('slug', 'pack-loan-demo')->firstOrFail();
        $this->assertGreaterThan(0, LoanDrawdown::where('tenant_id', $tenant->id)->where('status', LoanDrawdown::STATUS_POSTED)->count());
        $this->assertGreaterThan(0, SettlementPack::where('tenant_id', $tenant->id)->count());
    }

    public function test_demo_seed_includes_canonical_procurement_ap_and_reports(): void
    {
        Artisan::call('demo:seed-tenant', ['--tenant-name' => 'Canonical Demo', '--tenant-slug' => 'canonical-demo']);
        Artisan::call('demo:seed-tenant', ['--tenant-name' => 'Canonical Demo', '--tenant-slug' => 'canonical-demo']); // idempotency

        $tenant = Tenant::where('slug', 'canonical-demo')->firstOrFail();

        $this->assertGreaterThanOrEqual(1, ProjectPlan::where('tenant_id', $tenant->id)->where('status', ProjectPlan::STATUS_ACTIVE)->count());
        $this->assertGreaterThanOrEqual(1, PurchaseOrder::where('tenant_id', $tenant->id)->where('status', PurchaseOrder::STATUS_APPROVED)->count());

        $this->assertGreaterThanOrEqual(1, SupplierInvoice::where('tenant_id', $tenant->id)
            ->where('payment_terms', 'CREDIT')
            ->whereIn('status', [SupplierInvoice::STATUS_POSTED, SupplierInvoice::STATUS_PAID])
            ->count());

        $this->assertGreaterThanOrEqual(1, AllocationRow::where('tenant_id', $tenant->id)
            ->where('allocation_type', 'SUPPLIER_INVOICE_CREDIT_PREMIUM')
            ->count());

        $this->assertGreaterThanOrEqual(1, SupplierPaymentAllocation::where('tenant_id', $tenant->id)->count());

        // Deprecated paths must not be used by demo seed.
        $this->assertSame(0, DB::table('supplier_bills')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, DB::table('supplier_payments')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, DB::table('supplier_bill_line_matches')->where('tenant_id', $tenant->id)->count());

        $alpha = Project::where('tenant_id', $tenant->id)->where('name', 'Demo Project Alpha')->firstOrFail();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'demo.admin@terrava.local',
            'password' => 'Demo@12345',
        ]);
        $login->assertStatus(200);

        $bva = $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/reports/budget-vs-actual/project?project_id=' . $alpha->id . '&from=2026-01-01&to=2026-12-31&bucket=month');
        $bva->assertStatus(200);
        $this->assertNotNull($bva->json('totals.planned.planned_total_cost'));
        $this->assertNotNull($bva->json('totals.actual.actual_total_cost'));
        $this->assertNotNull($bva->json('totals.actual.actual_credit_premium_cost'));

        $settle = $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/reports/settlement-pack/project?project_id=' . $alpha->id . '&from=2026-01-01&to=2026-12-31');
        $settle->assertStatus(200);
        $this->assertNotNull($settle->json('summary.costs.credit_premium'));
    }

    public function test_operator_cannot_access_settlement_packs(): void
    {
        Artisan::call('demo:seed-tenant', ['--tenant-slug' => 'role-op-demo']);

        $tenant = Tenant::where('slug', 'role-op-demo')->firstOrFail();
        $login = $this->postJson('/api/auth/login', [
            'email' => 'demo.operator@terrava.local',
            'password' => 'Demo@12345',
        ]);
        $login->assertStatus(200);

        $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/settlement-packs')
            ->assertStatus(403);
    }

    public function test_accountant_can_list_settlement_packs(): void
    {
        Artisan::call('demo:seed-tenant', ['--tenant-slug' => 'role-acc-demo']);

        $tenant = Tenant::where('slug', 'role-acc-demo')->firstOrFail();
        $login = $this->postJson('/api/auth/login', [
            'email' => 'demo.accountant@terrava.local',
            'password' => 'Demo@12345',
        ]);
        $login->assertStatus(200);

        $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/settlement-packs')
            ->assertStatus(200);
    }
}

<?php

namespace Tests\Feature;

use App\Domains\Accounting\Loans\LoanAgreement;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Models\CropCycle;
use App\Models\Module;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use Database\Seeders\ModulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1H.5: tenant A cannot load tenant B resources by ID (settlement packs, loans, AP, payments).
 */
class TenantIsolationPhase1H5Test extends TestCase
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

    /** @return array{tenantA: Tenant, tenantB: Tenant, projectB: Project} */
    private function twoTenantsWithProjectB(): array
    {
        (new ModulesSeeder)->run();
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active', 'currency_code' => 'GBP']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active', 'currency_code' => 'GBP']);
        foreach (['settlements', 'loans', 'treasury_payments'] as $key) {
            $this->enableModule($tenantA, $key);
            $this->enableModule($tenantB, $key);
        }

        $cycle = CropCycle::create([
            'tenant_id' => $tenantB->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Party B',
            'party_types' => ['LANDLORD'],
        ]);
        $projectB = Project::create([
            'tenant_id' => $tenantB->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Project B',
            'status' => 'ACTIVE',
        ]);

        return ['tenantA' => $tenantA, 'tenantB' => $tenantB, 'projectB' => $projectB];
    }

    public function test_tenant_a_cannot_read_tenant_b_settlement_pack(): void
    {
        $x = $this->twoTenantsWithProjectB();
        $tenantA = $x['tenantA'];
        $tenantB = $x['tenantB'];
        $projectB = $x['projectB'];

        $gen = $this->withHeader('X-Tenant-Id', $tenantB->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$projectB->id}/settlement-pack", []);
        $gen->assertStatus(201);
        $packId = $gen->json('id');

        $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/settlement-packs/{$packId}")
            ->assertStatus(404);
    }

    public function test_tenant_a_cannot_read_tenant_b_loan_agreement(): void
    {
        $x = $this->twoTenantsWithProjectB();
        $tenantA = $x['tenantA'];
        $tenantB = $x['tenantB'];
        $projectB = $x['projectB'];

        $party = Party::where('tenant_id', $tenantB->id)->firstOrFail();
        $agreement = LoanAgreement::create([
            'tenant_id' => $tenantB->id,
            'project_id' => $projectB->id,
            'lender_party_id' => $party->id,
            'reference_no' => 'LA-B',
            'principal_amount' => 1000,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);

        $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/loan-agreements/{$agreement->id}")
            ->assertStatus(404);
    }

    public function test_tenant_a_cannot_read_tenant_b_supplier_invoice(): void
    {
        $x = $this->twoTenantsWithProjectB();
        $tenantA = $x['tenantA'];
        $tenantB = $x['tenantB'];
        $projectB = $x['projectB'];

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
    }

    public function test_tenant_a_cannot_read_tenant_b_payment(): void
    {
        $x = $this->twoTenantsWithProjectB();
        $tenantA = $x['tenantA'];
        $tenantB = $x['tenantB'];
        $projectB = $x['projectB'];

        $party = Party::where('tenant_id', $tenantB->id)->where('name', 'Party B')->firstOrFail();
        $payment = Payment::create([
            'tenant_id' => $tenantB->id,
            'party_id' => $party->id,
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

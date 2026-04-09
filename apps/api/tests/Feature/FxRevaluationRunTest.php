<?php

namespace Tests\Feature;

use App\Domains\Accounting\MultiCurrency\ExchangeRate;
use App\Domains\Accounting\MultiCurrency\FxRevaluationLine;
use App\Domains\Accounting\MultiCurrency\FxRevaluationRun;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FxRevaluationRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_run_has_lines_and_post_creates_one_balanced_posting_group(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'FX Rev', 'status' => 'active', 'currency_code' => 'USD']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2024-06-01',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => '1.25000000',
            'source' => 'test',
        ]);
        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2024-06-30',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => '1.35000000',
            'source' => 'test',
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'EU Vendor',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field FX',
            'status' => 'ACTIVE',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'INV-FXR-1',
            'invoice_date' => '2024-06-10',
            'currency_code' => 'EUR',
            'subtotal_amount' => 80,
            'tax_amount' => 0,
            'total_amount' => 80,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Service',
            'item_id' => null,
            'line_total' => 80,
            'tax_amount' => 0,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'si-fxr-1',
            ])
            ->assertStatus(201);

        $store = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fx-revaluation-runs', ['as_of_date' => '2024-06-30']);

        $store->assertStatus(201);
        $runId = $store->json('id');
        $this->assertNotEmpty($runId);

        $line = FxRevaluationLine::query()
            ->where('fx_revaluation_run_id', $runId)
            ->where('source_type', FxRevaluationLine::SOURCE_SUPPLIER_AP)
            ->first();
        $this->assertNotNull($line);
        $this->assertSame($supplier->id, $line->source_id);
        $this->assertEqualsWithDelta(100.0, (float) $line->original_base_amount, 0.02);
        $this->assertEqualsWithDelta(108.0, (float) $line->revalued_base_amount, 0.02);
        $this->assertEqualsWithDelta(8.0, (float) $line->delta_amount, 0.02);

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fx-revaluation-runs/{$runId}/post", [
                'posting_date' => '2024-06-30',
                'idempotency_key' => 'fxr-post-1',
            ]);
        $post->assertStatus(201);
        $pgId = $post->json('id');
        $this->assertNotEmpty($pgId);

        $this->assertSame('FX_REVALUATION_RUN', PostingGroup::findOrFail($pgId)->source_type);
        $this->assertSame($runId, PostingGroup::findOrFail($pgId)->source_id);

        $sumDr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount');
        $sumCr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount');
        $this->assertEqualsWithDelta($sumDr, $sumCr, 0.02);
        $this->assertEqualsWithDelta(8.0, $sumDr, 0.02);

        $run = FxRevaluationRun::findOrFail($runId);
        $this->assertSame(FxRevaluationRun::STATUS_POSTED, $run->status);
        $this->assertEquals($pgId, $run->posting_group_id);
    }

    public function test_post_is_idempotent_for_same_run(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'FX Idem', 'status' => 'active', 'currency_code' => 'USD']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2024-06-01',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => '1.25000000',
            'source' => 'test',
        ]);
        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2024-06-30',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => '1.35000000',
            'source' => 'test',
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'P',
            'status' => 'ACTIVE',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'currency_code' => 'EUR',
            'subtotal_amount' => 80,
            'tax_amount' => 0,
            'total_amount' => 80,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 80,
            'tax_amount' => 0,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'si-fxr-idem',
            ])
            ->assertStatus(201);

        $runId = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fx-revaluation-runs', ['as_of_date' => '2024-06-30'])
            ->assertStatus(201)
            ->json('id');

        $payload = ['posting_date' => '2024-06-30', 'idempotency_key' => 'idem-fx-1'];
        $p1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fx-revaluation-runs/{$runId}/post", $payload);
        $p2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fx-revaluation-runs/{$runId}/post", $payload);

        $p1->assertStatus(201);
        $p2->assertStatus(201);
        $this->assertSame($p1->json('id'), $p2->json('id'));
    }

    public function test_refresh_replaces_draft_lines_without_duplicating(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'FX Refresh', 'status' => 'active', 'currency_code' => 'USD']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2024-06-01',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => '1.25000000',
            'source' => 'test',
        ]);
        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2024-06-30',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => '1.35000000',
            'source' => 'test',
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V2',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'P2',
            'status' => 'ACTIVE',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'currency_code' => 'EUR',
            'subtotal_amount' => 80,
            'tax_amount' => 0,
            'total_amount' => 80,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 80,
            'tax_amount' => 0,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'si-fxr-ref',
            ])
            ->assertStatus(201);

        $runId = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/fx-revaluation-runs', ['as_of_date' => '2024-06-30'])
            ->assertStatus(201)
            ->json('id');

        $this->assertSame(1, FxRevaluationLine::where('fx_revaluation_run_id', $runId)->count());

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/fx-revaluation-runs/{$runId}/refresh")
            ->assertStatus(200);

        $this->assertSame(1, FxRevaluationLine::where('fx_revaluation_run_id', $runId)->count());
    }
}

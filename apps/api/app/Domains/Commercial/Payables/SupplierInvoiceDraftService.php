<?php

namespace App\Domains\Commercial\Payables;

use App\Models\CostCenter;
use App\Models\Party;
use App\Models\Project;
use App\Support\TenantScoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft CRUD for supplier invoices (project-linked procurement or cost-center farm bills).
 */
class SupplierInvoiceDraftService
{
    private function normalizeTerms(?string $terms): ?string
    {
        if ($terms === null || trim($terms) === '') {
            return null;
        }
        return strtoupper(trim($terms));
    }

    /**
     * M1: Store-only validation for cash/credit premium fields (posting remains unchanged).
     *
     * - If payment_terms is null: treat as legacy; do not require/validate credit fields.
     * - If CASH: allow credit fields but require premium to be zero when provided.
     * - If CREDIT: require cash_unit_price and credit_unit_price when provided and enforce credit >= cash.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function assertCreditPremiumFields(?string $paymentTerms, array $lines): void
    {
        $terms = $this->normalizeTerms($paymentTerms);
        if ($terms === null) {
            return;
        }
        if (! in_array($terms, ['CASH', 'CREDIT'], true)) {
            throw ValidationException::withMessages([
                'payment_terms' => ['payment_terms must be CASH or CREDIT (or null for legacy invoices).'],
            ]);
        }

        foreach ($lines as $i => $line) {
            $cash = $line['cash_unit_price'] ?? null;
            $credit = $line['credit_unit_price'] ?? null;
            $premium = $line['credit_premium_amount'] ?? null;

            if ($terms === 'CASH') {
                if ($premium !== null && abs((float) $premium) > 0.00001) {
                    throw ValidationException::withMessages([
                        "lines.{$i}.credit_premium_amount" => ['For CASH invoices, credit_premium_amount must be 0 (or null).'],
                    ]);
                }
                continue;
            }

            // CREDIT
            if ($cash === null || $credit === null) {
                // We allow legacy lines to omit these; but if payment_terms is explicitly CREDIT, require both.
                throw ValidationException::withMessages([
                    "lines.{$i}" => ['For CREDIT invoices, cash_unit_price and credit_unit_price are required on each line.'],
                ]);
            }
            if ((float) $credit + 1e-9 < (float) $cash) {
                throw ValidationException::withMessages([
                    "lines.{$i}.credit_unit_price" => ['credit_unit_price must be >= cash_unit_price for CREDIT invoices.'],
                ]);
            }
        }
    }
    public function assertValidBillingScope(?string $grnId, ?string $projectId, ?string $costCenterId): void
    {
        if ($grnId) {
            $hasProject = $projectId !== null && $projectId !== '';
            if (! $hasProject) {
                throw ValidationException::withMessages([
                    'project_id' => ['GRN-linked supplier invoices must include a project.'],
                ]);
            }
            if ($costCenterId !== null && $costCenterId !== '') {
                throw ValidationException::withMessages([
                    'cost_center_id' => ['GRN-linked invoices cannot use a cost center.'],
                ]);
            }

            return;
        }

        $hasProject = $projectId !== null && $projectId !== '';
        $hasCc = $costCenterId !== null && $costCenterId !== '';

        if ($hasProject && $hasCc) {
            throw ValidationException::withMessages([
                'scope' => ['Choose either a project or a cost center for this bill, not both.'],
            ]);
        }

        if (! $hasProject && ! $hasCc) {
            throw ValidationException::withMessages([
                'scope' => ['Select a project (crop-linked costs) or a cost center (farm overhead).'],
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    public function assertLinesMatchScopeAndTotals(string $tenantId, array $lines, bool $isCostCenterBill, float $totalAmount): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => ['At least one line is required.'],
            ]);
        }

        $sum = 0.0;
        foreach ($lines as $i => $line) {
            $lt = (float) ($line['line_total'] ?? 0);
            if ($lt <= 0) {
                throw ValidationException::withMessages([
                    'lines' => ["Line ".($i + 1).' must have a positive line_total.'],
                ]);
            }
            $sum += $lt;
            if ($isCostCenterBill && ! empty($line['item_id'])) {
                throw ValidationException::withMessages([
                    'lines' => ['Farm overhead bills cannot include stock (inventory) lines. Remove item_id from lines.'],
                ]);
            }
            if (! empty($line['item_id'])) {
                TenantScoped::for(\App\Models\InvItem::query(), $tenantId)->findOrFail($line['item_id']);
            }
        }

        if (abs($sum - $totalAmount) > 0.02) {
            throw ValidationException::withMessages([
                'total_amount' => ['Sum of line totals must equal total_amount.'],
            ]);
        }
    }

    public function assertCostCenterSelectable(string $tenantId, string $costCenterId): void
    {
        $cc = TenantScoped::for(CostCenter::query(), $tenantId)->where('id', $costCenterId)->firstOrFail();
        if ($cc->status !== CostCenter::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'cost_center_id' => ['Only active cost centers can be used on new bills.'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $lines
     */
    public function create(string $tenantId, array $data, array $lines, ?string $userId): SupplierInvoice
    {
        $grnId = $data['grn_id'] ?? null;
        $projectId = $data['project_id'] ?? null;
        $costCenterId = $data['cost_center_id'] ?? null;
        $this->assertValidBillingScope($grnId, $projectId, $costCenterId);
        if ($costCenterId) {
            $this->assertCostCenterSelectable($tenantId, $costCenterId);
        }
        if ($projectId) {
            TenantScoped::for(Project::query(), $tenantId)->findOrFail($projectId);
        }
        TenantScoped::for(Party::query(), $tenantId)->findOrFail($data['party_id']);

        $isCc = (bool) $costCenterId && ! $projectId;
        $this->assertLinesMatchScopeAndTotals($tenantId, $lines, $isCc, (float) $data['total_amount']);
        $this->assertCreditPremiumFields($data['payment_terms'] ?? null, $lines);

        return DB::transaction(function () use ($tenantId, $data, $lines, $userId) {
            $invoice = SupplierInvoice::create([
                'tenant_id' => $tenantId,
                'party_id' => $data['party_id'],
                'project_id' => $data['project_id'] ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'grn_id' => $data['grn_id'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'currency_code' => strtoupper((string) ($data['currency_code'] ?? 'GBP')),
                'payment_terms' => $this->normalizeTerms($data['payment_terms'] ?? null),
                'subtotal_amount' => $data['subtotal_amount'] ?? $data['total_amount'],
                'tax_amount' => $data['tax_amount'] ?? 0,
                'total_amount' => $data['total_amount'],
                'status' => SupplierInvoice::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $this->insertLines($tenantId, $invoice, $lines);

            return $invoice->load(['lines', 'party', 'project', 'costCenter']);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $lines
     */
    public function update(SupplierInvoice $invoice, array $data, array $lines): SupplierInvoice
    {
        if ($invoice->status !== SupplierInvoice::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft bills can be edited.'],
            ]);
        }

        $tenantId = $invoice->tenant_id;
        $grnId = array_key_exists('grn_id', $data) ? $data['grn_id'] : $invoice->grn_id;
        $projectId = array_key_exists('project_id', $data) ? $data['project_id'] : $invoice->project_id;
        $costCenterId = array_key_exists('cost_center_id', $data) ? $data['cost_center_id'] : $invoice->cost_center_id;

        $this->assertValidBillingScope($grnId, $projectId, $costCenterId);
        if ($costCenterId) {
            $this->assertCostCenterSelectable($tenantId, $costCenterId);
        }
        if ($projectId) {
            TenantScoped::for(Project::query(), $tenantId)->findOrFail($projectId);
        }
        if (isset($data['party_id'])) {
            TenantScoped::for(Party::query(), $tenantId)->findOrFail($data['party_id']);
        }

        $isCc = (bool) $costCenterId && ! $projectId;
        $total = (float) ($data['total_amount'] ?? $invoice->total_amount);
        $this->assertLinesMatchScopeAndTotals($tenantId, $lines, $isCc, $total);
        $nextTerms = array_key_exists('payment_terms', $data) ? $data['payment_terms'] : $invoice->payment_terms;
        $this->assertCreditPremiumFields($nextTerms, $lines);

        return DB::transaction(function () use ($invoice, $data, $lines, $tenantId) {
            $invoice->update([
                'party_id' => $data['party_id'] ?? $invoice->party_id,
                'project_id' => array_key_exists('project_id', $data) ? $data['project_id'] : $invoice->project_id,
                'cost_center_id' => array_key_exists('cost_center_id', $data) ? $data['cost_center_id'] : $invoice->cost_center_id,
                'grn_id' => array_key_exists('grn_id', $data) ? $data['grn_id'] : $invoice->grn_id,
                'reference_no' => array_key_exists('reference_no', $data) ? $data['reference_no'] : $invoice->reference_no,
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $invoice->due_date,
                'currency_code' => isset($data['currency_code']) ? strtoupper((string) $data['currency_code']) : $invoice->currency_code,
                'payment_terms' => array_key_exists('payment_terms', $data) ? $this->normalizeTerms($data['payment_terms']) : $invoice->payment_terms,
                'subtotal_amount' => $data['subtotal_amount'] ?? $invoice->subtotal_amount,
                'tax_amount' => $data['tax_amount'] ?? $invoice->tax_amount,
                'total_amount' => $data['total_amount'] ?? $invoice->total_amount,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $invoice->notes,
            ]);

            SupplierInvoiceLine::where('supplier_invoice_id', $invoice->id)->delete();
            $this->insertLines($tenantId, $invoice->fresh(), $lines);

            return $invoice->fresh()->load(['lines', 'party', 'project', 'costCenter']);
        });
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function insertLines(string $tenantId, SupplierInvoice $invoice, array $lines): void
    {
        $n = 1;
        foreach ($lines as $line) {
            SupplierInvoiceLine::create([
                'tenant_id' => $tenantId,
                'supplier_invoice_id' => $invoice->id,
                'line_no' => $line['line_no'] ?? $n,
                'description' => $line['description'] ?? null,
                'item_id' => $line['item_id'] ?? null,
                'qty' => $line['qty'] ?? null,
                'unit_price' => $line['unit_price'] ?? null,
                'cash_unit_price' => $line['cash_unit_price'] ?? null,
                'credit_unit_price' => $line['credit_unit_price'] ?? null,
                'selected_unit_price' => $line['selected_unit_price'] ?? null,
                'line_total' => $line['line_total'],
                'tax_amount' => $line['tax_amount'] ?? 0,
                'grn_line_id' => $line['grn_line_id'] ?? null,
                'base_cash_amount' => $line['base_cash_amount'] ?? null,
                'credit_premium_amount' => $line['credit_premium_amount'] ?? null,
            ]);
            $n++;
        }
    }
}

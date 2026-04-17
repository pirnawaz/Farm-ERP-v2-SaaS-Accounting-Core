<?php

namespace App\Domains\Commercial\Payables;

use App\Domains\Accounting\MultiCurrency\PostingFxService;
use App\Models\AllocationRow;
use App\Models\CostCenter;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\Accounting\PostValidationService;
use App\Services\BillPaymentService;
use App\Services\LedgerWriteGuard;
use App\Services\OperationalPostingGuard;
use App\Services\PostingDateGuard;
use App\Services\PostingIdempotencyService;
use App\Services\SystemAccountService;
use App\Support\TenantScoped;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts a supplier credit note: Dr AP (reduce payable), Cr INPUTS_EXPENSE (bounded farm correction).
 * Explicit posting group + allocation row (SUPPLIER_CREDIT). Does not mutate the original bill.
 */
class SupplierCreditNotePostingService
{
    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency,
        private PostingFxService $postingFx,
        private BillPaymentService $billPaymentService
    ) {}

    public function post(
        string $creditNoteId,
        string $tenantId,
        string $postingDate,
        ?string $idempotencyKey = null
    ): PostingGroup {
        return LedgerWriteGuard::scoped(static::class, function () use ($creditNoteId, $tenantId, $postingDate, $idempotencyKey) {
            return DB::transaction(function () use ($creditNoteId, $tenantId, $postingDate, $idempotencyKey) {
                /** @var SupplierCreditNote $note */
                $note = TenantScoped::for(SupplierCreditNote::query(), $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($creditNoteId);

                if ($note->posting_group_id) {
                    $pg = TenantScoped::for(PostingGroup::query(), $tenantId)
                        ->where('id', $note->posting_group_id)
                        ->first();
                    if ($pg) {
                        return $pg->load(['ledgerEntries.account', 'allocationRows']);
                    }
                }

                $resolved = $this->postingIdempotency->resolveOrCreate($tenantId, $idempotencyKey, 'SUPPLIER_CREDIT_NOTE', $note->id);
                if ($resolved['posting_group'] !== null) {
                    $existingByKey = $resolved['posting_group'];
                    if ($note->status !== SupplierCreditNote::STATUS_POSTED || ! $note->posting_group_id) {
                        $note->update([
                            'status' => SupplierCreditNote::STATUS_POSTED,
                            'posting_group_id' => $existingByKey->id,
                            'posted_at' => $note->posted_at ?? now(),
                        ]);
                    }

                    return $existingByKey->load(['ledgerEntries.account', 'allocationRows']);
                }
                $effectiveKey = $resolved['effective_key'];

                if ($note->status !== SupplierCreditNote::STATUS_DRAFT) {
                    throw ValidationException::withMessages([
                        'status' => ['Only draft supplier credit notes can be posted.'],
                    ]);
                }

                $projectId = $note->project_id;
                $costCenterId = $note->cost_center_id;
                $cropCycleId = null;
                $allocationProjectId = null;
                $allocationCostCenterId = null;
                $costCenter = null;

                if ($note->supplier_invoice_id) {
                    $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)->findOrFail($note->supplier_invoice_id);
                    if ($invoice->status !== SupplierInvoice::STATUS_POSTED || ! $invoice->posting_group_id) {
                        throw ValidationException::withMessages([
                            'supplier_invoice_id' => ['Linked bill must be posted before applying a credit note.'],
                        ]);
                    }
                    if ((string) $invoice->party_id !== (string) $note->party_id) {
                        throw ValidationException::withMessages([
                            'party_id' => ['Credit note supplier must match the linked bill.'],
                        ]);
                    }
                    $projectId = $invoice->project_id;
                    $costCenterId = $invoice->cost_center_id;
                }

                $hasProject = (bool) $projectId;
                $hasCostCenter = (bool) $costCenterId;

                if ($hasProject && $hasCostCenter) {
                    throw ValidationException::withMessages([
                        'scope' => ['Choose either a project or a cost center, not both.'],
                    ]);
                }
                if (! $hasProject && ! $hasCostCenter) {
                    throw ValidationException::withMessages([
                        'scope' => ['Post requires a project or an active cost center (or link a posted bill).'],
                    ]);
                }

                if ($hasProject) {
                    $project = TenantScoped::for(Project::query(), $tenantId)->findOrFail($projectId);
                    $cropCycleId = $project->crop_cycle_id;
                    if (! $cropCycleId) {
                        throw ValidationException::withMessages([
                            'project' => ['Project has no crop cycle.'],
                        ]);
                    }
                    $this->operationalPostingGuard->ensureCropCycleOpenForProject($projectId, $tenantId);
                    $allocationProjectId = $projectId;
                } else {
                    $costCenter = TenantScoped::for(CostCenter::query(), $tenantId)->findOrFail($costCenterId);
                    if ($costCenter->status !== CostCenter::STATUS_ACTIVE) {
                        throw ValidationException::withMessages([
                            'cost_center_id' => ['Cannot post to an inactive cost center.'],
                        ]);
                    }
                    $allocationCostCenterId = $costCenter->id;
                }

                $total = round((float) $note->total_amount, 2);
                if ($total <= 0) {
                    throw ValidationException::withMessages([
                        'total_amount' => ['Credit amount must be positive.'],
                    ]);
                }

                if ($note->supplier_invoice_id) {
                    $outstandingBefore = $this->billPaymentService->getSupplierInvoiceOutstandingExcludingCredits(
                        $note->supplier_invoice_id,
                        $tenantId
                    );
                    $existingCredits = $this->billPaymentService->getPostedCreditsLinkedToSupplierInvoice(
                        $note->supplier_invoice_id,
                        $tenantId,
                        $note->id
                    );
                    $maxCredit = max(0, $outstandingBefore - $existingCredits);
                    if ($total - $maxCredit > 0.02) {
                        throw ValidationException::withMessages([
                            'total_amount' => ['Credit exceeds remaining payable on the linked bill (after payments and other posted credits).'],
                        ]);
                    }
                }

                if ($note->inv_grn_id) {
                    $grn = TenantScoped::for(\App\Models\InvGrn::query(), $tenantId)->findOrFail($note->inv_grn_id);
                    if ($grn->supplier_party_id && (string) $grn->supplier_party_id !== (string) $note->party_id) {
                        throw ValidationException::withMessages([
                            'inv_grn_id' => ['GRN supplier must match the credit note supplier.'],
                        ]);
                    }
                }

                $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateObj));

                $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
                $currencyCode = strtoupper((string) ($note->currency_code ?: ($tenant->currency_code ?? 'GBP')));
                $fx = $this->postingFx->forPosting($tenantId, $postingDateObj, $currencyCode);

                $apAccount = $this->accountService->getByCode($tenantId, 'AP');
                $expenseAccount = $this->accountService->getByCode($tenantId, 'INPUTS_EXPENSE');

                $ledgerLines = [
                    [
                        'account_id' => $apAccount->id,
                        'debit_amount' => $total,
                        'credit_amount' => 0,
                    ],
                    [
                        'account_id' => $expenseAccount->id,
                        'debit_amount' => 0,
                        'credit_amount' => $total,
                    ],
                ];

                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $cropCycleId,
                    'source_type' => 'SUPPLIER_CREDIT_NOTE',
                    'source_id' => $note->id,
                    'posting_date' => $postingDateObj,
                    'idempotency_key' => $effectiveKey,
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                ]);

                foreach ($ledgerLines as $row) {
                    $dr = (float) $row['debit_amount'];
                    $cr = (float) $row['credit_amount'];
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $postingGroup->id,
                        'account_id' => $row['account_id'],
                        'debit_amount' => $dr,
                        'credit_amount' => $cr,
                        'currency_code' => $fx->transactionCurrencyCode,
                        'base_currency_code' => $fx->baseCurrencyCode,
                        'fx_rate' => $fx->fxRate,
                        'debit_amount_base' => $fx->amountInBase($dr),
                        'credit_amount_base' => $fx->amountInBase($cr),
                    ]);
                }

                $sumDr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('debit_amount');
                $sumCr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('credit_amount');
                if (abs($sumDr - $sumCr) > 0.02) {
                    throw new \RuntimeException('Debits and credits do not balance');
                }

                $snapshot = [
                    'source_type' => 'SUPPLIER_CREDIT_NOTE',
                    'supplier_credit_note_id' => $note->id,
                    'supplier_invoice_id' => $note->supplier_invoice_id,
                    'inv_grn_id' => $note->inv_grn_id,
                ];
                if ($allocationCostCenterId) {
                    $snapshot['cost_center_id'] = $allocationCostCenterId;
                    $snapshot['cost_center_name'] = $costCenter?->name;
                }

                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $allocationProjectId,
                    'cost_center_id' => $allocationCostCenterId,
                    'party_id' => $note->party_id,
                    'allocation_type' => 'SUPPLIER_CREDIT',
                    'amount' => (string) $total,
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                    'amount_base' => $fx->amountInBase($total),
                    'rule_snapshot' => $snapshot,
                ]);

                $note->update([
                    'status' => SupplierCreditNote::STATUS_POSTED,
                    'posting_group_id' => $postingGroup->id,
                    'posted_at' => now(),
                    'project_id' => $projectId,
                    'cost_center_id' => $costCenterId,
                ]);

                return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }
}

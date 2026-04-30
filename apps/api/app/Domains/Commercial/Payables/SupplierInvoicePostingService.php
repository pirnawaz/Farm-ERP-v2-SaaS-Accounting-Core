<?php

namespace App\Domains\Commercial\Payables;

use App\Domains\Accounting\MultiCurrency\PostingFxService;
use App\Models\AllocationRow;
use App\Models\CostCenter;
use App\Models\InvItem;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\Accounting\PostValidationService;
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
 * Posts a supplier invoice: Dr INVENTORY_INPUTS (stock lines) or INPUTS_EXPENSE (non-stock), Cr AP.
 * One posting group; one allocation row per invoice line (project or cost center + supplier preserved).
 */
class SupplierInvoicePostingService
{
    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency,
        private PostingFxService $postingFx
    ) {}

    public function post(
        string $supplierInvoiceId,
        string $tenantId,
        string $postingDate,
        ?string $idempotencyKey = null
    ): PostingGroup {
        return LedgerWriteGuard::scoped(static::class, function () use ($supplierInvoiceId, $tenantId, $postingDate, $idempotencyKey) {
            return DB::transaction(function () use ($supplierInvoiceId, $tenantId, $postingDate, $idempotencyKey) {
            /** @var SupplierInvoice $invoice */
            $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)
                ->lockForUpdate()
                ->findOrFail($supplierInvoiceId);

            if ($invoice->posting_group_id) {
                $pg = TenantScoped::for(PostingGroup::query(), $tenantId)
                    ->where('id', $invoice->posting_group_id)
                    ->first();
                if ($pg) {
                    return $pg->load(['ledgerEntries.account', 'allocationRows']);
                }
            }

            $resolved = $this->postingIdempotency->resolveOrCreate($tenantId, $idempotencyKey, 'SUPPLIER_INVOICE', $invoice->id);
            if ($resolved['posting_group'] !== null) {
                $existingByKey = $resolved['posting_group'];
                if ($invoice->status !== SupplierInvoice::STATUS_POSTED || ! $invoice->posting_group_id) {
                    $invoice->update([
                        'status' => SupplierInvoice::STATUS_POSTED,
                        'posting_group_id' => $existingByKey->id,
                        'posted_at' => $invoice->posted_at ?? now(),
                    ]);
                }

                return $existingByKey->load(['ledgerEntries.account', 'allocationRows']);
            }
            $effectiveKey = $resolved['effective_key'];

            if ($invoice->status !== SupplierInvoice::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ['Only supplier invoices in DRAFT status can be posted.'],
                ]);
            }

            $invoice->load(['lines']);

            if ($invoice->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => ['At least one invoice line is required.'],
                ]);
            }

            $isGrnLinked = (bool) $invoice->grn_id;
            $hasProject = (bool) $invoice->project_id;
            $hasCostCenter = (bool) $invoice->cost_center_id;

            if ($isGrnLinked) {
                if (! $hasProject || $hasCostCenter) {
                    throw ValidationException::withMessages([
                        'scope' => ['GRN-linked supplier invoices must be project-scoped (no cost center on the same bill).'],
                    ]);
                }
            } elseif ($hasProject && $hasCostCenter) {
                throw ValidationException::withMessages([
                    'scope' => ['Choose either a project or a cost center, not both.'],
                ]);
            } elseif (! $hasProject && ! $hasCostCenter) {
                throw ValidationException::withMessages([
                    'scope' => ['Post requires a project (crop-linked) or an active cost center (farm overhead).'],
                ]);
            }

            $cropCycleId = null;
            $allocationProjectId = null;
            $allocationCostCenterId = null;
            $costCenter = null;

            if ($hasProject) {
                $project = TenantScoped::for(Project::query(), $tenantId)->findOrFail($invoice->project_id);
                $cropCycleId = $project->crop_cycle_id;
                if (! $cropCycleId) {
                    throw ValidationException::withMessages([
                        'project' => ['Project has no crop cycle.'],
                    ]);
                }
                $this->operationalPostingGuard->ensureCropCycleOpenForProject($invoice->project_id, $tenantId);
                $allocationProjectId = $invoice->project_id;
            } else {
                $costCenter = TenantScoped::for(CostCenter::query(), $tenantId)->findOrFail($invoice->cost_center_id);
                if ($costCenter->status !== CostCenter::STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'cost_center_id' => ['Cannot post to an inactive cost center.'],
                    ]);
                }
                $allocationCostCenterId = $costCenter->id;
                foreach ($invoice->lines as $line) {
                    if ($line->item_id) {
                        throw ValidationException::withMessages([
                            'lines' => ['Cost-center (farm overhead) bills cannot include inventory stock lines.'],
                        ]);
                    }
                }
            }

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
            $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateObj));

            $terms = $invoice->payment_terms ? strtoupper((string) $invoice->payment_terms) : null;
            $isCreditInvoice = $terms === 'CREDIT';

            $lineSum = 0.0;
            foreach ($invoice->lines as $line) {
                $amt = (float) $line->line_total;
                if ($amt <= 0) {
                    throw ValidationException::withMessages([
                        'lines' => ["Line {$line->line_no} must have a positive line_total."],
                    ]);
                }
                $lineSum += $amt;
                if ($line->item_id) {
                    TenantScoped::for(InvItem::query(), $tenantId)->findOrFail($line->item_id);
                }
            }

            $total = (float) $invoice->total_amount;
            if (abs($lineSum - $total) > 0.02) {
                throw ValidationException::withMessages([
                    'total_amount' => ['Sum of line totals must equal invoice total_amount.'],
                ]);
            }

            $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
            $currencyCode = strtoupper((string) ($invoice->currency_code ?: ($tenant->currency_code ?? 'GBP')));

            $fx = $this->postingFx->forPosting($tenantId, $postingDateObj, $currencyCode);

            $apAccount = $this->accountService->getByCode($tenantId, 'AP');
            $inventoryAccount = $this->accountService->getByCode($tenantId, 'INVENTORY_INPUTS');
            $expenseAccount = $this->accountService->getByCode($tenantId, 'INPUTS_EXPENSE');

            $debitBuckets = [
                $inventoryAccount->id => 0.0,
                $expenseAccount->id => 0.0,
            ];
            $premiumTotal = 0.0;

            if (! $isCreditInvoice) {
                // Legacy / CASH behavior (unchanged): debit line_total by stock/non-stock classification.
                foreach ($invoice->lines as $line) {
                    $amt = round((float) $line->line_total, 2);
                    $debitAccount = $line->item_id ? $inventoryAccount : $expenseAccount;
                    $debitBuckets[$debitAccount->id] = ($debitBuckets[$debitAccount->id] ?? 0) + $amt;
                }
            } else {
                // CREDIT: split each line into base cash amount + premium amount.
                $premiumExpense = $this->accountService->getByCode($tenantId, 'CREDIT_PURCHASE_PREMIUM_EXPENSE');
                foreach ($invoice->lines as $line) {
                    $base = $line->base_cash_amount;
                    $prem = $line->credit_premium_amount;
                    if ($base === null || $prem === null) {
                        throw ValidationException::withMessages([
                            'lines' => ["Line {$line->line_no} is missing base_cash_amount or credit_premium_amount for CREDIT invoice posting."],
                        ]);
                    }
                    $baseAmt = round((float) $base, 2);
                    $premAmt = round((float) $prem, 2);
                    if ($baseAmt < 0 || $premAmt < 0) {
                        throw ValidationException::withMessages([
                            'lines' => ["Line {$line->line_no} base_cash_amount and credit_premium_amount must be non-negative."],
                        ]);
                    }
                    $lineTotal = round((float) $line->line_total, 2);
                    if (abs(($baseAmt + $premAmt) - $lineTotal) > 0.02) {
                        throw ValidationException::withMessages([
                            'lines' => ["Line {$line->line_no} base_cash_amount + credit_premium_amount must equal line_total."],
                        ]);
                    }

                    $debitAccount = $line->item_id ? $inventoryAccount : $expenseAccount;
                    $debitBuckets[$debitAccount->id] = ($debitBuckets[$debitAccount->id] ?? 0) + $baseAmt;
                    $premiumTotal += $premAmt;
                }
            }

            $ledgerLines = [];
            foreach ($debitBuckets as $accountId => $sum) {
                if ($sum <= 0) {
                    continue;
                }
                $ledgerLines[] = [
                    'account_id' => $accountId,
                    'debit_amount' => round($sum, 2),
                    'credit_amount' => 0,
                ];
            }
            if ($premiumTotal > 0.00001) {
                // Only reachable for CREDIT invoices.
                $premiumExpense = $this->accountService->getByCode($tenantId, 'CREDIT_PURCHASE_PREMIUM_EXPENSE');
                $ledgerLines[] = [
                    'account_id' => $premiumExpense->id,
                    'debit_amount' => round($premiumTotal, 2),
                    'credit_amount' => 0,
                ];
            }
            $ledgerLines[] = [
                'account_id' => $apAccount->id,
                'debit_amount' => 0,
                'credit_amount' => round($total, 2),
            ];

            $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $cropCycleId,
                'source_type' => 'SUPPLIER_INVOICE',
                'source_id' => $invoice->id,
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

            $sumDrBase = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('debit_amount_base');
            $sumCrBase = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('credit_amount_base');
            if (abs($sumDrBase - $sumCrBase) > 0.02) {
                throw new \RuntimeException('Debits and credits do not balance in base currency');
            }

            foreach ($invoice->lines as $line) {
                $lineAmt = round((float) $line->line_total, 2);
                $snapshot = [
                    'source_type' => 'SUPPLIER_INVOICE',
                    'supplier_invoice_id' => $invoice->id,
                    'supplier_invoice_line_id' => $line->id,
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
                    'party_id' => $invoice->party_id,
                    'allocation_type' => 'SUPPLIER_AP',
                    'amount' => (string) $lineAmt,
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                    'amount_base' => $fx->amountInBase($lineAmt),
                    'rule_snapshot' => $snapshot,
                ]);

                if ($isCreditInvoice) {
                    $prem = $line->credit_premium_amount;
                    $premAmt = $prem === null ? 0.0 : round((float) $prem, 2);
                    if ($premAmt > 0.00001) {
                        AllocationRow::create([
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'project_id' => $allocationProjectId,
                            'cost_center_id' => $allocationCostCenterId,
                            'party_id' => $invoice->party_id,
                            'allocation_type' => 'SUPPLIER_INVOICE_CREDIT_PREMIUM',
                            'amount' => (string) $premAmt,
                            'currency_code' => $fx->transactionCurrencyCode,
                            'base_currency_code' => $fx->baseCurrencyCode,
                            'fx_rate' => $fx->fxRate,
                            'amount_base' => $fx->amountInBase($premAmt),
                            'rule_snapshot' => array_merge($snapshot, [
                                'credit_premium_amount' => (string) $premAmt,
                            ]),
                        ]);
                    }
                }
            }

            $invoice->update([
                'status' => SupplierInvoice::STATUS_POSTED,
                'posting_group_id' => $postingGroup->id,
                'posted_at' => now(),
            ]);

            return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }
}

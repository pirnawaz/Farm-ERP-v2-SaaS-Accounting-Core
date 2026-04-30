<?php

namespace App\Domains\Commercial\AccountsPayable;

use App\Domains\Accounting\MultiCurrency\PostingFxService;
use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
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
 * AP-2: Post SupplierBill (operational draft) into immutable accounting artifacts.
 *
 * - One PostingGroup per bill (idempotent by source_type + source_id or idempotency key)
 * - AllocationRows created for base cash cost and credit premium separately
 * - Balanced LedgerEntries: Dr base cost, Dr premium expense (if any), Cr AP total payable
 *
 * This service must NOT mutate any historical posting groups/allocation rows/ledger entries.
 */
final class SupplierBillPostingService
{
    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency,
        private PostingFxService $postingFx,
        private SupplierBillCalculator $calculator
    ) {}

    public function post(
        string $supplierBillId,
        string $tenantId,
        string $postingDate,
        ?string $idempotencyKey = null,
        ?string $postedBy = null
    ): PostingGroup {
        // Use an allowlisted writer class for LedgerWriteGuard without requiring config changes.
        return LedgerWriteGuard::scoped(\App\Services\PostingService::class, function () use (
            $supplierBillId,
            $tenantId,
            $postingDate,
            $idempotencyKey,
            $postedBy
        ) {
            return DB::transaction(function () use ($supplierBillId, $tenantId, $postingDate, $idempotencyKey, $postedBy) {
                /** @var SupplierBill $bill */
                $bill = TenantScoped::for(SupplierBill::query(), $tenantId)
                    ->lockForUpdate()
                    ->with(['lines'])
                    ->findOrFail($supplierBillId);

                if ($bill->posting_group_id) {
                    $pg = TenantScoped::for(PostingGroup::query(), $tenantId)
                        ->where('id', $bill->posting_group_id)
                        ->first();
                    if ($pg) {
                        return $pg->load(['ledgerEntries.account', 'allocationRows']);
                    }
                }

                $resolved = $this->postingIdempotency->resolveOrCreate($tenantId, $idempotencyKey, 'SUPPLIER_BILL', $bill->id);
                if ($resolved['posting_group'] !== null) {
                    $existing = $resolved['posting_group'];
                    if ($bill->status !== SupplierBill::STATUS_POSTED || ! $bill->posting_group_id) {
                        $bill->update([
                            'status' => SupplierBill::STATUS_POSTED,
                            'posting_group_id' => $existing->id,
                            'posting_date' => $existing->posting_date,
                            'posted_at' => $bill->posted_at ?? now(),
                            'posted_by' => $bill->posted_by ?? $postedBy,
                        ]);
                    }
                    return $existing->load(['ledgerEntries.account', 'allocationRows']);
                }
                $effectiveKey = $resolved['effective_key'];

                if ($bill->status !== SupplierBill::STATUS_DRAFT) {
                    throw ValidationException::withMessages([
                        'status' => ['Only DRAFT supplier bills can be posted.'],
                    ]);
                }

                $supplier = TenantScoped::for(Supplier::query(), $tenantId)->findOrFail($bill->supplier_id);
                if ($supplier->status !== 'ACTIVE') {
                    throw ValidationException::withMessages([
                        'supplier_id' => ['Supplier is not ACTIVE.'],
                    ]);
                }

                $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateObj));

                if ($bill->lines->isEmpty()) {
                    throw ValidationException::withMessages([
                        'lines' => ['At least one line is required.'],
                    ]);
                }

                // Validate all lines are allocated and belong to tenant; also enforce one crop cycle per bill.
                $cropCycleId = null;
                foreach ($bill->lines as $line) {
                    /** @var SupplierBillLine $line */
                    if (! $line->project_id) {
                        throw ValidationException::withMessages(['lines' => ['Each line must have project_id before posting.']]);
                    }
                    if (! $line->crop_cycle_id) {
                        throw ValidationException::withMessages(['lines' => ['Each line must have crop_cycle_id before posting.']]);
                    }
                    TenantScoped::for(Project::query(), $tenantId)->where('id', $line->project_id)->firstOrFail();
                    TenantScoped::for(CropCycle::query(), $tenantId)->where('id', $line->crop_cycle_id)->firstOrFail();

                    $cropCycleId = $cropCycleId ?? $line->crop_cycle_id;
                    if ((string) $cropCycleId !== (string) $line->crop_cycle_id) {
                        throw ValidationException::withMessages([
                            'lines' => ['All lines must belong to the same crop cycle for posting (one PostingGroup per bill).'],
                        ]);
                    }

                    $this->operationalPostingGuard->ensureCropCycleOpenForProject($line->project_id, $tenantId);
                }

                // Validate posting date within crop cycle bounds (if dates exist).
                $cycle = TenantScoped::for(CropCycle::query(), $tenantId)->findOrFail($cropCycleId);
                $this->operationalPostingGuard->assertPostingDateWithinCropCycleBounds($cycle, $postingDateObj);

                // Validate stored calculations are consistent with calculator rules at post time.
                $calcLines = $bill->lines->map(function (SupplierBillLine $l) {
                    return [
                        'line_no' => $l->line_no,
                        'qty' => (float) $l->qty,
                        'cash_unit_price' => (float) $l->cash_unit_price,
                        'credit_unit_price' => $l->credit_unit_price === null ? null : (float) $l->credit_unit_price,
                    ];
                })->all();

                $recalc = $this->calculator->calculateBill($bill->payment_terms, $calcLines);
                if (abs(((float) $recalc['subtotal_cash_amount']) - ((float) $bill->subtotal_cash_amount)) > 0.02) {
                    throw ValidationException::withMessages(['subtotal_cash_amount' => ['Bill subtotal_cash_amount does not match recalculated value.']]);
                }
                if (abs(((float) $recalc['credit_premium_total']) - ((float) $bill->credit_premium_total)) > 0.02) {
                    throw ValidationException::withMessages(['credit_premium_total' => ['Bill credit_premium_total does not match recalculated value.']]);
                }
                if (abs(((float) $recalc['grand_total']) - ((float) $bill->grand_total)) > 0.02) {
                    throw ValidationException::withMessages(['grand_total' => ['Bill grand_total does not match recalculated value.']]);
                }

                // Additional rule: if CREDIT terms, credit price must be >= cash price (line-level).
                if ($bill->payment_terms === SupplierBill::TERMS_CREDIT) {
                    foreach ($bill->lines as $l) {
                        if ($l->credit_unit_price === null) {
                            throw ValidationException::withMessages(['lines' => ['credit_unit_price is required on all lines for CREDIT bills.']]);
                        }
                        if ((float) $l->credit_unit_price + 1e-9 < (float) $l->cash_unit_price) {
                            throw ValidationException::withMessages(['lines' => ['credit_unit_price must be >= cash_unit_price for CREDIT bills.']]);
                        }
                    }
                }

                $currencyCode = strtoupper((string) ($bill->currency_code ?: 'GBP'));
                $fx = $this->postingFx->forPosting($tenantId, $postingDateObj, $currencyCode);

                $ap = $this->accountService->getByCode($tenantId, 'AP');
                $baseCost = $this->accountService->getByCode($tenantId, 'INPUTS_EXPENSE');
                $premiumExpense = $this->accountService->getByCode($tenantId, 'CREDIT_PURCHASE_PREMIUM_EXPENSE');

                $baseSum = (float) $bill->subtotal_cash_amount;
                $premiumSum = (float) $bill->credit_premium_total;
                $grandTotal = (float) $bill->grand_total;

                $ledgerLines = [
                    ['account_id' => $baseCost->id],
                    ['account_id' => $ap->id],
                ];
                if ($premiumSum > 0.00001) {
                    $ledgerLines[] = ['account_id' => $premiumExpense->id];
                }
                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $cropCycleId,
                    'source_type' => 'SUPPLIER_BILL',
                    'source_id' => $bill->id,
                    'posting_date' => $postingDateObj,
                    'idempotency_key' => $effectiveKey,
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                ]);

                // Allocation rows first (per line; base cash always, premium only when >0).
                foreach ($bill->lines as $line) {
                    $baseAmt = round((float) $line->base_cash_amount, 2);
                    if ($baseAmt > 0) {
                        AllocationRow::create([
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'project_id' => $line->project_id,
                            'party_id' => $supplier->party_id,
                            'allocation_type' => 'SUPPLIER_BILL_BASE',
                            'amount' => (string) $baseAmt,
                            'currency_code' => $fx->transactionCurrencyCode,
                            'base_currency_code' => $fx->baseCurrencyCode,
                            'fx_rate' => $fx->fxRate,
                            'amount_base' => $fx->amountInBase($baseAmt),
                            'rule_snapshot' => [
                                'source_type' => 'SUPPLIER_BILL',
                                'supplier_bill_id' => $bill->id,
                                'supplier_bill_line_id' => $line->id,
                                'cost_category' => $line->cost_category,
                            ],
                        ]);
                    }

                    $premiumAmt = round((float) $line->credit_premium_amount, 2);
                    if ($premiumAmt > 0) {
                        AllocationRow::create([
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'project_id' => $line->project_id,
                            'party_id' => $supplier->party_id,
                            'allocation_type' => 'SUPPLIER_BILL_CREDIT_PREMIUM',
                            'amount' => (string) $premiumAmt,
                            'currency_code' => $fx->transactionCurrencyCode,
                            'base_currency_code' => $fx->baseCurrencyCode,
                            'fx_rate' => $fx->fxRate,
                            'amount_base' => $fx->amountInBase($premiumAmt),
                            'rule_snapshot' => [
                                'source_type' => 'SUPPLIER_BILL',
                                'supplier_bill_id' => $bill->id,
                                'supplier_bill_line_id' => $line->id,
                                'cost_category' => $line->cost_category,
                            ],
                        ]);
                    }
                }

                // Ledger entries (aggregate to 2-3 lines).
                $this->createLedgerEntry($tenantId, $postingGroup->id, $baseCost, $baseSum, 0.0, $fx);
                if ($premiumSum > 0.00001) {
                    $this->createLedgerEntry($tenantId, $postingGroup->id, $premiumExpense, $premiumSum, 0.0, $fx);
                }
                $this->createLedgerEntry($tenantId, $postingGroup->id, $ap, 0.0, $grandTotal, $fx);

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

                $bill->update([
                    'status' => SupplierBill::STATUS_POSTED,
                    'posting_group_id' => $postingGroup->id,
                    'posting_date' => $postingDateObj,
                    'posted_at' => now(),
                    'posted_by' => $postedBy,
                    'payment_status' => 'UNPAID',
                    'paid_amount' => '0.00',
                    'outstanding_amount' => number_format((float) $bill->grand_total, 2, '.', ''),
                ]);

                return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }

    private function createLedgerEntry(
        string $tenantId,
        string $postingGroupId,
        Account $account,
        float $debit,
        float $credit,
        object $fx
    ): void {
        $dr = round($debit, 2);
        $cr = round($credit, 2);
        if ($dr <= 0 && $cr <= 0) {
            return;
        }

        LedgerEntry::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $postingGroupId,
            'account_id' => $account->id,
            'debit_amount' => $dr,
            'credit_amount' => $cr,
            'currency_code' => $fx->transactionCurrencyCode,
            'base_currency_code' => $fx->baseCurrencyCode,
            'fx_rate' => $fx->fxRate,
            'debit_amount_base' => $fx->amountInBase($dr),
            'credit_amount_base' => $fx->amountInBase($cr),
        ]);
    }
}


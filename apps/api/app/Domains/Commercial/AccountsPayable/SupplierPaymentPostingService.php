<?php

namespace App\Domains\Commercial\AccountsPayable;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillPaymentAllocation;
use App\Models\SupplierPayment;
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

final class SupplierPaymentPostingService
{
    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency,
        private SupplierBillPaymentStatusService $billStatusService
    ) {}

    public function post(
        string $supplierPaymentId,
        string $tenantId,
        string $postingDate,
        ?string $idempotencyKey = null,
        ?string $postedBy = null
    ): PostingGroup {
        return LedgerWriteGuard::scoped(\App\Services\PaymentService::class, function () use (
            $supplierPaymentId,
            $tenantId,
            $postingDate,
            $idempotencyKey,
            $postedBy
        ) {
            return DB::transaction(function () use ($supplierPaymentId, $tenantId, $postingDate, $idempotencyKey, $postedBy) {
                /** @var SupplierPayment $payment */
                $payment = TenantScoped::for(SupplierPayment::query(), $tenantId)
                    ->lockForUpdate()
                    ->with(['allocations'])
                    ->findOrFail($supplierPaymentId);

                if ($payment->posting_group_id) {
                    $pg = TenantScoped::for(PostingGroup::query(), $tenantId)->where('id', $payment->posting_group_id)->first();
                    if ($pg) {
                        return $pg->load(['ledgerEntries.account']);
                    }
                }

                $resolved = $this->postingIdempotency->resolveOrCreate($tenantId, $idempotencyKey, 'SUPPLIER_PAYMENT', $payment->id);
                if ($resolved['posting_group'] !== null) {
                    $existing = $resolved['posting_group'];
                    if ($payment->status !== SupplierPayment::STATUS_POSTED || ! $payment->posting_group_id) {
                        $payment->update([
                            'status' => SupplierPayment::STATUS_POSTED,
                            'posting_group_id' => $existing->id,
                            'posting_date' => $existing->posting_date,
                            'posted_at' => $payment->posted_at ?? now(),
                            'posted_by' => $payment->posted_by ?? $postedBy,
                        ]);
                    }

                    // Refresh bill statuses for allocations (safe).
                    $billIds = SupplierBillPaymentAllocation::where('tenant_id', $tenantId)
                        ->where('supplier_payment_id', $payment->id)
                        ->pluck('supplier_bill_id')
                        ->all();
                    $this->billStatusService->refreshBills($billIds, $tenantId);

                    return $existing->load(['ledgerEntries.account']);
                }
                $effectiveKey = $resolved['effective_key'];

                if ($payment->status !== SupplierPayment::STATUS_DRAFT) {
                    throw ValidationException::withMessages(['status' => ['Only DRAFT supplier payments can be posted.']]);
                }

                $supplier = TenantScoped::for(Supplier::query(), $tenantId)->findOrFail($payment->supplier_id);
                if ($supplier->status !== 'ACTIVE') {
                    throw ValidationException::withMessages(['supplier_id' => ['Supplier is not ACTIVE.']]);
                }

                $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateObj));

                $allocs = SupplierBillPaymentAllocation::where('tenant_id', $tenantId)
                    ->where('supplier_payment_id', $payment->id)
                    ->get();
                if ($allocs->isEmpty()) {
                    throw ValidationException::withMessages(['allocations' => ['At least one allocation is required.']]);
                }

                $sumAlloc = (float) $allocs->sum('amount_applied');
                $total = (float) $payment->total_amount;
                if (abs($sumAlloc - $total) > 0.02) {
                    throw ValidationException::withMessages(['total_amount' => ['Sum of allocations must equal payment total_amount.']]);
                }

                // Validate bills are posted (or paid states) and allocation does not exceed outstanding.
                $cropCycleId = null;
                $billIds = [];
                foreach ($allocs as $a) {
                    /** @var SupplierBillPaymentAllocation $a */
                    /** @var SupplierBill $bill */
                    $bill = TenantScoped::for(SupplierBill::query(), $tenantId)->lockForUpdate()->findOrFail($a->supplier_bill_id);
                    $billIds[] = $bill->id;
                    if (! in_array($bill->status, [SupplierBill::STATUS_POSTED, SupplierBill::STATUS_PARTIALLY_PAID], true)) {
                        throw ValidationException::withMessages(['supplier_bill_id' => ['Allocations require bills to be POSTED or PARTIALLY_PAID.']]);
                    }
                    if ((string) $bill->supplier_id !== (string) $payment->supplier_id) {
                        throw ValidationException::withMessages(['supplier_bill_id' => ['Allocated bill supplier must match payment supplier.']]);
                    }

                    $outstanding = (float) ($bill->outstanding_amount ?? $bill->grand_total);
                    $amt = (float) $a->amount_applied;
                    if ($amt - $outstanding > 0.02) {
                        throw ValidationException::withMessages(['amount_applied' => ['Allocation exceeds unpaid bill balance.']]);
                    }

                    // Resolve crop cycle for posting group: use bill's posting group crop cycle (single cycle per payment).
                    if (! $bill->posting_group_id) {
                        throw ValidationException::withMessages(['supplier_bill_id' => ['Bill must have posting_group_id (posted) before payment allocation.']]);
                    }
                    $pgBill = TenantScoped::for(PostingGroup::query(), $tenantId)->findOrFail($bill->posting_group_id);
                    $cropCycleId = $cropCycleId ?? $pgBill->crop_cycle_id;
                    if ((string) $cropCycleId !== (string) $pgBill->crop_cycle_id) {
                        throw ValidationException::withMessages(['allocations' => ['All allocated bills must be in the same crop cycle for posting.']]);
                    }
                }

                $this->operationalPostingGuard->ensureCropCycleOpen($cropCycleId, $tenantId);

                $ap = $this->accountService->getByCode($tenantId, 'AP');
                $creditAccount = $this->resolveCreditAccount($tenantId, $payment);

                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, [
                    ['account_id' => $ap->id],
                    ['account_id' => $creditAccount->id],
                ]);

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $cropCycleId,
                    'source_type' => 'SUPPLIER_PAYMENT',
                    'source_id' => $payment->id,
                    'posting_date' => $postingDateObj,
                    'idempotency_key' => $effectiveKey,
                ]);

                // Ledger: Dr AP, Cr bank/cash.
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $ap->id,
                    'debit_amount' => $total,
                    'credit_amount' => 0,
                    'currency_code' => $creditAccount->tenant?->currency_code ?? 'GBP',
                ]);
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $creditAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $total,
                    'currency_code' => $creditAccount->tenant?->currency_code ?? 'GBP',
                ]);

                $sumDr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('debit_amount');
                $sumCr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('credit_amount');
                if (abs($sumDr - $sumCr) > 0.02) {
                    throw new \RuntimeException('Debits and credits do not balance');
                }

                $payment->update([
                    'status' => SupplierPayment::STATUS_POSTED,
                    'posting_group_id' => $postingGroup->id,
                    'posting_date' => $postingDateObj,
                    'posted_at' => now(),
                    'posted_by' => $postedBy,
                ]);

                // Update only AP-3 fields on bills.
                $this->billStatusService->refreshBills($billIds, $tenantId);

                return $postingGroup->fresh(['ledgerEntries.account']);
            });
        });
    }

    private function resolveCreditAccount(string $tenantId, SupplierPayment $payment): Account
    {
        $method = strtoupper((string) $payment->payment_method);
        if ($method === 'BANK') {
            if (! $payment->bank_account_id) {
                throw ValidationException::withMessages(['bank_account_id' => ['bank_account_id is required for BANK payments.']]);
            }
            $acct = TenantScoped::for(Account::query(), $tenantId)->findOrFail($payment->bank_account_id);
            if (strtolower((string) $acct->type) !== 'asset') {
                throw ValidationException::withMessages(['bank_account_id' => ['Bank account must be an asset account.']]);
            }
            return $acct;
        }

        return $this->accountService->getByCode($tenantId, 'CASH');
    }
}


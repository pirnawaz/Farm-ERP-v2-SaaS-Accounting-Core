<?php

namespace App\Services;

use App\Models\AllocationRow;
use App\Models\InvGrn;
use App\Models\Payment;
use App\Models\GrnPaymentAllocation;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * AP allocation rail: apply/unapply supplier payments (OUT) to GRNs (bills).
 * Reconciliation-only; no ledger writes. ACTIVE allocations only; cutoff allocation_date <= as_of.
 */
class BillPaymentService
{
    /**
     * Bill total for a GRN from SUPPLIER_AP allocation row (immutable).
     */
    public function getGrnBillTotal(string $grnId, string $tenantId): float
    {
        $grn = InvGrn::where('id', $grnId)->where('tenant_id', $tenantId)->firstOrFail();
        if (!$grn->posting_group_id) {
            return 0.0;
        }
        $v = AllocationRow::where('tenant_id', $tenantId)
            ->where('posting_group_id', $grn->posting_group_id)
            ->where('allocation_type', 'SUPPLIER_AP')
            ->value('amount');
        return (float) ($v ?? 0);
    }

    /**
     * Outstanding for a GRN: bill total minus ACTIVE allocations with allocation_date <= as_of.
     */
    public function getGrnOutstanding(string $grnId, string $tenantId, ?string $asOfDate = null): float
    {
        $total = $this->getGrnBillTotal($grnId, $tenantId);
        $query = GrnPaymentAllocation::where('tenant_id', $tenantId)
            ->where('grn_id', $grnId)
            ->where(function ($q) {
                $q->where('status', GrnPaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            });
        if ($asOfDate) {
            $query->where('allocation_date', '<=', $asOfDate);
        }
        $allocated = (float) $query->sum('amount');
        return max(0, $total - $allocated);
    }

    /**
     * Open bills for a supplier: POSTED, not REVERSED GRNs with outstanding > 0.
     * Each item: grn_id, doc_no, posting_date, due_date (posting_date), bill_total, allocated, outstanding.
     */
    public function getSupplierOpenBills(
        string $supplierPartyId,
        string $tenantId,
        ?string $asOfDate = null
    ): array {
        $grns = InvGrn::where('tenant_id', $tenantId)
            ->where('supplier_party_id', $supplierPartyId)
            ->where('status', 'POSTED')
            ->orderBy('posting_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        $open = [];
        foreach ($grns as $grn) {
            $total = $this->getGrnBillTotal($grn->id, $tenantId);
            $allocated = (float) GrnPaymentAllocation::where('tenant_id', $tenantId)
                ->where('grn_id', $grn->id)
                ->where(function ($q) {
                    $q->where('status', GrnPaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
                })
                ->when($asOfDate, fn ($q) => $q->where('allocation_date', '<=', $asOfDate))
                ->sum('amount');
            $outstanding = max(0, $total - $allocated);
            if ($outstanding <= 0) {
                continue;
            }
            $open[] = [
                'grn_id' => $grn->id,
                'doc_no' => $grn->doc_no,
                'posting_date' => $grn->posting_date ? $grn->posting_date->format('Y-m-d') : null,
                'due_date' => $grn->posting_date ? $grn->posting_date->format('Y-m-d') : null,
                'bill_total' => (string) round($total, 2),
                'allocated' => (string) round($allocated, 2),
                'outstanding' => (string) round($outstanding, 2),
            ];
        }
        return $open;
    }

    /**
     * Sum of open bills for a supplier (for reports / balance).
     */
    public function getSupplierOpenBillsTotal(string $supplierPartyId, string $tenantId, ?string $asOfDate = null): float
    {
        $open = $this->getSupplierOpenBills($supplierPartyId, $tenantId, $asOfDate);
        return array_sum(array_map(fn ($b) => (float) $b['outstanding'], $open));
    }

    /**
     * Allocate a supplier payment (OUT) to bills: FIFO or MANUAL. Creates ACTIVE allocation rows only.
     *
     * @param string $paymentId
     * @param string $tenantId
     * @param string $postingGroupId Payment's posting_group_id
     * @param string $allocationDate YYYY-MM-DD
     * @param string $mode 'FIFO' | 'MANUAL'
     * @param array|null $manualAllocations [['grn_id' => ..., 'amount' => ...]] for MANUAL
     * @param string|null $createdBy
     * @return array Created allocations
     */
    public function allocatePaymentToBills(
        string $paymentId,
        string $tenantId,
        string $postingGroupId,
        string $allocationDate,
        string $mode = 'FIFO',
        ?array $manualAllocations = null,
        ?string $createdBy = null
    ): array {
        return DB::transaction(function () use ($paymentId, $tenantId, $postingGroupId, $allocationDate, $mode, $manualAllocations, $createdBy) {
            $payment = Payment::where('id', $paymentId)
                ->where('tenant_id', $tenantId)
                ->where('direction', 'OUT')
                ->where('status', 'POSTED')
                ->whereNull('reversed_at')
                ->firstOrFail();

            if ($payment->posting_group_id !== $postingGroupId) {
                throw new \InvalidArgumentException('posting_group_id does not match payment.');
            }

            $existing = GrnPaymentAllocation::where('tenant_id', $tenantId)
                ->where('payment_id', $paymentId)
                ->where('posting_group_id', $postingGroupId)
                ->where(function ($q) {
                    $q->where('status', GrnPaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
                })
                ->sum('amount');
            $paymentAmount = (float) $payment->amount;
            $unapplied = $paymentAmount - (float) $existing;

            if ($unapplied <= 0) {
                throw new \InvalidArgumentException('Payment has no unapplied amount to allocate.');
            }

            $supplierPartyId = $payment->party_id;
            $toCreate = [];

            if ($mode === 'FIFO') {
                $openBills = $this->getSupplierOpenBills($supplierPartyId, $tenantId, $allocationDate);
                $remaining = $unapplied;
                foreach ($openBills as $bill) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $outstanding = (float) $bill['outstanding'];
                    $alloc = min($remaining, $outstanding);
                    if ($alloc > 0) {
                        $toCreate[] = ['grn_id' => $bill['grn_id'], 'amount' => $alloc];
                        $remaining -= $alloc;
                    }
                }
            } else {
                if (!$manualAllocations || !is_array($manualAllocations)) {
                    throw new \InvalidArgumentException('Manual allocations array is required for MANUAL mode.');
                }
                foreach ($manualAllocations as $a) {
                    $grnId = $a['grn_id'] ?? null;
                    $amount = isset($a['amount']) ? (float) $a['amount'] : 0;
                    if (!$grnId || $amount <= 0) {
                        continue;
                    }
                    $outstanding = $this->getGrnOutstanding($grnId, $tenantId, $allocationDate);
                    if ($amount > $outstanding) {
                        throw new \InvalidArgumentException("Allocation amount ({$amount}) exceeds outstanding ({$outstanding}) for GRN {$grnId}.");
                    }
                    $grn = InvGrn::where('id', $grnId)->where('tenant_id', $tenantId)
                        ->where('supplier_party_id', $supplierPartyId)->where('status', 'POSTED')->firstOrFail();
                    $toCreate[] = ['grn_id' => $grnId, 'amount' => $amount];
                }
                $totalRequested = array_sum(array_column($toCreate, 'amount'));
                if ($totalRequested > $unapplied) {
                    throw new \InvalidArgumentException("Total allocation ({$totalRequested}) exceeds unapplied amount ({$unapplied}).");
                }
            }

            $created = [];
            foreach ($toCreate as $item) {
                $alloc = GrnPaymentAllocation::create([
                    'tenant_id' => $tenantId,
                    'grn_id' => $item['grn_id'],
                    'payment_id' => $paymentId,
                    'posting_group_id' => $postingGroupId,
                    'allocation_date' => $allocationDate,
                    'amount' => $item['amount'],
                    'status' => GrnPaymentAllocation::STATUS_ACTIVE,
                    'created_by' => $createdBy,
                ]);
                $created[] = ['id' => $alloc->id, 'grn_id' => $alloc->grn_id, 'amount' => (float) $alloc->amount];
            }
            return $created;
        });
    }

    /**
     * Apply payment to bills: wrapper that uses payment's posting_group_id.
     */
    public function applyPaymentToBills(
        string $tenantId,
        string $paymentId,
        string $mode,
        ?array $allocations = null,
        ?string $allocationDate = null,
        ?string $createdBy = null
    ): array {
        $allocationDate = $allocationDate ?: Carbon::today()->format('Y-m-d');
        $payment = Payment::where('id', $paymentId)->where('tenant_id', $tenantId)
            ->where('direction', 'OUT')->where('status', 'POSTED')->whereNull('reversed_at')->firstOrFail();
        if (!$payment->posting_group_id) {
            throw new \InvalidArgumentException('Payment must have posting_group_id to apply to bills.');
        }
        return $this->allocatePaymentToBills(
            $paymentId,
            $tenantId,
            $payment->posting_group_id,
            $allocationDate,
            $mode,
            $allocations,
            $createdBy
        );
    }

    /**
     * Unapply: void ACTIVE allocations for this payment (optionally by grn_id). No ledger change.
     */
    public function unapplyPaymentFromBills(string $tenantId, string $paymentId, ?string $grnId = null, ?string $voidedBy = null): array
    {
        return DB::transaction(function () use ($tenantId, $paymentId, $grnId, $voidedBy) {
            Payment::where('id', $paymentId)
                ->where('tenant_id', $tenantId)
                ->where('direction', 'OUT')
                ->where('status', 'POSTED')
                ->whereNull('reversed_at')
                ->firstOrFail();

            $query = GrnPaymentAllocation::where('tenant_id', $tenantId)
                ->where('payment_id', $paymentId)
                ->where('status', GrnPaymentAllocation::STATUS_ACTIVE);
            if ($grnId !== null) {
                $query->where('grn_id', $grnId);
            }
            $query->update([
                'status' => GrnPaymentAllocation::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $voidedBy,
            ]);

            return $this->getPaymentAllocationSummary($tenantId, $paymentId);
        });
    }

    /**
     * Summary: applied amount, unapplied amount, list of ACTIVE allocations.
     */
    public function getPaymentAllocationSummary(string $tenantId, string $paymentId): array
    {
        $payment = Payment::where('id', $paymentId)->where('tenant_id', $tenantId)->firstOrFail();
        $applied = (float) GrnPaymentAllocation::where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->where(function ($q) {
                $q->where('status', GrnPaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            })
            ->sum('amount');
        $total = (float) $payment->amount;
        $unapplied = max(0, $total - $applied);

        $allocations = GrnPaymentAllocation::where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->where(function ($q) {
                $q->where('status', GrnPaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            })
            ->with('grn:id,doc_no,posting_date')
            ->orderBy('allocation_date')->orderBy('id')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'grn_id' => $a->grn_id,
                'doc_no' => $a->grn?->doc_no,
                'amount' => number_format((float) $a->amount, 2, '.', ''),
                'allocation_date' => $a->allocation_date->format('Y-m-d'),
            ])
            ->values()
            ->all();

        return [
            'payment_id' => $paymentId,
            'amount' => number_format($total, 2, '.', ''),
            'applied_amount' => number_format($applied, 2, '.', ''),
            'unapplied_amount' => number_format($unapplied, 2, '.', ''),
            'allocations' => $allocations,
        ];
    }

    /**
     * Preview apply payment (OUT) to open bills: payment summary + open bills + suggested FIFO allocations.
     */
    public function previewApplyPaymentToBills(string $tenantId, string $paymentId, string $mode = 'FIFO'): array
    {
        $payment = Payment::where('id', $paymentId)
            ->where('tenant_id', $tenantId)
            ->where('direction', 'OUT')
            ->where('status', 'POSTED')
            ->whereNull('reversed_at')
            ->firstOrFail();

        $applied = (float) GrnPaymentAllocation::where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->where(function ($q) {
                $q->where('status', GrnPaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            })
            ->sum('amount');
        $paymentAmount = (float) $payment->amount;
        $unapplied = max(0, $paymentAmount - $applied);

        $openBills = $this->getSupplierOpenBills($payment->party_id, $tenantId, null);
        $suggested = [];
        if ($mode === 'FIFO' && $unapplied > 0) {
            $remaining = $unapplied;
            foreach ($openBills as $bill) {
                if ($remaining <= 0) {
                    break;
                }
                $outstanding = (float) $bill['outstanding'];
                $alloc = min($remaining, $outstanding);
                if ($alloc > 0) {
                    $suggested[] = ['grn_id' => $bill['grn_id'], 'amount' => number_format($alloc, 2, '.', '')];
                    $remaining -= $alloc;
                }
            }
        }

        return [
            'payment_summary' => [
                'id' => $payment->id,
                'amount' => number_format($paymentAmount, 2, '.', ''),
                'unapplied_amount' => number_format($unapplied, 2, '.', ''),
            ],
            'open_bills' => $openBills,
            'suggested_allocations' => $suggested,
        ];
    }

    /**
     * Unapplied amount for a posted OUT payment (for reports).
     */
    public function getPaymentUnappliedAmount(string $paymentId, string $tenantId, ?string $asOfDate = null): float
    {
        $payment = Payment::where('id', $paymentId)->where('tenant_id', $tenantId)
            ->where('direction', 'OUT')->where('status', 'POSTED')->whereNull('reversed_at')->first();
        if (!$payment) {
            return 0.0;
        }
        $applied = (float) GrnPaymentAllocation::where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->where(function ($q) {
                $q->where('status', GrnPaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            })
            ->when($asOfDate, fn ($q) => $q->where('allocation_date', '<=', $asOfDate))
            ->sum('amount');
        return max(0, (float) $payment->amount - $applied);
    }

    /**
     * Count of ACTIVE allocations for a GRN (for reversal guard).
     */
    public function getGrnActiveAllocationCount(string $grnId, string $tenantId): int
    {
        return GrnPaymentAllocation::where('tenant_id', $tenantId)
            ->where('grn_id', $grnId)
            ->where(function ($q) {
                $q->where('status', GrnPaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            })
            ->count();
    }

    /**
     * Count of ACTIVE allocations for a payment (for reversal guard).
     */
    public function getPaymentActiveAllocationCount(string $paymentId, string $tenantId): int
    {
        return GrnPaymentAllocation::where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->where(function ($q) {
                $q->where('status', GrnPaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            })
            ->count();
    }
}

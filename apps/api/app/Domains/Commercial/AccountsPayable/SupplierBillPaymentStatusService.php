<?php

namespace App\Domains\Commercial\AccountsPayable;

use App\Models\SupplierBill;
use App\Models\SupplierBillPaymentAllocation;
use App\Models\SupplierPayment;
use App\Support\TenantScoped;
use Illuminate\Support\Facades\DB;

final class SupplierBillPaymentStatusService
{
    /**
     * Recalculate and persist paid/outstanding/payment_status + status for a bill.
     * Only updates fields created by AP-3 (safe; does not touch historical accounting artifacts).
     */
    public function refreshBill(string $supplierBillId, string $tenantId): SupplierBill
    {
        /** @var SupplierBill $bill */
        $bill = TenantScoped::for(SupplierBill::query(), $tenantId)->lockForUpdate()->findOrFail($supplierBillId);

        $paid = (float) SupplierBillPaymentAllocation::query()
            ->where('tenant_id', $tenantId)
            ->where('supplier_bill_id', $supplierBillId)
            ->whereIn('supplier_payment_id', function ($q) use ($tenantId) {
                $q->select('id')
                    ->from('supplier_payments')
                    ->where('tenant_id', $tenantId)
                    ->where('status', SupplierPayment::STATUS_POSTED);
            })
            ->sum('amount_applied');

        $grand = (float) $bill->grand_total;
        $outstanding = max(0.0, round($grand - $paid, 2));

        $paymentStatus = 'UNPAID';
        $nextBillStatus = $bill->status;
        if ($outstanding <= 0.00001) {
            $paymentStatus = 'PAID';
            $nextBillStatus = SupplierBill::STATUS_PAID;
        } elseif ($paid > 0.00001) {
            $paymentStatus = 'PARTIALLY_PAID';
            $nextBillStatus = SupplierBill::STATUS_PARTIALLY_PAID;
        } else {
            $paymentStatus = 'UNPAID';
            // Keep as POSTED (unpaid) after posting.
            $nextBillStatus = $bill->status === SupplierBill::STATUS_POSTED ? SupplierBill::STATUS_POSTED : $bill->status;
        }

        $bill->update([
            'paid_amount' => number_format(round($paid, 2), 2, '.', ''),
            'outstanding_amount' => number_format($outstanding, 2, '.', ''),
            'payment_status' => $paymentStatus,
            'status' => $nextBillStatus,
        ]);

        return $bill->fresh();
    }

    /**
     * Refresh a set of bills in one transaction.
     *
     * @param list<string> $billIds
     */
    public function refreshBills(array $billIds, string $tenantId): void
    {
        DB::transaction(function () use ($billIds, $tenantId) {
            foreach (array_values(array_unique($billIds)) as $id) {
                $this->refreshBill($id, $tenantId);
            }
        });
    }
}


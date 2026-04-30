<?php

namespace App\Domains\Commercial\AccountsPayable;

use App\Models\SupplierBill;
use Illuminate\Validation\ValidationException;

final class SupplierBillDraftGuard
{
    public function assertDraft(SupplierBill $bill): void
    {
        if ($bill->status !== SupplierBill::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only DRAFT supplier bills can be edited.'],
            ]);
        }
    }
}


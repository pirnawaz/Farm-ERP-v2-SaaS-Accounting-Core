<?php

namespace App\Domains\Commercial\AccountsPayable;

use App\Models\SupplierBill;
use Illuminate\Validation\ValidationException;

final class SupplierBillCalculator
{
    /**
     * @param array{qty:mixed,cash_unit_price:mixed,credit_unit_price:mixed} $line
     * @return array{base_cash_amount:string,selected_unit_price:string,credit_premium_amount:string,line_total:string}
     */
    public function calculateLine(string $paymentTerms, array $line): array
    {
        $qty = (float) ($line['qty'] ?? 0);
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => ['qty must be > 0']]);
        }

        $cash = (float) ($line['cash_unit_price'] ?? 0);
        if ($cash < 0) {
            throw ValidationException::withMessages(['cash_unit_price' => ['cash_unit_price must be >= 0']]);
        }

        $creditRaw = $line['credit_unit_price'] ?? null;
        $credit = $creditRaw === null || $creditRaw === '' ? null : (float) $creditRaw;
        if ($credit !== null && $credit < 0) {
            throw ValidationException::withMessages(['credit_unit_price' => ['credit_unit_price must be >= 0 when provided']]);
        }

        $baseCashAmount = round($qty * $cash, 2);

        if ($paymentTerms === SupplierBill::TERMS_CASH) {
            $selectedUnit = $cash;
            $lineTotal = round($qty * $cash, 2);
            $premium = 0.0;
        } elseif ($paymentTerms === SupplierBill::TERMS_CREDIT) {
            if ($credit === null) {
                throw ValidationException::withMessages(['credit_unit_price' => ['credit_unit_price is required when bill payment_terms is CREDIT']]);
            }
            $selectedUnit = $credit;
            $lineTotal = round($qty * $credit, 2);
            $premium = max(0.0, round($lineTotal - $baseCashAmount, 2));
        } else {
            throw ValidationException::withMessages(['payment_terms' => ['payment_terms must be CASH or CREDIT']]);
        }

        return [
            'base_cash_amount' => number_format($baseCashAmount, 2, '.', ''),
            'selected_unit_price' => number_format($selectedUnit, 6, '.', ''),
            'credit_premium_amount' => number_format($premium, 2, '.', ''),
            'line_total' => number_format($lineTotal, 2, '.', ''),
        ];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array{subtotal_cash_amount:string,credit_premium_total:string,grand_total:string,lines:list<array<string,mixed>>}
     */
    public function calculateBill(string $paymentTerms, array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => ['At least one line is required.']]);
        }

        $subtotalCash = 0.0;
        $premiumTotal = 0.0;
        $grand = 0.0;
        $outLines = [];

        foreach ($lines as $idx => $line) {
            $calc = $this->calculateLine($paymentTerms, $line);
            $subtotalCash += (float) $calc['base_cash_amount'];
            $premiumTotal += (float) $calc['credit_premium_amount'];
            $grand += (float) $calc['line_total'];
            $outLines[] = array_merge($line, $calc, [
                'line_no' => (int) ($line['line_no'] ?? ($idx + 1)),
            ]);
        }

        return [
            'subtotal_cash_amount' => number_format(round($subtotalCash, 2), 2, '.', ''),
            'credit_premium_total' => number_format(round($premiumTotal, 2), 2, '.', ''),
            'grand_total' => number_format(round($grand, 2), 2, '.', ''),
            'lines' => $outLines,
        ];
    }
}


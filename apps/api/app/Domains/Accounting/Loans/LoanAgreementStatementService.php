<?php

namespace App\Domains\Accounting\Loans;

use App\Support\TenantScoped;
use App\Models\PostingGroup;
use Carbon\Carbon;

/**
 * Liability-focused loan statement: posted drawdowns increase balance; repayment principal decreases it.
 * Interest does not affect liability. Dates use posting_groups.posting_date.
 */
class LoanAgreementStatementService
{
    /**
     * @return array{
     *   loan_agreement_id: string,
     *   currency_code: string,
     *   from: string|null,
     *   to: string|null,
     *   opening_balance: string,
     *   closing_balance: string,
     *   drawdowns: list<array<string, mixed>>,
     *   repayments: list<array<string, mixed>>,
     *   lines: list<array<string, mixed>>
     * }
     */
    public function build(string $agreementId, string $tenantId, ?string $from = null, ?string $to = null): array
    {
        $agreement = TenantScoped::for(LoanAgreement::query(), $tenantId)->findOrFail($agreementId);

        $currency = $agreement->currency_code ?? 'GBP';

        $drawdownRows = TenantScoped::for(LoanDrawdown::query(), $tenantId)
            ->where('loan_agreement_id', $agreementId)
            ->where('status', LoanDrawdown::STATUS_POSTED)
            ->whereNotNull('posting_group_id')
            ->with('postingGroup')
            ->get()
            ->filter(fn (LoanDrawdown $d) => $d->postingGroup !== null);

        $repaymentRows = TenantScoped::for(LoanRepayment::query(), $tenantId)
            ->where('loan_agreement_id', $agreementId)
            ->where('status', LoanRepayment::STATUS_POSTED)
            ->whereNotNull('posting_group_id')
            ->with('postingGroup')
            ->get()
            ->filter(fn (LoanRepayment $r) => $r->postingGroup !== null);

        $fromStr = $from ? Carbon::parse($from)->format('Y-m-d') : null;
        $toStr = $to ? Carbon::parse($to)->format('Y-m-d') : null;

        $opening = 0.0;
        if ($fromStr !== null) {
            foreach ($drawdownRows as $d) {
                $pd = $this->postingDateStr($d->postingGroup);
                if ($pd < $fromStr) {
                    $opening += (float) $d->amount;
                }
            }
            foreach ($repaymentRows as $r) {
                $pd = $this->postingDateStr($r->postingGroup);
                if ($pd < $fromStr) {
                    $opening -= $this->principalPortion($r);
                }
            }
        }

        $drawdownsOut = [];
        foreach ($drawdownRows as $d) {
            $pd = $this->postingDateStr($d->postingGroup);
            if (! $this->inInclusiveWindow($pd, $fromStr, $toStr)) {
                continue;
            }
            $drawdownsOut[] = [
                'id' => $d->id,
                'drawdown_date' => $d->drawdown_date?->format('Y-m-d'),
                'posting_date' => $pd,
                'amount' => $this->moneyStr($d->amount),
                'reference_no' => $d->reference_no,
                'posting_group_id' => $d->posting_group_id,
            ];
        }

        $repaymentsOut = [];
        foreach ($repaymentRows as $r) {
            $pd = $this->postingDateStr($r->postingGroup);
            if (! $this->inInclusiveWindow($pd, $fromStr, $toStr)) {
                continue;
            }
            $principal = $this->principalPortion($r);
            $interest = round(max(0.0, (float) $r->amount - $principal), 2);
            $repaymentsOut[] = [
                'id' => $r->id,
                'repayment_date' => $r->repayment_date?->format('Y-m-d'),
                'posting_date' => $pd,
                'amount' => $this->moneyStr($r->amount),
                'principal_amount' => $this->moneyStr($principal),
                'interest_amount' => $this->moneyStr($interest),
                'reference_no' => $r->reference_no,
                'posting_group_id' => $r->posting_group_id,
            ];
        }

        $periodDd = 0.0;
        foreach ($drawdownRows as $d) {
            $pd = $this->postingDateStr($d->postingGroup);
            if ($this->inInclusiveWindow($pd, $fromStr, $toStr)) {
                $periodDd += (float) $d->amount;
            }
        }
        $periodPr = 0.0;
        foreach ($repaymentRows as $r) {
            $pd = $this->postingDateStr($r->postingGroup);
            if ($this->inInclusiveWindow($pd, $fromStr, $toStr)) {
                $periodPr += $this->principalPortion($r);
            }
        }

        if ($fromStr !== null) {
            $closing = $opening + $periodDd - $periodPr;
            $openingOut = $opening;
        } else {
            $openingOut = 0.0;
            $closing = $periodDd - $periodPr;
        }

        $lines = $this->mergeLines($drawdownsOut, $repaymentsOut, $fromStr, $openingOut);

        return [
            'loan_agreement_id' => $agreement->id,
            'currency_code' => $currency,
            'from' => $fromStr,
            'to' => $toStr,
            'opening_balance' => $this->moneyStr($openingOut),
            'closing_balance' => $this->moneyStr($closing),
            'drawdowns' => array_values($drawdownsOut),
            'repayments' => array_values($repaymentsOut),
            'lines' => $lines,
        ];
    }

    private function postingDateStr(?PostingGroup $pg): string
    {
        if (! $pg || ! $pg->posting_date) {
            return '1970-01-01';
        }

        return $pg->posting_date instanceof \DateTimeInterface
            ? $pg->posting_date->format('Y-m-d')
            : (string) $pg->posting_date;
    }

    private function principalPortion(LoanRepayment $r): float
    {
        $total = (float) $r->amount;
        if ($r->principal_amount !== null && $r->interest_amount !== null) {
            return round((float) $r->principal_amount, 2);
        }
        if ($r->principal_amount !== null) {
            return round((float) $r->principal_amount, 2);
        }
        if ($r->interest_amount !== null) {
            return round($total - (float) $r->interest_amount, 2);
        }

        return $total;
    }

    /**
     * Inclusive on both ends when from/to set; if from null, no lower bound; if to null, no upper bound.
     */
    private function inInclusiveWindow(string $postingDate, ?string $fromStr, ?string $toStr): bool
    {
        if ($fromStr !== null && $postingDate < $fromStr) {
            return false;
        }
        if ($toStr !== null && $postingDate > $toStr) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $drawdownsOut
     * @param  list<array<string, mixed>>  $repaymentsOut
     * @return list<array<string, mixed>>
     */
    private function mergeLines(array $drawdownsOut, array $repaymentsOut, ?string $fromStr, float $opening): array
    {
        $items = [];
        foreach ($drawdownsOut as $row) {
            $items[] = [
                'kind' => 'DRAWDOWN',
                'id' => $row['id'],
                'date' => $row['posting_date'],
                'amount' => $row['amount'],
                'principal' => null,
                'interest' => null,
                'reference_no' => $row['reference_no'],
            ];
        }
        foreach ($repaymentsOut as $row) {
            $items[] = [
                'kind' => 'REPAYMENT',
                'id' => $row['id'],
                'date' => $row['posting_date'],
                'amount' => $row['amount'],
                'principal' => $row['principal_amount'],
                'interest' => $row['interest_amount'],
                'reference_no' => $row['reference_no'],
            ];
        }

        usort($items, function ($a, $b) {
            $cmp = strcmp($a['date'], $b['date']);
            if ($cmp !== 0) {
                return $cmp;
            }
            if ($a['kind'] !== $b['kind']) {
                return $a['kind'] === 'DRAWDOWN' ? -1 : 1;
            }

            return strcmp((string) $a['id'], (string) $b['id']);
        });

        $balance = $fromStr !== null ? $opening : 0.0;
        $lines = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'DRAWDOWN') {
                $balance += (float) $item['amount'];
            } else {
                $balance -= (float) $item['principal'];
            }
            $lines[] = array_merge($item, [
                'balance_after' => $this->moneyStr($balance),
            ]);
        }

        return $lines;
    }

    private function moneyStr(float|string $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }
}

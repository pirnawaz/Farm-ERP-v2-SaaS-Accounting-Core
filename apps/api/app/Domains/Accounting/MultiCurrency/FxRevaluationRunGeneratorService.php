<?php

namespace App\Domains\Accounting\MultiCurrency;

use App\Domains\Accounting\Loans\LoanAgreement;
use App\Domains\Accounting\Loans\LoanRepayment;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Models\LedgerEntry;
use App\Support\TenantScoped;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Builds DRAFT FX revaluation lines from open monetary balances (no GL impact until post).
 */
final class FxRevaluationRunGeneratorService
{
    public function __construct(
        private FxRateResolver $fxRateResolver,
    ) {}

    public function generate(string $tenantId, string $asOfDate): FxRevaluationRun
    {
        $asOf = Carbon::parse($asOfDate)->format('Y-m-d');

        return DB::transaction(function () use ($tenantId, $asOf) {
            $run = FxRevaluationRun::create([
                'tenant_id' => $tenantId,
                'reference_no' => $this->nextReferenceNo($tenantId),
                'status' => FxRevaluationRun::STATUS_DRAFT,
                'as_of_date' => $asOf,
            ]);

            $this->rebuildLines($run, $tenantId, $asOf);

            return $run->fresh(['lines']);
        });
    }

    /**
     * Replaces all lines for a DRAFT run from current subledgers and rates.
     */
    public function refreshDraftLines(string $runId, string $tenantId): FxRevaluationRun
    {
        return DB::transaction(function () use ($runId, $tenantId) {
            /** @var FxRevaluationRun $run */
            $run = TenantScoped::for(FxRevaluationRun::query(), $tenantId)
                ->lockForUpdate()
                ->findOrFail($runId);

            if ($run->status !== FxRevaluationRun::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ['Only a DRAFT run can be refreshed.'],
                ]);
            }

            $asOf = Carbon::parse($run->as_of_date)->format('Y-m-d');
            $this->rebuildLines($run, $tenantId, $asOf);

            return $run->fresh(['lines']);
        });
    }

    private function rebuildLines(FxRevaluationRun $run, string $tenantId, string $asOfDate): void
    {
        FxRevaluationLine::query()
            ->where('fx_revaluation_run_id', $run->id)
            ->delete();

        $base = $this->fxRateResolver->tenantBaseCurrencyCode($tenantId);
        if ($base === null) {
            throw ValidationException::withMessages([
                'tenant' => ['Tenant has no functional currency (currency_code).'],
            ]);
        }
        $base = strtoupper($base);

        $loanPayableId = (string) DB::table('accounts')
            ->where('tenant_id', $tenantId)
            ->where('code', 'LOAN_PAYABLE')
            ->value('id');
        $apAccountId = (string) DB::table('accounts')
            ->where('tenant_id', $tenantId)
            ->where('code', 'AP')
            ->value('id');

        if ($loanPayableId === '' || $apAccountId === '') {
            throw ValidationException::withMessages([
                'accounts' => ['Required system accounts AP and LOAN_PAYABLE must exist for this tenant.'],
            ]);
        }

        $this->appendSupplierApLines($run, $tenantId, $asOfDate, $base, $apAccountId);
        $this->appendLoanLines($run, $tenantId, $asOfDate, $base, $loanPayableId);
    }

    private function appendSupplierApLines(
        FxRevaluationRun $run,
        string $tenantId,
        string $asOfDate,
        string $baseCurrency,
        string $apAccountId
    ): void {
        $invoices = TenantScoped::for(SupplierInvoice::query(), $tenantId)
            ->whereNotNull('posting_group_id')
            ->whereIn('status', [SupplierInvoice::STATUS_POSTED, SupplierInvoice::STATUS_PAID])
            ->whereHas('postingGroup', fn ($q) => $q->whereDate('posting_date', '<=', $asOfDate))
            ->with('postingGroup')
            ->orderBy('id')
            ->get();

        /** @var array<string, array{orig: float, reval: float, party_id: string, currency: string}> $buckets */
        $buckets = [];

        foreach ($invoices as $invoice) {
            $cc = strtoupper((string) $invoice->currency_code);
            if ($cc === $baseCurrency) {
                continue;
            }

            $rate = $this->fxRateResolver->rateForPostingDate($tenantId, $asOfDate, $baseCurrency, $cc);
            if ($rate === null) {
                throw ValidationException::withMessages([
                    'exchange_rate' => ["No exchange rate for {$baseCurrency}/{$cc} on or before {$asOfDate}."],
                ]);
            }

            $invFc = round((float) $invoice->total_amount, 2);
            if ($invFc <= 0) {
                continue;
            }

            $paidFc = (float) DB::table('supplier_payment_allocations')
                ->where('tenant_id', $tenantId)
                ->where('supplier_invoice_id', $invoice->id)
                ->where(function ($q) {
                    $q->where('status', 'ACTIVE')->orWhereNull('status');
                })
                ->whereDate('allocation_date', '<=', $asOfDate)
                ->sum('amount');

            $openFc = round($invFc - $paidFc, 2);
            if ($openFc <= 0.004) {
                continue;
            }

            $apLine = LedgerEntry::query()
                ->where('posting_group_id', $invoice->posting_group_id)
                ->where('account_id', $apAccountId)
                ->where('credit_amount', '>', 0)
                ->orderByDesc('credit_amount')
                ->first();

            if ($apLine === null) {
                continue;
            }

            $invBase = (float) ($apLine->credit_amount_base ?? $apLine->credit_amount);
            $origPart = round($invBase * ($openFc / $invFc), 2);
            $revalPart = round($openFc * (float) $rate, 2);

            $key = $invoice->party_id.'|'.$cc;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'party_id' => (string) $invoice->party_id,
                    'currency' => $cc,
                    'orig' => 0.0,
                    'reval' => 0.0,
                ];
            }
            $buckets[$key]['orig'] = round($buckets[$key]['orig'] + $origPart, 2);
            $buckets[$key]['reval'] = round($buckets[$key]['reval'] + $revalPart, 2);
        }

        foreach ($buckets as $b) {
            $delta = round($b['reval'] - $b['orig'], 2);
            if (abs($delta) < 0.005) {
                continue;
            }

            FxRevaluationLine::create([
                'tenant_id' => $tenantId,
                'fx_revaluation_run_id' => $run->id,
                'source_type' => FxRevaluationLine::SOURCE_SUPPLIER_AP,
                'source_id' => $b['party_id'],
                'currency_code' => $b['currency'],
                'original_base_amount' => (string) $b['orig'],
                'revalued_base_amount' => (string) $b['reval'],
                'delta_amount' => (string) $delta,
            ]);
        }
    }

    private function appendLoanLines(
        FxRevaluationRun $run,
        string $tenantId,
        string $asOfDate,
        string $baseCurrency,
        string $loanPayableAccountId
    ): void {
        $agreements = TenantScoped::for(LoanAgreement::query(), $tenantId)
            ->whereIn('status', [LoanAgreement::STATUS_ACTIVE, LoanAgreement::STATUS_POSTED])
            ->orderBy('reference_no')
            ->get();

        foreach ($agreements as $agreement) {
            $cc = strtoupper((string) ($agreement->currency_code ?? $baseCurrency));
            if ($cc === $baseCurrency) {
                continue;
            }

            $rate = $this->fxRateResolver->rateForPostingDate($tenantId, $asOfDate, $baseCurrency, $cc);
            if ($rate === null) {
                throw ValidationException::withMessages([
                    'exchange_rate' => ["No exchange rate for {$baseCurrency}/{$cc} on or before {$asOfDate}."],
                ]);
            }

            $drawnFc = (float) DB::table('loan_drawdowns as ld')
                ->join('posting_groups as pg', 'pg.id', '=', 'ld.posting_group_id')
                ->where('ld.tenant_id', $tenantId)
                ->where('ld.loan_agreement_id', $agreement->id)
                ->whereNotNull('ld.posting_group_id')
                ->whereDate('pg.posting_date', '<=', $asOfDate)
                ->sum('ld.amount');

            $repaidFc = $this->sumPrincipalRepaid($tenantId, $agreement->id, $asOfDate);

            $openFc = round($drawnFc - $repaidFc, 2);
            if ($openFc <= 0.004) {
                continue;
            }

            $origBase = $this->loanPayableLedgerBase(
                $tenantId,
                $loanPayableAccountId,
                $agreement->id,
                $asOfDate
            );

            $revalBase = round($openFc * (float) $rate, 2);
            $delta = round($revalBase - $origBase, 2);
            if (abs($delta) < 0.005) {
                continue;
            }

            FxRevaluationLine::create([
                'tenant_id' => $tenantId,
                'fx_revaluation_run_id' => $run->id,
                'source_type' => FxRevaluationLine::SOURCE_LOAN_PAYABLE,
                'source_id' => $agreement->id,
                'currency_code' => $cc,
                'original_base_amount' => (string) round($origBase, 2),
                'revalued_base_amount' => (string) $revalBase,
                'delta_amount' => (string) $delta,
            ]);
        }
    }

    private function sumPrincipalRepaid(string $tenantId, string $agreementId, string $asOfDate): float
    {
        $rows = LoanRepayment::query()
            ->where('tenant_id', $tenantId)
            ->where('loan_agreement_id', $agreementId)
            ->whereNotNull('posting_group_id')
            ->join('posting_groups as pg', 'pg.id', '=', 'loan_repayments.posting_group_id')
            ->whereDate('pg.posting_date', '<=', $asOfDate)
            ->select('loan_repayments.*')
            ->get();

        $sum = 0.0;
        foreach ($rows as $r) {
            $sum += $this->repaymentPrincipal($r);
        }

        return round($sum, 2);
    }

    private function repaymentPrincipal(LoanRepayment $r): float
    {
        if ($r->principal_amount !== null) {
            return round((float) $r->principal_amount, 2);
        }
        $total = (float) $r->amount;
        $interest = $r->interest_amount !== null ? (float) $r->interest_amount : 0.0;

        return round(max(0.0, $total - $interest), 2);
    }

    private function loanPayableLedgerBase(
        string $tenantId,
        string $loanPayableAccountId,
        string $loanAgreementId,
        string $asOfDate
    ): float {
        $row = DB::selectOne(
            '
            SELECT COALESCE(SUM(
                COALESCE(le.credit_amount_base, le.credit_amount, 0)::numeric
                - COALESCE(le.debit_amount_base, le.debit_amount, 0)::numeric
            ), 0) AS bal
            FROM ledger_entries le
            INNER JOIN posting_groups pg ON pg.id = le.posting_group_id AND pg.tenant_id = le.tenant_id
            WHERE le.tenant_id = ?
              AND le.account_id = ?
              AND pg.posting_date::date <= ?::date
              AND (
                (pg.source_type::text = \'LOAN_DRAWDOWN\' AND EXISTS (
                  SELECT 1 FROM loan_drawdowns ld
                  WHERE ld.id = pg.source_id AND ld.tenant_id = le.tenant_id AND ld.loan_agreement_id = ?
                ))
                OR
                (pg.source_type::text = \'LOAN_REPAYMENT\' AND EXISTS (
                  SELECT 1 FROM loan_repayments lr
                  WHERE lr.id = pg.source_id AND lr.tenant_id = le.tenant_id AND lr.loan_agreement_id = ?
                ))
              )
            ',
            [$tenantId, $loanPayableAccountId, $asOfDate, $loanAgreementId, $loanAgreementId]
        );

        return round((float) ($row->bal ?? 0), 2);
    }

    private function nextReferenceNo(string $tenantId): string
    {
        for ($i = 0; $i < 10; $i++) {
            $candidate = 'FXR-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
            $exists = FxRevaluationRun::query()
                ->where('tenant_id', $tenantId)
                ->where('reference_no', $candidate)
                ->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return 'FXR-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}

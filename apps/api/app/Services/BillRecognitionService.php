<?php

namespace App\Services;

use App\Domains\Accounting\MultiCurrency\PostingFxService;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Models\AllocationRow;
use App\Models\BillRecognitionSchedule;
use App\Models\BillRecognitionScheduleLine;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\Accounting\PostValidationService;
use App\Support\TenantScoped;
use App\Services\LedgerWriteGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bill-linked PREPAID schedules: deferral posting moves expense to prepaid asset; recognition posts expense over periods.
 */
class BillRecognitionService
{
    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency,
        private PostingFxService $postingFx
    ) {}

    public function createSchedule(
        string $tenantId,
        string $supplierInvoiceId,
        string $treatment,
        string $startDate,
        string $endDate,
        float $totalAmount
    ): BillRecognitionSchedule {
        if ($treatment !== 'PREPAID') {
            throw ValidationException::withMessages([
                'treatment' => ['Only PREPAID is supported in this phase.'],
            ]);
        }

        return DB::transaction(function () use ($tenantId, $supplierInvoiceId, $treatment, $startDate, $endDate, $totalAmount) {
            $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)->findOrFail($supplierInvoiceId);
            if ($invoice->status !== SupplierInvoice::STATUS_POSTED || ! $invoice->posting_group_id) {
                throw ValidationException::withMessages([
                    'supplier_invoice_id' => ['Invoice must be posted before creating a recognition schedule.'],
                ]);
            }
            $hasOpen = BillRecognitionSchedule::query()
                ->where('tenant_id', $tenantId)
                ->where('supplier_invoice_id', $supplierInvoiceId)
                ->whereIn('status', [BillRecognitionSchedule::STATUS_DRAFT, BillRecognitionSchedule::STATUS_DEFERRAL_POSTED])
                ->exists();
            if ($hasOpen) {
                throw ValidationException::withMessages([
                    'supplier_invoice_id' => ['An open schedule already exists for this bill. Complete or remove it first.'],
                ]);
            }

            $expenseNet = $this->expenseNetForPostingGroup($tenantId, (string) $invoice->posting_group_id);
            if (abs($expenseNet - $totalAmount) > 0.02) {
                throw ValidationException::withMessages([
                    'total_amount' => ['Total must match posted expense on this bill ('.round($expenseNet, 2).').'],
                ]);
            }

            $hasProject = (bool) $invoice->project_id;
            $hasCc = (bool) $invoice->cost_center_id;
            if ($hasProject === $hasCc) {
                throw ValidationException::withMessages([
                    'supplier_invoice_id' => ['Bill must be either project-scoped or cost-center overhead (not both, not neither).'],
                ]);
            }

            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
            if ($end->lt($start)) {
                throw ValidationException::withMessages(['end_date' => ['End date must be on or after start date.']]);
            }

            $schedule = BillRecognitionSchedule::create([
                'tenant_id' => $tenantId,
                'supplier_invoice_id' => $supplierInvoiceId,
                'treatment' => $treatment,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'frequency' => 'MONTHLY',
                'total_amount' => $totalAmount,
                'status' => BillRecognitionSchedule::STATUS_DRAFT,
            ]);

            $this->generateMonthlyLines($schedule, $tenantId, $totalAmount, $start, $end);

            return $schedule->load('lines');
        });
    }

    private function generateMonthlyLines(
        BillRecognitionSchedule $schedule,
        string $tenantId,
        float $totalAmount,
        Carbon $start,
        Carbon $end
    ): void {
        $months = [];
        $cur = $start->copy()->startOfMonth();
        $lastMonth = $end->copy()->startOfMonth();
        while ($cur->lte($lastMonth)) {
            $pStart = $cur->copy()->startOfMonth();
            $pEnd = $cur->copy()->endOfMonth();
            $months[] = ['start' => $pStart, 'end' => $pEnd];
            $cur->addMonth();
        }
        if ($months === []) {
            throw ValidationException::withMessages(['dates' => ['No recognition periods in the selected date range.']]);
        }

        $n = count($months);
        $each = round($totalAmount / $n, 2);
        $allocated = 0.0;
        foreach ($months as $i => $m) {
            $amt = ($i === $n - 1) ? round($totalAmount - $allocated, 2) : $each;
            $allocated += $amt;
            BillRecognitionScheduleLine::create([
                'tenant_id' => $tenantId,
                'bill_recognition_schedule_id' => $schedule->id,
                'period_no' => $i + 1,
                'period_start' => $m['start']->toDateString(),
                'period_end' => $m['end']->toDateString(),
                'amount' => $amt,
                'recognition_due_date' => $m['end']->toDateString(),
                'status' => BillRecognitionScheduleLine::STATUS_PENDING,
            ]);
        }
    }

    public function postDeferral(BillRecognitionSchedule $schedule, string $postingDateYmd, ?string $idempotencyKey = null): PostingGroup
    {
        if ($schedule->status !== BillRecognitionSchedule::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => ['Deferral already posted or schedule completed.']]);
        }

        $tenantId = $schedule->tenant_id;
        $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)
            ->with(['lines'])
            ->findOrFail($schedule->supplier_invoice_id);

        return LedgerWriteGuard::scoped(self::class, function () use ($schedule, $tenantId, $invoice, $postingDateYmd, $idempotencyKey) {
            return DB::transaction(function () use ($schedule, $tenantId, $invoice, $postingDateYmd, $idempotencyKey) {
                $resolved = $this->postingIdempotency->resolveOrCreate(
                    $tenantId,
                    $idempotencyKey,
                    'BILL_RECOGNITION_DEFERRAL',
                    $schedule->id
                );
                if ($resolved['posting_group'] !== null) {
                    $pg = $resolved['posting_group'];
                    if (! $schedule->deferral_posting_group_id) {
                        $schedule->update(['deferral_posting_group_id' => $pg->id, 'status' => BillRecognitionSchedule::STATUS_DEFERRAL_POSTED]);
                    }

                    return $pg->load(['ledgerEntries.account', 'allocationRows']);
                }
                $effectiveKey = $resolved['effective_key'];

                $postingDateObj = Carbon::parse($postingDateYmd)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateObj));

                if ($invoice->project_id) {
                    $this->operationalPostingGuard->ensureCropCycleOpenForProject((string) $invoice->project_id, $tenantId);
                }

                $total = round((float) $schedule->total_amount, 2);
                $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
                $currencyCode = strtoupper((string) ($tenant->currency_code ?? 'GBP'));
                $fx = $this->postingFx->forPosting($tenantId, $postingDateObj, $currencyCode);

                $prepaid = $this->accountService->getByCode($tenantId, 'PREPAID_EXPENSE');
                $expense = $this->accountService->getByCode($tenantId, 'INPUTS_EXPENSE');

                $ledgerLines = [
                    ['account_id' => $prepaid->id, 'debit_amount' => $total, 'credit_amount' => 0],
                    ['account_id' => $expense->id, 'debit_amount' => 0, 'credit_amount' => $total],
                ];
                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

                $cropCycleId = null;
                if ($invoice->project_id) {
                    $cropCycleId = Project::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $invoice->project_id)
                        ->value('crop_cycle_id');
                }

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $cropCycleId,
                    'source_type' => 'BILL_RECOGNITION_DEFERRAL',
                    'source_id' => $schedule->id,
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

                $this->createDeferralAllocationRows($tenantId, $postingGroup->id, $invoice, $fx, $total);

                $schedule->update([
                    'deferral_posting_group_id' => $postingGroup->id,
                    'status' => BillRecognitionSchedule::STATUS_DEFERRAL_POSTED,
                ]);

                return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }

    private function createDeferralAllocationRows(
        string $tenantId,
        string $postingGroupId,
        SupplierInvoice $invoice,
        \App\Domains\Accounting\MultiCurrency\PostingFxSnapshot $fx,
        float $total
    ): void {
        $arTemplate = AllocationRow::query()
            ->where('tenant_id', $tenantId)
            ->where('posting_group_id', $invoice->posting_group_id)
            ->orderBy('id')
            ->firstOrFail();

        AllocationRow::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $postingGroupId,
            'project_id' => $arTemplate->project_id,
            'cost_center_id' => $arTemplate->cost_center_id,
            'party_id' => $arTemplate->party_id,
            'allocation_type' => 'BILL_RECOGNITION',
            'amount' => (string) $total,
            'currency_code' => $fx->transactionCurrencyCode,
            'base_currency_code' => $fx->baseCurrencyCode,
            'fx_rate' => $fx->fxRate,
            'amount_base' => $fx->amountInBase($total),
            'rule_snapshot' => [
                'kind' => 'bill_recognition_deferral',
                'supplier_invoice_id' => $invoice->id,
            ],
        ]);
    }

    public function postRecognitionLine(
        BillRecognitionScheduleLine $line,
        string $postingDateYmd,
        ?string $idempotencyKey = null
    ): PostingGroup {
        $schedule = $line->schedule;
        if ($schedule->status !== BillRecognitionSchedule::STATUS_DEFERRAL_POSTED) {
            throw ValidationException::withMessages([
                'schedule' => ['Post deferral before recognizing periods.'],
            ]);
        }
        if ($line->status === BillRecognitionScheduleLine::STATUS_POSTED) {
            throw ValidationException::withMessages(['line' => ['This period was already recognized.']]);
        }

        $tenantId = $line->tenant_id;

        return LedgerWriteGuard::scoped(self::class, function () use ($line, $schedule, $tenantId, $postingDateYmd, $idempotencyKey) {
            return DB::transaction(function () use ($line, $schedule, $tenantId, $postingDateYmd, $idempotencyKey) {
                $resolved = $this->postingIdempotency->resolveOrCreate(
                    $tenantId,
                    $idempotencyKey,
                    'BILL_RECOGNITION',
                    $line->id
                );
                if ($resolved['posting_group'] !== null) {
                    $pg = $resolved['posting_group'];
                    if (! $line->recognition_posting_group_id) {
                        $line->update([
                            'recognition_posting_group_id' => $pg->id,
                            'status' => BillRecognitionScheduleLine::STATUS_POSTED,
                        ]);
                        $this->refreshScheduleStatus($schedule);
                    }

                    return $pg->load(['ledgerEntries.account', 'allocationRows']);
                }
                $effectiveKey = $resolved['effective_key'];

                $postingDateObj = Carbon::parse($postingDateYmd)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateObj));

                $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)
                    ->with(['lines'])
                    ->findOrFail($schedule->supplier_invoice_id);

                if ($invoice->project_id) {
                    $this->operationalPostingGuard->ensureCropCycleOpenForProject((string) $invoice->project_id, $tenantId);
                }

                $amt = round((float) $line->amount, 2);
                if ($amt <= 0) {
                    throw ValidationException::withMessages(['amount' => ['Invalid line amount.']]);
                }

                $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
                $currencyCode = strtoupper((string) ($tenant->currency_code ?? 'GBP'));
                $fx = $this->postingFx->forPosting($tenantId, $postingDateObj, $currencyCode);

                $prepaid = $this->accountService->getByCode($tenantId, 'PREPAID_EXPENSE');
                $expense = $this->accountService->getByCode($tenantId, 'INPUTS_EXPENSE');

                $ledgerLines = [
                    ['account_id' => $expense->id, 'debit_amount' => $amt, 'credit_amount' => 0],
                    ['account_id' => $prepaid->id, 'debit_amount' => 0, 'credit_amount' => $amt],
                ];
                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

                $cropCycleId = null;
                if ($invoice->project_id) {
                    $cropCycleId = Project::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $invoice->project_id)
                        ->value('crop_cycle_id');
                }

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $cropCycleId,
                    'source_type' => 'BILL_RECOGNITION',
                    'source_id' => $line->id,
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

                $arTemplate = AllocationRow::query()
                    ->where('tenant_id', $tenantId)
                    ->where('posting_group_id', $invoice->posting_group_id)
                    ->orderBy('id')
                    ->firstOrFail();

                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $arTemplate->project_id,
                    'cost_center_id' => $arTemplate->cost_center_id,
                    'party_id' => $arTemplate->party_id,
                    'allocation_type' => 'BILL_RECOGNITION',
                    'amount' => (string) $amt,
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                    'amount_base' => $fx->amountInBase($amt),
                    'rule_snapshot' => [
                        'kind' => 'bill_recognition_period',
                        'schedule_line_id' => $line->id,
                        'supplier_invoice_id' => $invoice->id,
                    ],
                ]);

                $line->update([
                    'recognition_posting_group_id' => $postingGroup->id,
                    'status' => BillRecognitionScheduleLine::STATUS_POSTED,
                ]);

                $this->refreshScheduleStatus($schedule->fresh());

                return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }

    private function refreshScheduleStatus(BillRecognitionSchedule $schedule): void
    {
        $pending = $schedule->lines()->where('status', BillRecognitionScheduleLine::STATUS_PENDING)->count();
        if ($pending === 0) {
            $schedule->update(['status' => BillRecognitionSchedule::STATUS_COMPLETED]);
        }
    }

    private function expenseNetForPostingGroup(string $tenantId, string $postingGroupId): float
    {
        $row = DB::selectOne(
            'SELECT COALESCE(SUM(le.debit_amount - le.credit_amount), 0) AS net
             FROM ledger_entries le
             INNER JOIN accounts a ON a.id = le.account_id AND a.tenant_id = le.tenant_id
             WHERE le.tenant_id = ? AND le.posting_group_id = ? AND a.type = ?',
            [$tenantId, $postingGroupId, 'expense']
        );

        return round(max(0, (float) ($row->net ?? 0)), 2);
    }
}

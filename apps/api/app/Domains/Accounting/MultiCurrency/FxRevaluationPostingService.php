<?php

namespace App\Domains\Accounting\MultiCurrency;

use App\Models\LedgerEntry;
use App\Models\PostingGroup;
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
 * Posts a single FX revaluation run: one posting group; Dr/Cr unrealized FX P&L vs AP / loans payable.
 */
final class FxRevaluationPostingService
{
    private const SOURCE_TYPE = 'FX_REVALUATION_RUN';

    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency,
    ) {}

    public function post(
        string $runId,
        string $tenantId,
        string $postingDate,
        ?string $idempotencyKey = null,
        ?string $postedByUserId = null
    ): PostingGroup {
        return LedgerWriteGuard::scoped(self::class, function () use ($runId, $tenantId, $postingDate, $idempotencyKey, $postedByUserId) {
            return DB::transaction(function () use ($runId, $tenantId, $postingDate, $idempotencyKey, $postedByUserId) {
                /** @var FxRevaluationRun $run */
                $run = TenantScoped::for(FxRevaluationRun::query(), $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($runId);

                if ($run->posting_group_id) {
                    $pg = TenantScoped::for(PostingGroup::query(), $tenantId)
                        ->where('id', $run->posting_group_id)
                        ->first();
                    if ($pg) {
                        return $pg->load(['ledgerEntries.account']);
                    }
                }

                $resolved = $this->postingIdempotency->resolveOrCreate($tenantId, $idempotencyKey, self::SOURCE_TYPE, $run->id);
                if ($resolved['posting_group'] !== null) {
                    $existingByKey = $resolved['posting_group'];
                    if ($run->status !== FxRevaluationRun::STATUS_POSTED || ! $run->posting_group_id) {
                        $run->update([
                            'status' => FxRevaluationRun::STATUS_POSTED,
                            'posting_group_id' => $existingByKey->id,
                            'posted_at' => $run->posted_at ?? now(),
                            'posted_by_user_id' => $run->posted_by_user_id ?? $postedByUserId,
                            'posting_date' => $run->posting_date ?? Carbon::parse($postingDate)->format('Y-m-d'),
                        ]);
                    }

                    return $existingByKey->load(['ledgerEntries.account']);
                }
                $effectiveKey = $resolved['effective_key'];

                if ($run->status !== FxRevaluationRun::STATUS_DRAFT) {
                    throw ValidationException::withMessages([
                        'status' => ['Only a DRAFT FX revaluation run can be posted.'],
                    ]);
                }

                $run->load('lines');
                if ($run->lines->isEmpty()) {
                    throw ValidationException::withMessages([
                        'lines' => ['Nothing to post: run has no revaluation lines.'],
                    ]);
                }

                $postingDateStr = Carbon::parse($postingDate)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateStr));
                $this->operationalPostingGuard->ensureCropCycleOpenViaAnyOpenProject($tenantId);

                $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
                $baseCurrency = strtoupper((string) ($tenant->currency_code ?? 'GBP'));

                $fxLoss = $this->accountService->getByCode($tenantId, 'FX_UNREALIZED_LOSS');
                $fxGain = $this->accountService->getByCode($tenantId, 'FX_UNREALIZED_GAIN');
                $ap = $this->accountService->getByCode($tenantId, 'AP');
                $loanPayable = $this->accountService->getByCode($tenantId, 'LOAN_PAYABLE');

                $ledgerPlan = [];
                foreach ($run->lines as $line) {
                    $delta = round((float) $line->delta_amount, 2);
                    if (abs($delta) < 0.005) {
                        continue;
                    }

                    $bs = match ($line->source_type) {
                        FxRevaluationLine::SOURCE_SUPPLIER_AP => $ap,
                        FxRevaluationLine::SOURCE_LOAN_PAYABLE => $loanPayable,
                        default => throw ValidationException::withMessages([
                            'lines' => ['Unknown revaluation line source_type.'],
                        ]),
                    };

                    if ($delta > 0) {
                        // Liability increases: Dr FX loss, Cr AP / loan payable
                        $ledgerPlan[] = ['account_id' => $fxLoss->id, 'debit' => $delta, 'credit' => 0.0];
                        $ledgerPlan[] = ['account_id' => $bs->id, 'debit' => 0.0, 'credit' => $delta];
                    } else {
                        $amt = abs($delta);
                        $ledgerPlan[] = ['account_id' => $bs->id, 'debit' => $amt, 'credit' => 0.0];
                        $ledgerPlan[] = ['account_id' => $fxGain->id, 'debit' => 0.0, 'credit' => $amt];
                    }
                }

                if ($ledgerPlan === []) {
                    throw ValidationException::withMessages([
                        'lines' => ['Nothing to post: all line deltas round to zero.'],
                    ]);
                }

                $accountsForValidation = [];
                foreach ($ledgerPlan as $row) {
                    $accountsForValidation[] = ['account_id' => $row['account_id']];
                }
                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $accountsForValidation);

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => null,
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => $run->id,
                    'posting_date' => $postingDateStr,
                    'idempotency_key' => $effectiveKey,
                    'currency_code' => $baseCurrency,
                    'base_currency_code' => $baseCurrency,
                    'fx_rate' => 1,
                ]);

                foreach ($ledgerPlan as $row) {
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $postingGroup->id,
                        'account_id' => $row['account_id'],
                        'debit_amount' => $row['debit'],
                        'credit_amount' => $row['credit'],
                        'currency_code' => $baseCurrency,
                        'base_currency_code' => $baseCurrency,
                        'fx_rate' => 1,
                        'debit_amount_base' => $row['debit'],
                        'credit_amount_base' => $row['credit'],
                    ]);
                }

                $sumDr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('debit_amount');
                $sumCr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('credit_amount');
                if (abs($sumDr - $sumCr) > 0.02) {
                    throw new \RuntimeException('Debits and credits do not balance');
                }

                $run->update([
                    'status' => FxRevaluationRun::STATUS_POSTED,
                    'posting_date' => $postingDateStr,
                    'posted_at' => now(),
                    'posted_by_user_id' => $postedByUserId,
                    'posting_group_id' => $postingGroup->id,
                ]);

                return $postingGroup->fresh(['ledgerEntries.account']);
            });
        });
    }
}

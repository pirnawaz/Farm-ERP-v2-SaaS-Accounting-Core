<?php

namespace App\Domains\Accounting\Loans;

use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Project;
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
 * Posts a loan repayment: Dr LOAN_PAYABLE (principal), Dr LOAN_INTEREST_EXPENSE (interest), Cr CASH/BANK.
 * One posting group per repayment; idempotent by idempotency_key. Immutable once POSTED (no second post).
 */
class LoanRepaymentPostingService
{
    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency
    ) {}

    /**
     * @param  'CASH'|'BANK'  $fundingAccountCode
     */
    public function postRepayment(
        string $repaymentId,
        string $tenantId,
        string $postingDate,
        string $fundingAccountCode,
        ?string $idempotencyKey = null,
        ?string $userRole = null
    ): PostingGroup {
        if ($userRole && ! in_array($userRole, ['accountant', 'tenant_admin'], true)) {
            throw ValidationException::withMessages([
                'role' => ['Only accountant or tenant_admin can post loan repayments.'],
            ]);
        }

        if (! in_array($fundingAccountCode, ['CASH', 'BANK'], true)) {
            throw ValidationException::withMessages([
                'funding_account' => ['Funding account must be CASH or BANK.'],
            ]);
        }

        return LedgerWriteGuard::scoped(static::class, function () use ($repaymentId, $tenantId, $postingDate, $idempotencyKey, $fundingAccountCode) {
            return DB::transaction(function () use ($repaymentId, $tenantId, $postingDate, $idempotencyKey, $fundingAccountCode) {
            /** @var LoanRepayment $repayment */
            $repayment = TenantScoped::for(LoanRepayment::query(), $tenantId)
                ->lockForUpdate()
                ->findOrFail($repaymentId);

            if ($repayment->posting_group_id) {
                $pg = TenantScoped::for(PostingGroup::query(), $tenantId)
                    ->where('id', $repayment->posting_group_id)
                    ->first();
                if ($pg) {
                    return $pg->load(['ledgerEntries.account', 'allocationRows']);
                }
            }

            $resolved = $this->postingIdempotency->resolveOrCreate($tenantId, $idempotencyKey, 'LOAN_REPAYMENT', $repayment->id);
            if ($resolved['posting_group'] !== null) {
                $existingByKey = $resolved['posting_group'];
                if ($repayment->status !== LoanRepayment::STATUS_POSTED || ! $repayment->posting_group_id) {
                    $repayment->update([
                        'status' => LoanRepayment::STATUS_POSTED,
                        'posting_group_id' => $existingByKey->id,
                        'posted_at' => $repayment->posted_at ?? now(),
                    ]);
                }

                return $existingByKey->load(['ledgerEntries.account', 'allocationRows']);
            }
            $effectiveKey = $resolved['effective_key'];

            if (! in_array($repayment->status, [LoanRepayment::STATUS_DRAFT, LoanRepayment::STATUS_ACTIVE], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only repayments in DRAFT or ACTIVE status can be posted.'],
                ]);
            }

            $agreement = TenantScoped::for(LoanAgreement::query(), $tenantId)->findOrFail($repayment->loan_agreement_id);

            if ($agreement->status === LoanAgreement::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'loan_agreement' => ['Cannot post repayment for a closed loan agreement.'],
                ]);
            }

            if ($repayment->project_id !== $agreement->project_id) {
                throw ValidationException::withMessages([
                    'project_id' => ['Repayment project must match the loan agreement project.'],
                ]);
            }

            $total = (float) $repayment->amount;
            if ($total <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Repayment amount must be greater than zero.'],
                ]);
            }

            [$principal, $interest] = $this->resolvePrincipalAndInterest($repayment, $total);

            if ($principal < 0 || $interest < 0) {
                throw ValidationException::withMessages([
                    'principal_amount' => ['Principal and interest portions must be non-negative.'],
                ]);
            }

            if (abs(($principal + $interest) - $total) > 0.02) {
                throw ValidationException::withMessages([
                    'amount' => ['Amount must equal principal plus interest (within 0.01).'],
                ]);
            }

            $this->operationalPostingGuard->ensureCropCycleOpenForProject($repayment->project_id, $tenantId);

            $project = TenantScoped::for(Project::query(), $tenantId)->findOrFail($repayment->project_id);

            $cropCycleId = $project->crop_cycle_id;
            if (! $cropCycleId) {
                throw ValidationException::withMessages([
                    'project' => ['Project has no crop cycle.'],
                ]);
            }

            $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDate));

            $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
            $currencyCode = $agreement->currency_code ?? $tenant->currency_code ?? 'GBP';

            $loanPayable = $this->accountService->getByCode($tenantId, 'LOAN_PAYABLE');
            $interestExpense = $this->accountService->getByCode($tenantId, 'LOAN_INTEREST_EXPENSE');
            $creditAccount = $fundingAccountCode === 'BANK'
                ? $this->accountService->getByCode($tenantId, 'BANK')
                : $this->accountService->getByCode($tenantId, 'CASH');

            $accountsForValidation = [
                ['account_id' => $loanPayable->id],
                ['account_id' => $interestExpense->id],
                ['account_id' => $creditAccount->id],
            ];
            $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $accountsForValidation);

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $cropCycleId,
                'source_type' => 'LOAN_REPAYMENT',
                'source_id' => $repayment->id,
                'posting_date' => Carbon::parse($postingDate)->format('Y-m-d'),
                'idempotency_key' => $effectiveKey,
            ]);

            $this->addDebit($tenantId, $postingGroup->id, $loanPayable, $principal, $currencyCode);
            $this->addDebit($tenantId, $postingGroup->id, $interestExpense, $interest, $currencyCode);

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $creditAccount->id,
                'debit_amount' => 0,
                'credit_amount' => $total,
                'currency_code' => $currencyCode,
            ]);

            $sumDr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('debit_amount');
            $sumCr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('credit_amount');
            if (abs($sumDr - $sumCr) > 0.02) {
                throw new \RuntimeException('Debits and credits do not balance');
            }

            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $repayment->project_id,
                'party_id' => $agreement->lender_party_id,
                'allocation_type' => 'LOAN_REPAYMENT',
                'amount' => $total,
                'rule_snapshot' => [
                    'loan_agreement_id' => $agreement->id,
                    'loan_repayment_id' => $repayment->id,
                    'principal' => $principal,
                    'interest' => $interest,
                    'funding_account' => $fundingAccountCode,
                ],
            ]);

            $repayment->update([
                'status' => LoanRepayment::STATUS_POSTED,
                'posting_group_id' => $postingGroup->id,
                'posted_at' => now(),
                'principal_amount' => (string) round($principal, 2),
                'interest_amount' => (string) round($interest, 2),
            ]);

            return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function resolvePrincipalAndInterest(LoanRepayment $repayment, float $total): array
    {
        $p = $repayment->principal_amount;
        $i = $repayment->interest_amount;

        if ($p === null && $i === null) {
            return [$total, 0.0];
        }

        if ($p !== null && $i !== null) {
            return [(float) $p, (float) $i];
        }

        if ($p === null) {
            $interest = (float) $i;
            $principal = round($total - $interest, 2);
            if ($principal < 0) {
                throw ValidationException::withMessages([
                    'principal_amount' => ['Principal and interest portions are inconsistent with amount.'],
                ]);
            }

            return [$principal, $interest];
        }

        $principal = (float) $p;
        $interest = round($total - $principal, 2);
        if ($interest < 0) {
            throw ValidationException::withMessages([
                'interest_amount' => ['Principal and interest portions are inconsistent with amount.'],
            ]);
        }

        return [$principal, $interest];
    }

    private function addDebit(string $tenantId, string $postingGroupId, Account $account, float $amount, string $currencyCode): void
    {
        if ($amount <= 0) {
            return;
        }

        LedgerEntry::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $postingGroupId,
            'account_id' => $account->id,
            'debit_amount' => $amount,
            'credit_amount' => 0,
            'currency_code' => $currencyCode,
        ]);
    }
}

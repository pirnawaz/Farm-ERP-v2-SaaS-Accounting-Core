<?php

namespace App\Domains\Accounting\Loans;

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
 * Posts a loan drawdown: Dr Bank/Cash, Cr Loans Payable. One posting group per drawdown; idempotent by idempotency_key and source.
 */
class LoanDrawdownPostingService
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
    public function postDrawdown(
        string $drawdownId,
        string $tenantId,
        string $postingDate,
        string $fundingAccountCode,
        ?string $idempotencyKey = null,
        ?string $userRole = null
    ): PostingGroup {
        if ($userRole && ! in_array($userRole, ['accountant', 'tenant_admin'], true)) {
            throw ValidationException::withMessages([
                'role' => ['Only accountant or tenant_admin can post loan drawdowns.'],
            ]);
        }

        if (! in_array($fundingAccountCode, ['CASH', 'BANK'], true)) {
            throw ValidationException::withMessages([
                'funding_account' => ['Funding account must be CASH or BANK.'],
            ]);
        }

        return LedgerWriteGuard::scoped(static::class, function () use ($drawdownId, $tenantId, $postingDate, $idempotencyKey, $fundingAccountCode) {
            return DB::transaction(function () use ($drawdownId, $tenantId, $postingDate, $idempotencyKey, $fundingAccountCode) {
            /** @var LoanDrawdown $drawdown */
            $drawdown = TenantScoped::for(LoanDrawdown::query(), $tenantId)
                ->lockForUpdate()
                ->findOrFail($drawdownId);

            if ($drawdown->posting_group_id) {
                $pg = TenantScoped::for(PostingGroup::query(), $tenantId)
                    ->where('id', $drawdown->posting_group_id)
                    ->first();
                if ($pg) {
                    return $pg->load(['ledgerEntries.account', 'allocationRows']);
                }
            }

            $resolved = $this->postingIdempotency->resolveOrCreate($tenantId, $idempotencyKey, 'LOAN_DRAWDOWN', $drawdown->id);
            if ($resolved['posting_group'] !== null) {
                $existingByKey = $resolved['posting_group'];
                if ($drawdown->status !== LoanDrawdown::STATUS_POSTED || ! $drawdown->posting_group_id) {
                    $drawdown->update([
                        'status' => LoanDrawdown::STATUS_POSTED,
                        'posting_group_id' => $existingByKey->id,
                        'posted_at' => $drawdown->posted_at ?? now(),
                    ]);
                }

                return $existingByKey->load(['ledgerEntries.account', 'allocationRows']);
            }
            $effectiveKey = $resolved['effective_key'];

            if (! in_array($drawdown->status, [LoanDrawdown::STATUS_DRAFT, LoanDrawdown::STATUS_ACTIVE], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only drawdowns in DRAFT or ACTIVE status can be posted.'],
                ]);
            }

            $agreement = TenantScoped::for(LoanAgreement::query(), $tenantId)->findOrFail($drawdown->loan_agreement_id);

            if ($agreement->status === LoanAgreement::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'loan_agreement' => ['Cannot post drawdown for a closed loan agreement.'],
                ]);
            }

            if ($drawdown->project_id !== $agreement->project_id) {
                throw ValidationException::withMessages([
                    'project_id' => ['Drawdown project must match the loan agreement project.'],
                ]);
            }

            if ((float) $drawdown->amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Drawdown amount must be greater than zero.'],
                ]);
            }

            $this->operationalPostingGuard->ensureCropCycleOpenForProject($drawdown->project_id, $tenantId);

            $project = TenantScoped::for(Project::query(), $tenantId)->findOrFail($drawdown->project_id);

            $cropCycleId = $project->crop_cycle_id;
            if (! $cropCycleId) {
                throw ValidationException::withMessages([
                    'project' => ['Project has no crop cycle.'],
                ]);
            }

            $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDate));

            $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
            $currencyCode = $agreement->currency_code ?? $tenant->currency_code ?? 'GBP';

            $debitAccount = $fundingAccountCode === 'BANK'
                ? $this->accountService->getByCode($tenantId, 'BANK')
                : $this->accountService->getByCode($tenantId, 'CASH');
            $creditAccount = $this->accountService->getByCode($tenantId, 'LOAN_PAYABLE');

            $this->postValidationService->validateNoDeprecatedAccounts($tenantId, [
                ['account_id' => $debitAccount->id],
                ['account_id' => $creditAccount->id],
            ]);

            $amount = $drawdown->amount;

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $cropCycleId,
                'source_type' => 'LOAN_DRAWDOWN',
                'source_id' => $drawdown->id,
                'posting_date' => Carbon::parse($postingDate)->format('Y-m-d'),
                'idempotency_key' => $effectiveKey,
            ]);

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $debitAccount->id,
                'debit_amount' => $amount,
                'credit_amount' => 0,
                'currency_code' => $currencyCode,
            ]);

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $creditAccount->id,
                'debit_amount' => 0,
                'credit_amount' => $amount,
                'currency_code' => $currencyCode,
            ]);

            $totalDebits = LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('debit_amount');
            $totalCredits = LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('credit_amount');
            if (abs((float) $totalDebits - (float) $totalCredits) > 0.01) {
                throw new \RuntimeException('Debits and credits do not balance');
            }

            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $drawdown->project_id,
                'party_id' => $agreement->lender_party_id,
                'allocation_type' => 'LOAN_DRAWDOWN',
                'amount' => $amount,
                'rule_snapshot' => [
                    'loan_agreement_id' => $agreement->id,
                    'loan_drawdown_id' => $drawdown->id,
                    'funding_account' => $fundingAccountCode,
                ],
            ]);

            $drawdown->update([
                'status' => LoanDrawdown::STATUS_POSTED,
                'posting_group_id' => $postingGroup->id,
                'posted_at' => now(),
            ]);

            return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }
}

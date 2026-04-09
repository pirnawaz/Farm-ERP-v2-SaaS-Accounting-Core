<?php

namespace App\Domains\Accounting\FixedAssets;

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
 * Full disposal: remove gross cost and accumulated depreciation, record proceeds, gain/loss. One posting group per disposal.
 */
class FixedAssetDisposalPostingService
{
    private const SOURCE_TYPE = 'FIXED_ASSET_DISPOSAL';

    private const EPS = 0.02;

    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency
    ) {}

    public function post(
        string $disposalId,
        string $tenantId,
        string $postingDate,
        ?string $idempotencyKey = null,
        ?string $postedByUserId = null
    ): PostingGroup {
        return LedgerWriteGuard::scoped(self::class, function () use ($disposalId, $tenantId, $postingDate, $idempotencyKey, $postedByUserId) {
            return DB::transaction(function () use ($disposalId, $tenantId, $postingDate, $idempotencyKey, $postedByUserId) {
                /** @var FixedAssetDisposal $disposal */
                $disposal = TenantScoped::for(FixedAssetDisposal::query(), $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($disposalId);

                if ($disposal->posting_group_id) {
                    $pg = TenantScoped::for(PostingGroup::query(), $tenantId)
                        ->where('id', $disposal->posting_group_id)
                        ->first();
                    if ($pg) {
                        return $pg->load(['ledgerEntries.account', 'allocationRows']);
                    }
                }

                $postingDateStr = Carbon::parse($postingDate)->format('Y-m-d');

                $resolved = $this->postingIdempotency->resolveOrCreate($tenantId, $idempotencyKey, self::SOURCE_TYPE, $disposal->id);
                if ($resolved['posting_group'] !== null) {
                    $existingByKey = $resolved['posting_group'];
                    if ($disposal->status !== FixedAssetDisposal::STATUS_POSTED || ! $disposal->posting_group_id) {
                        $this->syncDisposalPostedState($disposal, $existingByKey, $postingDateStr, $postedByUserId);
                    }

                    return $existingByKey->load(['ledgerEntries.account', 'allocationRows']);
                }
                $effectiveKey = $resolved['effective_key'];

                if ($disposal->status !== FixedAssetDisposal::STATUS_DRAFT) {
                    throw ValidationException::withMessages([
                        'status' => ['Only a DRAFT disposal can be posted.'],
                    ]);
                }

                $proceeds = round((float) $disposal->proceeds_amount, 2);
                if ($proceeds > self::EPS && ! in_array($disposal->proceeds_account, ['BANK', 'CASH'], true)) {
                    throw ValidationException::withMessages([
                        'proceeds_account' => ['proceeds_account must be BANK or CASH when proceeds_amount is greater than zero.'],
                    ]);
                }

                /** @var FixedAsset $asset */
                $asset = TenantScoped::for(FixedAsset::query(), $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($disposal->fixed_asset_id);

                if ($asset->status !== FixedAsset::STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'fixed_asset' => ['Only ACTIVE assets can be disposed.'],
                    ]);
                }

                $book = FixedAssetBook::query()
                    ->where('tenant_id', $tenantId)
                    ->where('fixed_asset_id', $asset->id)
                    ->where('book_type', FixedAssetBook::BOOK_PRIMARY)
                    ->lockForUpdate()
                    ->firstOrFail();

                $grossCost = round((float) $book->asset_cost, 2);
                $accum = round((float) $book->accumulated_depreciation, 2);
                $carrying = round((float) $book->carrying_amount, 2);
                $expectedCarrying = round(max(0.0, $grossCost - $accum), 2);
                if (abs($carrying - $expectedCarrying) > self::EPS) {
                    throw ValidationException::withMessages([
                        'book' => ['Primary book carrying amount is inconsistent with cost and accumulated depreciation.'],
                    ]);
                }

                $residual = round((float) $asset->residual_value, 2);
                if ($carrying < $residual - self::EPS) {
                    throw ValidationException::withMessages([
                        'book' => ['Carrying amount is below residual value; disposal is not allowed.'],
                    ]);
                }

                $netGainLoss = round($proceeds - $carrying, 2);
                $gain = 0.0;
                $loss = 0.0;
                if ($netGainLoss > self::EPS) {
                    $gain = round($netGainLoss, 2);
                } elseif ($netGainLoss < -self::EPS) {
                    $loss = round(abs($netGainLoss), 2);
                }

                $fixedAssetAccount = $this->accountService->getByCode($tenantId, 'FIXED_ASSET');
                $adAccount = $this->accountService->getByCode($tenantId, 'ACCUMULATED_DEPRECIATION');
                $gainAccount = $this->accountService->getByCode($tenantId, 'GAIN_ON_FIXED_ASSET_DISPOSAL');
                $lossAccount = $this->accountService->getByCode($tenantId, 'LOSS_ON_FIXED_ASSET_DISPOSAL');

                $ledgerLines = [];
                $ledgerLines[] = ['account_id' => $adAccount->id, 'debit_amount' => $accum, 'credit_amount' => 0.0];
                $ledgerLines[] = ['account_id' => $fixedAssetAccount->id, 'debit_amount' => 0.0, 'credit_amount' => $grossCost];

                if ($proceeds > self::EPS) {
                    $proceedsAcct = $disposal->proceeds_account === 'BANK'
                        ? $this->accountService->getByCode($tenantId, 'BANK')
                        : $this->accountService->getByCode($tenantId, 'CASH');
                    $ledgerLines[] = [
                        'account_id' => $proceedsAcct->id,
                        'debit_amount' => $proceeds,
                        'credit_amount' => 0.0,
                    ];
                }

                if ($gain > self::EPS) {
                    $ledgerLines[] = ['account_id' => $gainAccount->id, 'debit_amount' => 0.0, 'credit_amount' => $gain];
                }
                if ($loss > self::EPS) {
                    $ledgerLines[] = ['account_id' => $lossAccount->id, 'debit_amount' => $loss, 'credit_amount' => 0.0];
                }

                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

                $sumDr = 0.0;
                $sumCr = 0.0;
                foreach ($ledgerLines as $row) {
                    $sumDr += $row['debit_amount'];
                    $sumCr += $row['credit_amount'];
                }
                if (abs($sumDr - $sumCr) > self::EPS) {
                    throw new \RuntimeException('Disposal journal does not balance: Dr '.$sumDr.' Cr '.$sumCr);
                }

                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateStr));
                $this->operationalPostingGuard->ensureCropCycleOpenViaAnyOpenProject($tenantId);

                $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
                $currencyCode = $asset->currency_code ?: ($tenant->currency_code ?? 'GBP');

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => null,
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => $disposal->id,
                    'posting_date' => $postingDateStr,
                    'idempotency_key' => $effectiveKey,
                ]);

                foreach ($ledgerLines as $row) {
                    if ($row['debit_amount'] <= self::EPS && $row['credit_amount'] <= self::EPS) {
                        continue;
                    }
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $postingGroup->id,
                        'account_id' => $row['account_id'],
                        'debit_amount' => round($row['debit_amount'], 2),
                        'credit_amount' => round($row['credit_amount'], 2),
                        'currency_code' => $currencyCode,
                    ]);
                }

                $sumDr2 = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('debit_amount');
                $sumCr2 = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('credit_amount');
                if (abs($sumDr2 - $sumCr2) > self::EPS) {
                    throw new \RuntimeException('Posted ledger is not balanced.');
                }

                $project = $asset->project_id ? TenantScoped::for(Project::query(), $tenantId)->find($asset->project_id) : null;
                $partyId = $project?->party_id;

                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $asset->project_id,
                    'party_id' => $partyId,
                    'allocation_type' => 'FIXED_ASSET_DISPOSAL',
                    'amount' => $grossCost,
                    'rule_snapshot' => [
                        'fixed_asset_disposal_id' => $disposal->id,
                        'fixed_asset_id' => $asset->id,
                        'asset_code' => $asset->asset_code,
                        'gross_cost' => $grossCost,
                        'accumulated_depreciation' => $accum,
                        'carrying_amount' => $carrying,
                        'proceeds_amount' => $proceeds,
                        'gain_amount' => $gain,
                        'loss_amount' => $loss,
                    ],
                ]);

                $book->update([
                    'accumulated_depreciation' => $grossCost,
                    'carrying_amount' => 0,
                    'last_depreciation_date' => Carbon::parse($disposal->disposal_date)->format('Y-m-d'),
                ]);

                $asset->update(['status' => FixedAsset::STATUS_DISPOSED]);

                $disposal->update([
                    'status' => FixedAssetDisposal::STATUS_POSTED,
                    'posting_date' => $postingDateStr,
                    'posted_at' => now(),
                    'posted_by_user_id' => $postedByUserId,
                    'posting_group_id' => $postingGroup->id,
                    'carrying_amount_at_post' => $carrying,
                    'gain_amount' => $gain > self::EPS ? $gain : null,
                    'loss_amount' => $loss > self::EPS ? $loss : null,
                ]);

                return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }

    private function syncDisposalPostedState(
        FixedAssetDisposal $disposal,
        PostingGroup $postingGroup,
        string $postingDateStr,
        ?string $postedByUserId
    ): void {
        $disposal->update([
            'status' => FixedAssetDisposal::STATUS_POSTED,
            'posting_group_id' => $postingGroup->id,
            'posted_at' => $disposal->posted_at ?? now(),
            'posted_by_user_id' => $disposal->posted_by_user_id ?? $postedByUserId,
            'posting_date' => $disposal->posting_date ?? $postingDateStr,
        ]);
    }
}

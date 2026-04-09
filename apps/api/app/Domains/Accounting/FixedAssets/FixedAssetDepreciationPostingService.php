<?php

namespace App\Domains\Accounting\FixedAssets;

use App\Models\AllocationRow;
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
 * Posts a depreciation run: Dr depreciation expense, Cr accumulated depreciation. Updates PRIMARY books in the same transaction.
 */
class FixedAssetDepreciationPostingService
{
    private const SOURCE_TYPE = 'FIXED_ASSET_DEPRECIATION_RUN';

    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency
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
                /** @var FixedAssetDepreciationRun $run */
                $run = TenantScoped::for(FixedAssetDepreciationRun::query(), $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($runId);

                if ($run->posting_group_id) {
                    $pg = TenantScoped::for(PostingGroup::query(), $tenantId)
                        ->where('id', $run->posting_group_id)
                        ->first();
                    if ($pg) {
                        return $pg->load(['ledgerEntries.account', 'allocationRows']);
                    }
                }

                $postingDateStr = Carbon::parse($postingDate)->format('Y-m-d');

                $resolved = $this->postingIdempotency->resolveOrCreate($tenantId, $idempotencyKey, self::SOURCE_TYPE, $run->id);
                if ($resolved['posting_group'] !== null) {
                    $existingByKey = $resolved['posting_group'];
                    if ($run->status !== FixedAssetDepreciationRun::STATUS_POSTED || ! $run->posting_group_id) {
                        $run->update([
                            'status' => FixedAssetDepreciationRun::STATUS_POSTED,
                            'posting_group_id' => $existingByKey->id,
                            'posted_at' => $run->posted_at ?? now(),
                            'posted_by_user_id' => $run->posted_by_user_id ?? $postedByUserId,
                            'posting_date' => $run->posting_date ?? $postingDateStr,
                        ]);
                    }

                    return $existingByKey->load(['ledgerEntries.account', 'allocationRows']);
                }
                $effectiveKey = $resolved['effective_key'];

                if ($run->status !== FixedAssetDepreciationRun::STATUS_DRAFT) {
                    throw ValidationException::withMessages([
                        'status' => ['Only a DRAFT depreciation run can be posted.'],
                    ]);
                }

                $run->load(['lines.fixedAsset.project']);
                if ($run->lines->isEmpty()) {
                    throw ValidationException::withMessages([
                        'lines' => ['Depreciation run has no lines to post.'],
                    ]);
                }

                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateStr));
                $this->operationalPostingGuard->ensureCropCycleOpenViaAnyOpenProject($tenantId);

                $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
                $currencyCode = $tenant->currency_code ?? 'GBP';

                $expenseAccount = $this->accountService->getByCode($tenantId, 'FIXED_ASSET_DEPRECIATION_EXPENSE');
                $accumAccount = $this->accountService->getByCode($tenantId, 'ACCUMULATED_DEPRECIATION');

                $total = 0.0;
                foreach ($run->lines as $line) {
                    /** @var FixedAssetDepreciationLine $line */
                    $book = FixedAssetBook::query()
                        ->where('tenant_id', $tenantId)
                        ->where('fixed_asset_id', $line->fixed_asset_id)
                        ->where('book_type', FixedAssetBook::BOOK_PRIMARY)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $asset = $line->fixedAsset;
                    if ($asset === null) {
                        $asset = TenantScoped::for(FixedAsset::query(), $tenantId)->findOrFail($line->fixed_asset_id);
                    }

                    $residual = round((float) $asset->residual_value, 2);
                    $carrying = round((float) $book->carrying_amount, 2);
                    $maxDep = max(0.0, round($carrying - $residual, 2));
                    $lineAmt = round((float) $line->depreciation_amount, 2);

                    if ($lineAmt > $maxDep + 0.02) {
                        throw ValidationException::withMessages([
                            'depreciation_amount' => [
                                "Line for asset {$asset->asset_code} exceeds remaining depreciable basis ({$maxDep}). Regenerate the run.",
                            ],
                        ]);
                    }

                    if ($lineAmt < 0) {
                        throw ValidationException::withMessages([
                            'lines' => ['Invalid depreciation line amount.'],
                        ]);
                    }

                    $total += $lineAmt;
                }

                if ($total <= 0) {
                    throw ValidationException::withMessages([
                        'lines' => ['Total depreciation must be greater than zero.'],
                    ]);
                }

                $total = round($total, 2);

                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, [
                    ['account_id' => $expenseAccount->id],
                    ['account_id' => $accumAccount->id],
                ]);

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => null,
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => $run->id,
                    'posting_date' => $postingDateStr,
                    'idempotency_key' => $effectiveKey,
                ]);

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $expenseAccount->id,
                    'debit_amount' => $total,
                    'credit_amount' => 0,
                    'currency_code' => $currencyCode,
                ]);

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $accumAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $total,
                    'currency_code' => $currencyCode,
                ]);

                $sumDr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('debit_amount');
                $sumCr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('credit_amount');
                if (abs($sumDr - $sumCr) > 0.02) {
                    throw new \RuntimeException('Debits and credits do not balance');
                }

                $periodEnd = Carbon::parse($run->period_end)->format('Y-m-d');

                foreach ($run->lines as $line) {
                    $book = FixedAssetBook::query()
                        ->where('tenant_id', $tenantId)
                        ->where('fixed_asset_id', $line->fixed_asset_id)
                        ->where('book_type', FixedAssetBook::BOOK_PRIMARY)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $asset = $line->fixedAsset ?? TenantScoped::for(FixedAsset::query(), $tenantId)->findOrFail($line->fixed_asset_id);
                    $residual = round((float) $asset->residual_value, 2);
                    $amt = round((float) $line->depreciation_amount, 2);

                    $newAccum = round((float) $book->accumulated_depreciation + $amt, 2);
                    $newCarrying = max($residual, round((float) $book->carrying_amount - $amt, 2));

                    $book->update([
                        'accumulated_depreciation' => $newAccum,
                        'carrying_amount' => $newCarrying,
                        'last_depreciation_date' => $periodEnd,
                    ]);

                    $project = $asset->relationLoaded('project') ? $asset->project : null;
                    if ($asset->project_id && $project === null) {
                        $asset->load('project');
                        $project = $asset->project;
                    }
                    $partyId = $project?->party_id;

                    AllocationRow::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $postingGroup->id,
                        'project_id' => $asset->project_id,
                        'party_id' => $partyId,
                        'allocation_type' => 'FIXED_ASSET_DEPRECIATION',
                        'amount' => $amt,
                        'rule_snapshot' => [
                            'fixed_asset_depreciation_run_id' => $run->id,
                            'fixed_asset_depreciation_line_id' => $line->id,
                            'fixed_asset_id' => $asset->id,
                            'asset_code' => $asset->asset_code,
                        ],
                    ]);
                }

                $run->update([
                    'status' => FixedAssetDepreciationRun::STATUS_POSTED,
                    'posting_date' => $postingDateStr,
                    'posted_at' => now(),
                    'posted_by_user_id' => $postedByUserId,
                    'posting_group_id' => $postingGroup->id,
                ]);

                return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }
}

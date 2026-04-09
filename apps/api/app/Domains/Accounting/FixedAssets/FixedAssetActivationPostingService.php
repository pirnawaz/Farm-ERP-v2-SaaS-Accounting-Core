<?php

namespace App\Domains\Accounting\FixedAssets;

use App\Domains\Accounting\MultiCurrency\PostingFxService;
use App\Models\AllocationRow;
use App\Models\CropCycle;
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
 * Activates a fixed asset: Dr FIXED_ASSET, Cr funding account. One posting group per asset (idempotent).
 */
class FixedAssetActivationPostingService
{
    private const SOURCE_TYPE = 'FIXED_ASSET_ACTIVATION';

    /** @var array<string, string> */
    private const SOURCE_ACCOUNT_TO_CODE = [
        'BANK' => 'BANK',
        'CASH' => 'CASH',
        'AP_CLEARING' => 'AP_CLEARING',
        'EQUITY_INJECTION' => 'EQUITY_INJECTION',
    ];

    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency,
        private PostingFxService $postingFx
    ) {}

    /**
     * @param  'BANK'|'CASH'|'AP_CLEARING'|'EQUITY_INJECTION'  $sourceAccount
     */
    public function activate(
        string $fixedAssetId,
        string $tenantId,
        string $postingDate,
        string $sourceAccount,
        ?string $idempotencyKey = null,
        ?string $activatedByUserId = null
    ): PostingGroup {
        if (! isset(self::SOURCE_ACCOUNT_TO_CODE[$sourceAccount])) {
            throw ValidationException::withMessages([
                'source_account' => ['source_account must be one of: BANK, CASH, AP_CLEARING, EQUITY_INJECTION.'],
            ]);
        }

        return LedgerWriteGuard::scoped(self::class, function () use ($fixedAssetId, $tenantId, $postingDate, $sourceAccount, $idempotencyKey, $activatedByUserId) {
            return DB::transaction(function () use ($fixedAssetId, $tenantId, $postingDate, $sourceAccount, $idempotencyKey, $activatedByUserId) {
                /** @var FixedAsset $asset */
                $asset = TenantScoped::for(FixedAsset::query(), $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($fixedAssetId);

                if ($asset->activation_posting_group_id) {
                    $pg = TenantScoped::for(PostingGroup::query(), $tenantId)
                        ->where('id', $asset->activation_posting_group_id)
                        ->first();
                    if ($pg) {
                        return $pg->load(['ledgerEntries.account', 'allocationRows']);
                    }
                }

                $resolved = $this->postingIdempotency->resolveOrCreate($tenantId, $idempotencyKey, self::SOURCE_TYPE, $asset->id);
                if ($resolved['posting_group'] !== null) {
                    $existingByKey = $resolved['posting_group'];
                    if ($asset->status !== FixedAsset::STATUS_ACTIVE || ! $asset->activation_posting_group_id) {
                        $asset->update([
                            'status' => FixedAsset::STATUS_ACTIVE,
                            'activation_posting_group_id' => $existingByKey->id,
                            'activated_at' => $asset->activated_at ?? now(),
                            'activated_by_user_id' => $asset->activated_by_user_id ?? $activatedByUserId,
                        ]);
                        $this->ensurePrimaryBook($asset->fresh());
                    }

                    return $existingByKey->load(['ledgerEntries.account', 'allocationRows']);
                }
                $effectiveKey = $resolved['effective_key'];

                if ($asset->status !== FixedAsset::STATUS_DRAFT) {
                    throw ValidationException::withMessages([
                        'status' => ['Only assets in DRAFT status can be activated.'],
                    ]);
                }

                if (! $asset->acquisition_date || ! $asset->in_service_date) {
                    throw ValidationException::withMessages([
                        'fixed_asset' => ['acquisition_date and in_service_date are required before activation.'],
                    ]);
                }

                $amount = (float) $asset->acquisition_cost;
                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'acquisition_cost' => ['acquisition_cost must be greater than zero to activate.'],
                    ]);
                }

                $cropCycleId = null;
                $project = null;
                if ($asset->project_id) {
                    $project = TenantScoped::for(Project::query(), $tenantId)->findOrFail($asset->project_id);
                    if (! $project->crop_cycle_id) {
                        throw ValidationException::withMessages([
                            'project' => ['Project has no crop cycle.'],
                        ]);
                    }
                    $this->operationalPostingGuard->ensureCropCycleOpenForProject($asset->project_id, $tenantId);
                    $cropCycleId = $project->crop_cycle_id;

                    $cycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();
                    $this->assertDateInCropCycle($asset->acquisition_date, $cycle, 'acquisition_date');
                    $this->assertDateInCropCycle($asset->in_service_date, $cycle, 'in_service_date');
                } else {
                    $this->operationalPostingGuard->ensureCropCycleOpenViaAnyOpenProject($tenantId);
                }

                $postingDateStr = Carbon::parse($postingDate)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateStr));

                $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
                $currencyCode = strtoupper((string) ($asset->currency_code ?: ($tenant->currency_code ?? 'GBP')));

                $fx = $this->postingFx->forPosting($tenantId, $postingDateStr, $currencyCode);

                $fixedAssetAccount = $this->accountService->getByCode($tenantId, 'FIXED_ASSET');
                $creditCode = self::SOURCE_ACCOUNT_TO_CODE[$sourceAccount];
                $creditAccount = $this->accountService->getByCode($tenantId, $creditCode);

                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, [
                    ['account_id' => $fixedAssetAccount->id],
                    ['account_id' => $creditAccount->id],
                ]);

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $cropCycleId,
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => $asset->id,
                    'posting_date' => $postingDateStr,
                    'idempotency_key' => $effectiveKey,
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                ]);

                $amountBase = $fx->amountInBase($amount);

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $fixedAssetAccount->id,
                    'debit_amount' => $amount,
                    'credit_amount' => 0,
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                    'debit_amount_base' => $amountBase,
                    'credit_amount_base' => 0,
                ]);

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $creditAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $amount,
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                    'debit_amount_base' => 0,
                    'credit_amount_base' => $amountBase,
                ]);

                $sumDr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('debit_amount');
                $sumCr = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('credit_amount');
                if (abs($sumDr - $sumCr) > 0.01) {
                    throw new \RuntimeException('Debits and credits do not balance');
                }

                $sumDrBase = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('debit_amount_base');
                $sumCrBase = (float) LedgerEntry::query()->where('posting_group_id', $postingGroup->id)->sum('credit_amount_base');
                if (abs($sumDrBase - $sumCrBase) > 0.02) {
                    throw new \RuntimeException('Debits and credits do not balance in base currency');
                }

                $partyId = $project?->party_id;

                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $asset->project_id,
                    'party_id' => $partyId,
                    'allocation_type' => 'FIXED_ASSET_ACTIVATION',
                    'amount' => round($amount, 2),
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                    'amount_base' => $amountBase,
                    'rule_snapshot' => [
                        'fixed_asset_id' => $asset->id,
                        'asset_code' => $asset->asset_code,
                        'source_account' => $sourceAccount,
                        'crop_cycle_id' => $cropCycleId,
                    ],
                ]);

                $asset->update([
                    'status' => FixedAsset::STATUS_ACTIVE,
                    'activation_posting_group_id' => $postingGroup->id,
                    'activated_at' => now(),
                    'activated_by_user_id' => $activatedByUserId,
                ]);

                $this->ensurePrimaryBook($asset->fresh());

                return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }

    private function ensurePrimaryBook(FixedAsset $asset): void
    {
        FixedAssetBook::query()->firstOrCreate(
            [
                'tenant_id' => $asset->tenant_id,
                'fixed_asset_id' => $asset->id,
                'book_type' => FixedAssetBook::BOOK_PRIMARY,
            ],
            [
                'asset_cost' => $asset->acquisition_cost,
                'accumulated_depreciation' => 0,
                'carrying_amount' => $asset->acquisition_cost,
                'last_depreciation_date' => null,
            ]
        );
    }

    private function assertDateInCropCycle(\DateTimeInterface|string $date, CropCycle $cycle, string $fieldLabel): void
    {
        $d = Carbon::parse($date)->format('Y-m-d');
        $start = $cycle->start_date ? Carbon::parse($cycle->start_date)->format('Y-m-d') : null;
        $end = $cycle->end_date ? Carbon::parse($cycle->end_date)->format('Y-m-d') : null;
        if ($start && $d < $start) {
            throw ValidationException::withMessages([
                $fieldLabel => ["{$fieldLabel} is before the project crop cycle start date."],
            ]);
        }
        if ($end && $d > $end) {
            throw ValidationException::withMessages([
                $fieldLabel => ["{$fieldLabel} is after the project crop cycle end date."],
            ]);
        }
    }
}

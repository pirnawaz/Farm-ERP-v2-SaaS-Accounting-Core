<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\OperationalTransaction;
use App\Models\PostingGroup;
use App\Models\Settlement;
use App\Models\SettlementOffset;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\CropCycle;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SettlementService
{
    public function __construct(
        private SystemAccountService $accountService,
        private SystemPartyService $partyService,
        private PartyFinancialSourceService $financialSourceService
    ) {}

    /**
     * Preview settlement calculations for a project.
     * 
     * @param string $projectId
     * @param string $tenantId
     * @param string|null $upToDate YYYY-MM-DD format, defaults to today
     * @return array
     */
    public function previewSettlement(string $projectId, string $tenantId, ?string $upToDate = null): array
    {
        $project = Project::where('id', $projectId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $projectRule = ProjectRule::where('project_id', $projectId)->first();
        if (!$projectRule) {
            throw new \Exception('Project rules not found');
        }

        $upToDateObj = $upToDate ? Carbon::parse($upToDate) : Carbon::today();

        // Get all posted transactions for this project up to up_to_date
        // We need to join with posting_groups to filter by posting_date
        $postedTransactionIds = PostingGroup::where('tenant_id', $tenantId)
            ->where('source_type', 'OPERATIONAL')
            ->where('posting_date', '<=', $upToDateObj->format('Y-m-d'))
            ->pluck('source_id')
            ->toArray();

        // Get transactions that are posted and belong to this project
        // FARM_OVERHEAD transactions are excluded from project settlement
        $postedTransactions = OperationalTransaction::where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('status', 'POSTED')
            ->whereIn('id', $postedTransactionIds)
            ->get();

        // Calculate totals
        $poolRevenue = $postedTransactions
            ->where('type', 'INCOME')
            ->sum('amount');

        $sharedCosts = $postedTransactions
            ->where('type', 'EXPENSE')
            ->where('classification', 'SHARED')
            ->sum('amount');

        $hariOnlyDeductions = $postedTransactions
            ->where('type', 'EXPENSE')
            ->where('classification', 'HARI_ONLY')
            ->sum('amount');

        // Apply Decision D math
        $poolProfit = $poolRevenue - $sharedCosts;
        $kamdariAmount = $poolProfit * ($projectRule->kamdari_pct / 100);
        $remainingPool = $poolProfit - $kamdariAmount;
        $landlordGross = $remainingPool * ($projectRule->profit_split_landlord_pct / 100);
        $hariGross = $remainingPool * ($projectRule->profit_split_hari_pct / 100);
        $hariNet = $hariGross - $hariOnlyDeductions;

        return [
            'pool_revenue' => $poolRevenue,
            'shared_costs' => $sharedCosts,
            'pool_profit' => $poolProfit,
            'kamdari_amount' => $kamdariAmount,
            'remaining_pool' => $remainingPool,
            'landlord_gross' => $landlordGross,
            'hari_gross' => $hariGross,
            'hari_only_deductions' => $hariOnlyDeductions,
            'hari_net' => $hariNet,
        ];
    }

    /**
     * Preview offset information for a settlement.
     * 
     * @param string $projectId
     * @param string $tenantId
     * @param string $postingDate YYYY-MM-DD format
     * @return array
     */
    public function offsetPreview(string $projectId, string $tenantId, string $postingDate): array
    {
        $project = Project::where('id', $projectId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Calculate Hari payable from settlement preview
        $calculations = $this->previewSettlement($projectId, $tenantId, $postingDate);
        $hariPayableAmount = (float) $calculations['hari_net'];

        // Get Hari party ID from project
        $hariPartyId = $project->party_id;

        // Get outstanding advance balance as of posting date
        $outstandingAdvance = $this->financialSourceService->getOutstandingAdvanceBalance(
            $hariPartyId,
            $tenantId,
            $postingDate
        );

        // Suggested offset = min(payable, outstanding advance)
        $suggestedOffset = min($hariPayableAmount, $outstandingAdvance);
        $maxOffset = $suggestedOffset; // Same as suggested

        return [
            'hari_party_id' => $hariPartyId,
            'hari_payable_amount' => $hariPayableAmount,
            'outstanding_advance' => $outstandingAdvance,
            'suggested_offset' => $suggestedOffset,
            'max_offset' => $maxOffset,
        ];
    }

    /**
     * Post settlement for a project.
     * 
     * @param string $projectId
     * @param string $tenantId
     * @param string $postingDate YYYY-MM-DD format
     * @param string $idempotencyKey
     * @param string|null $upToDate YYYY-MM-DD format, defaults to posting_date
     * @param bool $applyAdvanceOffset Whether to apply advance offset
     * @param float|null $advanceOffsetAmount Offset amount (required if applyAdvanceOffset is true)
     * @return array Contains 'settlement' and 'posting_group'
     */
    public function postSettlement(
        string $projectId,
        string $tenantId,
        string $postingDate,
        string $idempotencyKey,
        ?string $upToDate = null,
        bool $applyAdvanceOffset = false,
        ?float $advanceOffsetAmount = null
    ): array {
        return DB::transaction(function () use ($projectId, $tenantId, $postingDate, $idempotencyKey, $upToDate, $applyAdvanceOffset, $advanceOffsetAmount) {
            // Check idempotency
            $existingPostingGroup = PostingGroup::where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingPostingGroup) {
                $settlement = Settlement::where('posting_group_id', $existingPostingGroup->id)->first();
                return [
                    'settlement_id' => $settlement->id,
                    'posting_group_id' => $existingPostingGroup->id,
                    'settlement' => $settlement->load('offsets'),
                    'posting_group' => $existingPostingGroup->load(['allocationRows', 'ledgerEntries.account']),
                ];
            }

            $project = Project::where('id', $projectId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            $projectRule = ProjectRule::where('project_id', $projectId)->first();
            if (!$projectRule) {
                throw new \Exception('Project rules not found');
            }

            // Verify crop cycle is OPEN
            $cropCycle = CropCycle::where('id', $project->crop_cycle_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            if ($cropCycle->status !== 'OPEN') {
                throw new \Exception('Cannot post settlement to a closed crop cycle');
            }

            // Ensure landlord party exists
            $landlordParty = $this->partyService->ensureSystemLandlordParty($tenantId);

            // Calculate settlement amounts
            $upToDateObj = $upToDate ? Carbon::parse($upToDate) : Carbon::parse($postingDate);
            $calculations = $this->previewSettlement($projectId, $tenantId, $upToDateObj->format('Y-m-d'));

            // Validate and process offset if requested
            $offsetAmount = 0;
            $hariPartyId = $project->party_id;
            
            if ($applyAdvanceOffset) {
                if ($advanceOffsetAmount === null || $advanceOffsetAmount <= 0) {
                    throw new \Exception('Advance offset amount must be greater than 0 when apply_advance_offset is true');
                }

                // Calculate Hari payable
                $hariPayableAmount = (float) $calculations['hari_net'];

                // Get outstanding advance balance as of posting date (recalculate at POST time for concurrency)
                $outstandingAdvance = $this->financialSourceService->getOutstandingAdvanceBalance(
                    $hariPartyId,
                    $tenantId,
                    $postingDate
                );

                // Validate offset amount
                $allowedMax = min($hariPayableAmount, $outstandingAdvance);
                
                if ($advanceOffsetAmount > $allowedMax) {
                    throw new \Exception(
                        "Advance offset amount ({$advanceOffsetAmount}) exceeds allowed maximum ({$allowedMax}). " .
                        "Outstanding advance balance may have changed. Please refresh and try again."
                    );
                }

                $offsetAmount = $advanceOffsetAmount;
            }

            // Get system accounts
            $profitDistributionAccount = $this->accountService->getByCode($tenantId, 'PROFIT_DISTRIBUTION');
            $payableLandlordAccount = $this->accountService->getByCode($tenantId, 'PAYABLE_LANDLORD');
            $payableHariAccount = $this->accountService->getByCode($tenantId, 'PAYABLE_HARI');
            $advanceHariAccount = null;
            if ($offsetAmount > 0) {
                $advanceHariAccount = $this->accountService->getByCode($tenantId, 'ADVANCE_HARI');
            }
            $payableKamdarAccount = null;
            if ($projectRule->kamdar_party_id && $calculations['kamdari_amount'] > 0) {
                try {
                    $payableKamdarAccount = $this->accountService->getByCode($tenantId, 'PAYABLE_KAMDAR');
                } catch (\Exception $e) {
                    // Account might not exist, that's okay if kamdari_amount is 0
                }
            }

            // Create posting group
            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $project->crop_cycle_id,
                'source_type' => 'SETTLEMENT',
                'source_id' => $projectId,
                'posting_date' => Carbon::parse($postingDate)->format('Y-m-d'),
                'idempotency_key' => $idempotencyKey,
            ]);

            // Create settlement record
            $settlement = Settlement::create([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'posting_group_id' => $postingGroup->id,
                'pool_revenue' => $calculations['pool_revenue'],
                'shared_costs' => $calculations['shared_costs'],
                'pool_profit' => $calculations['pool_profit'],
                'kamdari_amount' => $calculations['kamdari_amount'],
                'landlord_share' => $calculations['landlord_gross'],
                'hari_share' => $calculations['hari_net'],
                'hari_only_deductions' => $calculations['hari_only_deductions'],
            ]);

            // Create allocation rows per Decision D
            $ruleSnapshot = [
                'landlord_pct' => $projectRule->profit_split_landlord_pct,
                'hari_pct' => $projectRule->profit_split_hari_pct,
                'kamdari_pct' => $projectRule->kamdari_pct,
                'pool_definition' => $projectRule->pool_definition,
                'kamdari_order' => $projectRule->kamdari_order,
            ];

            // KAMDARI allocation (if applicable)
            if ($projectRule->kamdar_party_id && $calculations['kamdari_amount'] > 0) {
                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $projectId,
                    'party_id' => $projectRule->kamdar_party_id,
                    'allocation_type' => 'KAMDARI',
                    'amount' => $calculations['kamdari_amount'],
                    'rule_snapshot' => $ruleSnapshot,
                ]);
            }

            // POOL_SHARE for landlord
            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $projectId,
                'party_id' => $landlordParty->id,
                'allocation_type' => 'POOL_SHARE',
                'amount' => $calculations['landlord_gross'],
                'rule_snapshot' => $ruleSnapshot,
            ]);

            // POOL_SHARE for hari
            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $projectId,
                'party_id' => $project->party_id,
                'allocation_type' => 'POOL_SHARE',
                'amount' => $calculations['hari_net'],
                'rule_snapshot' => $ruleSnapshot,
            ]);

            // HARI_ONLY deductions (for statement clarity)
            if ($calculations['hari_only_deductions'] > 0) {
                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $projectId,
                    'party_id' => $project->party_id,
                    'allocation_type' => 'HARI_ONLY',
                    'amount' => $calculations['hari_only_deductions'],
                    'rule_snapshot' => $ruleSnapshot,
                ]);
            }

            // Create ledger entries per Decision D
            // Dr PROFIT_DISTRIBUTION = (landlord_gross + hari_net + kamdari_amount)
            $totalDistribution = $calculations['landlord_gross'] + $calculations['hari_net'] + $calculations['kamdari_amount'];

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $profitDistributionAccount->id,
                'debit_amount' => $totalDistribution,
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);

            // Cr PAYABLE_LANDLORD
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $payableLandlordAccount->id,
                'debit_amount' => 0,
                'credit_amount' => $calculations['landlord_gross'],
                'currency_code' => 'GBP',
            ]);

            // Cr PAYABLE_HARI (full amount, will be reduced by offset if applicable)
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $payableHariAccount->id,
                'debit_amount' => 0,
                'credit_amount' => $calculations['hari_net'],
                'currency_code' => 'GBP',
            ]);

            // Handle offset if applicable
            if ($offsetAmount > 0) {
                // Create offset allocation rows for audit trail
                // Allocation Row A: Settlement advance offset (reduce Hari payable)
                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $projectId,
                    'party_id' => $hariPartyId,
                    'allocation_type' => 'ADVANCE_OFFSET',
                    'amount' => $offsetAmount,
                    'rule_snapshot' => array_merge($ruleSnapshot, [
                        'offset_type' => 'REDUCE_PAYABLE',
                        'description' => 'Settlement advance offset (reduce Hari payable)',
                    ]),
                ]);

                // Allocation Row B: Settlement advance recovery (reduce Hari advance)
                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $projectId,
                    'party_id' => $hariPartyId,
                    'allocation_type' => 'ADVANCE_OFFSET',
                    'amount' => $offsetAmount,
                    'rule_snapshot' => array_merge($ruleSnapshot, [
                        'offset_type' => 'REDUCE_ADVANCE',
                        'description' => 'Settlement advance recovery (reduce Hari advance)',
                    ]),
                ]);

                // Ledger entry: Debit PAYABLE_HARI (reduces what we owe the Hari)
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $payableHariAccount->id,
                    'debit_amount' => $offsetAmount,
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);

                // Ledger entry: Credit ADVANCE_HARI (reduces what Hari owes us / reduces receivable)
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $advanceHariAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $offsetAmount,
                    'currency_code' => 'GBP',
                ]);

                // Create settlement_offsets record
                SettlementOffset::create([
                    'tenant_id' => $tenantId,
                    'settlement_id' => $settlement->id,
                    'party_id' => $hariPartyId,
                    'posting_group_id' => $postingGroup->id,
                    'posting_date' => Carbon::parse($postingDate)->format('Y-m-d'),
                    'offset_amount' => $offsetAmount,
                ]);
            }

            // Cr PAYABLE_KAMDAR (if applicable)
            if ($payableKamdarAccount && $calculations['kamdari_amount'] > 0) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $payableKamdarAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $calculations['kamdari_amount'],
                    'currency_code' => 'GBP',
                ]);
            }

            // Verify debits == credits
            $totalDebits = LedgerEntry::where('posting_group_id', $postingGroup->id)
                ->sum('debit_amount');
            $totalCredits = LedgerEntry::where('posting_group_id', $postingGroup->id)
                ->sum('credit_amount');

            if (abs($totalDebits - $totalCredits) > 0.01) {
                throw new \Exception('Debits and credits do not balance');
            }

            return [
                'settlement' => $settlement->load(['postingGroup', 'offsets']),
                'posting_group' => $postingGroup->load(['allocationRows', 'ledgerEntries.account']),
            ];
        });
    }
}

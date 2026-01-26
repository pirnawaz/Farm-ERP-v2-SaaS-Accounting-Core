<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\OperationalTransaction;
use App\Models\PostingGroup;
use App\Models\Settlement;
use App\Models\SettlementOffset;
use App\Models\SettlementLine;
use App\Models\SettlementSale;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\CropCycle;
use App\Models\Sale;
use App\Models\ShareRule;
use App\Models\ShareRuleLine;
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

    // ============================================================================
    // SALES-BASED SETTLEMENTS (Phase 11)
    // ============================================================================

    /**
     * Preview settlement calculations for posted sales.
     * 
     * @param array $filters
     * @return array
     */
    public function preview(array $filters): array
    {
        $tenantId = $filters['tenant_id'];
        $cropCycleId = $filters['crop_cycle_id'] ?? null;
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;
        $shareRuleId = $filters['share_rule_id'] ?? null;

        // Get posted sales matching filters
        $salesQuery = Sale::where('tenant_id', $tenantId)
            ->where('status', 'POSTED')
            ->with(['lines', 'inventoryAllocations']);

        if ($cropCycleId) {
            $salesQuery->where('crop_cycle_id', $cropCycleId);
        }

        if ($fromDate) {
            $salesQuery->where('posting_date', '>=', $fromDate);
        }

        if ($toDate) {
            $salesQuery->where('posting_date', '<=', $toDate);
        }

        $sales = $salesQuery->get();

        // Filter out sales already settled
        $settledSaleIds = SettlementSale::where('tenant_id', $tenantId)
            ->whereHas('settlement', function ($q) {
                $q->where('status', 'POSTED');
            })
            ->pluck('sale_id')
            ->toArray();

        $sales = $sales->reject(fn($sale) => in_array($sale->id, $settledSaleIds));

        // Calculate totals
        $totalRevenue = 0;
        $totalCogs = 0;

        foreach ($sales as $sale) {
            // Revenue from sale lines
            $saleRevenue = $sale->lines->sum(fn($line) => (float) $line->line_total);
            $totalRevenue += $saleRevenue;

            // COGS from inventory allocations
            $saleCogs = $sale->inventoryAllocations->sum(fn($alloc) => (float) $alloc->total_cost);
            $totalCogs += $saleCogs;
        }

        $totalMargin = $totalRevenue - $totalCogs;

        // Resolve share rule if not provided
        if (!$shareRuleId && $cropCycleId) {
            $cropCycle = CropCycle::find($cropCycleId);
            $saleDate = $toDate ?? $fromDate ?? Carbon::now()->format('Y-m-d');
            $shareRuleService = app(ShareRuleService::class);
            $shareRule = $shareRuleService->resolveRule($tenantId, $saleDate, $cropCycleId);
            if ($shareRule) {
                $shareRuleId = $shareRule->id;
            }
        }

        if (!$shareRuleId) {
            throw new \Exception('Share rule is required. Either provide share_rule_id or ensure crop_cycle_id has an active rule.');
        }

        $shareRule = ShareRule::with('lines.party')->findOrFail($shareRuleId);

        // Determine basis amount
        $basisAmount = $shareRule->basis === 'MARGIN' ? $totalMargin : $totalRevenue;

        // Calculate per-party amounts
        $partyAmounts = [];
        foreach ($shareRule->lines as $line) {
            $amount = $basisAmount * ((float) $line->percentage / 100);
            $partyAmounts[] = [
                'party_id' => $line->party_id,
                'party_name' => $line->party->name,
                'role' => $line->role,
                'percentage' => (float) $line->percentage,
                'amount' => round($amount, 2),
            ];
        }

        return [
            'sales' => $sales->map(fn($sale) => [
                'id' => $sale->id,
                'sale_no' => $sale->sale_no,
                'posting_date' => $sale->posting_date->format('Y-m-d'),
                'revenue' => round($sale->lines->sum(fn($line) => (float) $line->line_total), 2),
                'cogs' => round($sale->inventoryAllocations->sum(fn($alloc) => (float) $alloc->total_cost), 2),
                'margin' => round(
                    $sale->lines->sum(fn($line) => (float) $line->line_total) -
                    $sale->inventoryAllocations->sum(fn($alloc) => (float) $alloc->total_cost),
                    2
                ),
            ]),
            'total_revenue' => round($totalRevenue, 2),
            'total_cogs' => round($totalCogs, 2),
            'total_margin' => round($totalMargin, 2),
            'share_rule' => [
                'id' => $shareRule->id,
                'name' => $shareRule->name,
                'basis' => $shareRule->basis,
            ],
            'basis_amount' => round($basisAmount, 2),
            'party_amounts' => $partyAmounts,
        ];
    }

    /**
     * Create a DRAFT settlement.
     * 
     * @param array $data
     * @return Settlement
     */
    public function create(array $data): Settlement
    {
        return DB::transaction(function () use ($data) {
            $tenantId = $data['tenant_id'];
            $saleIds = $data['sale_ids'] ?? [];

            // Validate sales are POSTED
            $sales = Sale::where('tenant_id', $tenantId)
                ->whereIn('id', $saleIds)
                ->where('status', 'POSTED')
                ->get();

            if ($sales->count() !== count($saleIds)) {
                throw new \Exception('All sales must be POSTED');
            }

            // Check sales aren't already settled
            $this->checkSalesAlreadySettled($saleIds, $tenantId);

            // Get preview to calculate amounts
            $preview = $this->preview([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $data['crop_cycle_id'] ?? null,
                'from_date' => $data['from_date'] ?? null,
                'to_date' => $data['to_date'] ?? null,
                'share_rule_id' => $data['share_rule_id'],
            ]);

            // Generate settlement number
            $settlementNo = $data['settlement_no'] ?? $this->generateSettlementNo($tenantId);

            // Create settlement
            $settlement = Settlement::create([
                'tenant_id' => $tenantId,
                'settlement_no' => $settlementNo,
                'share_rule_id' => $data['share_rule_id'],
                'crop_cycle_id' => $data['crop_cycle_id'] ?? null,
                'from_date' => $data['from_date'] ?? null,
                'to_date' => $data['to_date'] ?? null,
                'basis_amount' => $preview['basis_amount'],
                'status' => 'DRAFT',
                'created_by' => $data['created_by'] ?? null,
            ]);

            // Create settlement lines
            foreach ($preview['party_amounts'] as $partyAmount) {
                SettlementLine::create([
                    'settlement_id' => $settlement->id,
                    'party_id' => $partyAmount['party_id'],
                    'role' => $partyAmount['role'],
                    'percentage' => $partyAmount['percentage'],
                    'amount' => $partyAmount['amount'],
                ]);
            }

            // Link sales to settlement
            foreach ($saleIds as $saleId) {
                SettlementSale::create([
                    'tenant_id' => $tenantId,
                    'settlement_id' => $settlement->id,
                    'sale_id' => $saleId,
                ]);
            }

            return $settlement->load(['lines.party', 'sales', 'shareRule']);
        });
    }

    /**
     * Post a settlement.
     * 
     * @param Settlement $settlement
     * @param string $postingDate
     * @return array
     */
    public function post(Settlement $settlement, string $postingDate): array
    {
        return DB::transaction(function () use ($settlement, $postingDate) {
            // Assert status is DRAFT
            if ($settlement->status !== 'DRAFT') {
                throw new \Exception('Settlement must be in DRAFT status to post');
            }

            // Ensure referenced sales are POSTED and not already settled
            $saleIds = $settlement->sales->pluck('id')->toArray();
            $this->checkSalesAlreadySettled($saleIds, $settlement->tenant_id);

            // Verify crop cycle is OPEN if applicable
            if ($settlement->crop_cycle_id) {
                $cropCycle = CropCycle::findOrFail($settlement->crop_cycle_id);
                if ($cropCycle->status !== 'OPEN') {
                    throw new \Exception('Cannot post settlement to a closed crop cycle');
                }
            }

            // Create idempotency key
            $idempotencyKey = "settlement_{$settlement->tenant_id}_{$settlement->id}";

            // Check idempotency
            $existingPostingGroup = PostingGroup::where('tenant_id', $settlement->tenant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingPostingGroup) {
                $settlement->update([
                    'status' => 'POSTED',
                    'posting_group_id' => $existingPostingGroup->id,
                    'posting_date' => $postingDate,
                    'posted_at' => now(),
                ]);
                return [
                    'settlement' => $settlement->fresh(['postingGroup', 'lines.party']),
                    'posting_group' => $existingPostingGroup->load(['allocationRows', 'ledgerEntries.account']),
                ];
            }

            // Create posting group
            $postingGroup = PostingGroup::create([
                'tenant_id' => $settlement->tenant_id,
                'crop_cycle_id' => $settlement->crop_cycle_id,
                'source_type' => 'SETTLEMENT',
                'source_id' => $settlement->id,
                'posting_date' => Carbon::parse($postingDate)->format('Y-m-d'),
                'idempotency_key' => $idempotencyKey,
            ]);

            // Get accounts
            // Use PROFIT_DISTRIBUTION as settlement clearing (same as project settlements)
            // Note: SETTLEMENT_CLEARING account should be added to SystemAccountsSeeder in future
            try {
                $settlementClearingAccount = $this->accountService->getByCode($settlement->tenant_id, 'SETTLEMENT_CLEARING');
            } catch (\Exception $e) {
                // Fallback to PROFIT_DISTRIBUTION if SETTLEMENT_CLEARING doesn't exist
                $settlementClearingAccount = $this->accountService->getByCode($settlement->tenant_id, 'PROFIT_DISTRIBUTION');
            }
            
            // Use AP (Accounts Payable) as generic payable account
            // Note: ACCOUNTS_PAYABLE account should be added to SystemAccountsSeeder in future
            try {
                $accountsPayableAccount = $this->accountService->getByCode($settlement->tenant_id, 'ACCOUNTS_PAYABLE');
            } catch (\Exception $e) {
                // Fallback to AP if ACCOUNTS_PAYABLE doesn't exist
                $accountsPayableAccount = $this->accountService->getByCode($settlement->tenant_id, 'AP');
            }

            // Create allocation rows and ledger entries for each settlement line
            $totalDistribution = 0;
            foreach ($settlement->lines as $line) {
                $totalDistribution += (float) $line->amount;

                // Create allocation row
                AllocationRow::create([
                    'tenant_id' => $settlement->tenant_id,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => null, // Sales-based settlements don't use projects
                    'party_id' => $line->party_id,
                    'allocation_type' => 'SETTLEMENT_PAYABLE',
                    'amount' => $line->amount,
                    'rule_snapshot' => [
                        'settlement_id' => $settlement->id,
                        'share_rule_id' => $settlement->share_rule_id,
                        'role' => $line->role,
                        'percentage' => (float) $line->percentage,
                    ],
                ]);

                // Create ledger entry: Credit AP (party-specific payable)
                LedgerEntry::create([
                    'tenant_id' => $settlement->tenant_id,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $accountsPayableAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $line->amount,
                    'currency_code' => 'GBP',
                ]);
            }

            // Create ledger entry: Debit SETTLEMENT_CLEARING
            LedgerEntry::create([
                'tenant_id' => $settlement->tenant_id,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $settlementClearingAccount->id,
                'debit_amount' => $totalDistribution,
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);

            // Verify debits == credits
            $totalDebits = LedgerEntry::where('posting_group_id', $postingGroup->id)
                ->sum('debit_amount');
            $totalCredits = LedgerEntry::where('posting_group_id', $postingGroup->id)
                ->sum('credit_amount');

            if (abs($totalDebits - $totalCredits) > 0.01) {
                throw new \Exception('Debits and credits do not balance');
            }

            // Update settlement
            $settlement->update([
                'status' => 'POSTED',
                'posting_group_id' => $postingGroup->id,
                'posting_date' => $postingDate,
                'posted_at' => now(),
            ]);

            return [
                'settlement' => $settlement->fresh(['postingGroup', 'lines.party', 'sales']),
                'posting_group' => $postingGroup->load(['allocationRows', 'ledgerEntries.account']),
            ];
        });
    }

    /**
     * Reverse a posted settlement.
     * 
     * @param Settlement $settlement
     * @param string $reversalDate
     * @return array
     */
    public function reverse(Settlement $settlement, string $reversalDate): array
    {
        return DB::transaction(function () use ($settlement, $reversalDate) {
            // Assert status is POSTED
            if ($settlement->status !== 'POSTED') {
                throw new \Exception('Settlement must be in POSTED status to reverse');
            }

            if (!$settlement->posting_group_id) {
                throw new \Exception('Settlement has no posting group to reverse');
            }

            // Verify crop cycle is OPEN if applicable
            if ($settlement->crop_cycle_id) {
                $cropCycle = CropCycle::findOrFail($settlement->crop_cycle_id);
                if ($cropCycle->status !== 'OPEN') {
                    throw new \Exception('Cannot reverse settlement in a closed crop cycle');
                }
            }

            // Use ReversalService to reverse the posting group
            $reversalService = app(ReversalService::class);
            $reversalPostingGroup = $reversalService->reversePostingGroup(
                $settlement->posting_group_id,
                $settlement->tenant_id,
                $reversalDate,
                'Settlement reversal'
            );

            // Update settlement
            $settlement->update([
                'status' => 'REVERSED',
                'reversal_posting_group_id' => $reversalPostingGroup->id,
                'reversed_at' => now(),
            ]);

            return [
                'settlement' => $settlement->fresh(['postingGroup', 'reversalPostingGroup', 'lines.party']),
                'reversal_posting_group' => $reversalPostingGroup->load(['allocationRows', 'ledgerEntries.account']),
            ];
        });
    }

    /**
     * Check if sales are already settled.
     * 
     * @param array $saleIds
     * @param string $tenantId
     * @throws \Exception
     */
    public function checkSalesAlreadySettled(array $saleIds, string $tenantId): void
    {
        $settledSaleIds = SettlementSale::where('tenant_id', $tenantId)
            ->whereIn('sale_id', $saleIds)
            ->whereHas('settlement', function ($q) {
                $q->where('status', 'POSTED');
            })
            ->pluck('sale_id')
            ->toArray();

        if (!empty($settledSaleIds)) {
            $saleNos = Sale::whereIn('id', $settledSaleIds)->pluck('sale_no')->join(', ');
            throw new \Exception("The following sales are already settled: {$saleNos}");
        }
    }

    /**
     * Generate a unique settlement number.
     * 
     * @param string $tenantId
     * @return string
     */
    private function generateSettlementNo(string $tenantId): string
    {
        $year = Carbon::now()->format('Y');
        $lastSettlement = Settlement::where('tenant_id', $tenantId)
            ->where('settlement_no', 'like', "STL-{$year}-%")
            ->orderBy('settlement_no', 'desc')
            ->first();

        if ($lastSettlement) {
            $lastNumber = (int) substr($lastSettlement->settlement_no, -4);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('STL-%s-%04d', $year, $nextNumber);
    }
}

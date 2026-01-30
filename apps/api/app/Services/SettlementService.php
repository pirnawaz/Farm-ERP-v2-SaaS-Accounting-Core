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
use App\Services\Accounting\PostValidationService;
use App\Services\OperationalPostingGuard;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SettlementService
{
    /** Source types that contribute to project profit (operational + reversals; exclude settlement postings). */
    private const OPERATIONAL_SOURCE_TYPES = [
        'INVENTORY_ISSUE', 'INVENTORY_GRN', 'LABOUR_WORK_LOG', 'MACHINE_WORK_LOG',
        'MACHINE_MAINTENANCE_JOB', 'MACHINERY_CHARGE', 'CROP_ACTIVITY', 'OPERATIONAL',
        'SALE', 'HARVEST', 'REVERSAL',
    ];

    public function __construct(
        private SystemAccountService $accountService,
        private PartyAccountService $partyAccountService,
        private SystemPartyService $partyService,
        private PartyFinancialSourceService $financialSourceService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $guard
    ) {}

    /**
     * Compute project profit from ledger entries (canonical truth).
     * Only income and expense accounts; only posting_groups that have allocation_rows for this project;
     * exclude settlement posting groups.
     *
     * @return array{total_revenue: float, total_expenses: float, pool_profit: float}
     */
    public function getProjectProfitFromLedger(string $projectId, string $tenantId, string $upToDate): array
    {
        $pgIds = PostingGroup::where('tenant_id', $tenantId)
            ->where('posting_date', '<=', $upToDate)
            ->whereIn('source_type', self::OPERATIONAL_SOURCE_TYPES)
            ->whereExists(function ($q) use ($projectId) {
                $q->select(DB::raw(1))
                    ->from('allocation_rows')
                    ->whereColumn('allocation_rows.posting_group_id', 'posting_groups.id')
                    ->where('allocation_rows.project_id', $projectId);
            })
            ->pluck('id');

        if ($pgIds->isEmpty()) {
            return [
                'total_revenue' => 0.0,
                'total_expenses' => 0.0,
                'pool_profit' => 0.0,
            ];
        }

        $rows = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.tenant_id', $tenantId)
            ->whereIn('ledger_entries.posting_group_id', $pgIds)
            ->whereIn('accounts.type', ['income', 'expense'])
            ->selectRaw("
                SUM(CASE WHEN accounts.type = 'income' THEN (ledger_entries.credit_amount - ledger_entries.debit_amount) ELSE 0 END) AS revenue,
                SUM(CASE WHEN accounts.type = 'expense' THEN (ledger_entries.debit_amount - ledger_entries.credit_amount) ELSE 0 END) AS expenses
            ")
            ->first();

        $totalRevenue = (float) ($rows->revenue ?? 0);
        $totalExpenses = (float) ($rows->expenses ?? 0);
        $poolProfit = $totalRevenue - $totalExpenses;

        return [
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'pool_profit' => $poolProfit,
        ];
    }

    /**
     * Same as getProjectProfitFromLedger but excludes COGS expense accounts.
     * Used for settlement-vs-OT reconciliation so pool totals match OT (OT has no separate COGS line).
     *
     * @return array{total_revenue: float, total_expenses: float, pool_profit: float}
     */
    public function getProjectProfitFromLedgerExcludingCOGS(string $projectId, string $tenantId, string $upToDate): array
    {
        $pgIds = PostingGroup::where('tenant_id', $tenantId)
            ->where('posting_date', '<=', $upToDate)
            ->whereIn('source_type', self::OPERATIONAL_SOURCE_TYPES)
            ->whereExists(function ($q) use ($projectId) {
                $q->select(DB::raw(1))
                    ->from('allocation_rows')
                    ->whereColumn('allocation_rows.posting_group_id', 'posting_groups.id')
                    ->where('allocation_rows.project_id', $projectId);
            })
            ->pluck('id');

        if ($pgIds->isEmpty()) {
            return [
                'total_revenue' => 0.0,
                'total_expenses' => 0.0,
                'pool_profit' => 0.0,
            ];
        }

        $cogsCodes = config('reconciliation.cogs_account_codes', ['COGS_PRODUCE']);
        $placeholders = implode(',', array_fill(0, count($cogsCodes), '?'));

        $rows = DB::table('ledger_entries')
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.tenant_id', $tenantId)
            ->whereIn('ledger_entries.posting_group_id', $pgIds)
            ->whereIn('accounts.type', ['income', 'expense'])
            ->whereRaw("accounts.code NOT IN ({$placeholders})", $cogsCodes)
            ->selectRaw("
                SUM(CASE WHEN accounts.type = 'income' THEN (ledger_entries.credit_amount - ledger_entries.debit_amount) ELSE 0 END) AS revenue,
                SUM(CASE WHEN accounts.type = 'expense' THEN (ledger_entries.debit_amount - ledger_entries.credit_amount) ELSE 0 END) AS expenses
            ")
            ->first();

        $totalRevenue = (float) ($rows->revenue ?? 0);
        $totalExpenses = (float) ($rows->expenses ?? 0);
        $poolProfit = $totalRevenue - $totalExpenses;

        return [
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'pool_profit' => $poolProfit,
        ];
    }

    /**
     * Preview settlement calculations for a project (canonical ledger-based).
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
            ->with('party')
            ->firstOrFail();

        $projectRule = ProjectRule::where('project_id', $projectId)->first();
        if (!$projectRule) {
            throw new \Exception('Project rules not found');
        }

        $isOwnerOperated = $projectRule->profit_split_hari_pct == 0 ||
            ($project->party && !in_array('HARI', $project->party->party_types ?? []));

        $upToDateStr = $upToDate ? Carbon::parse($upToDate)->format('Y-m-d') : Carbon::today()->format('Y-m-d');

        // Canonical profit from ledger (income/expense only)
        $ledger = $this->getProjectProfitFromLedger($projectId, $tenantId, $upToDateStr);
        $poolProfit = $ledger['pool_profit'];
        $totalRevenue = $ledger['total_revenue'];
        $totalExpenses = $ledger['total_expenses'];

        // Apply Decision D: kamdari first, then split remainder
        $kamdariAmount = $poolProfit * ($projectRule->kamdari_pct / 100);
        $remainingPool = $poolProfit - $kamdariAmount;

        if ($isOwnerOperated) {
            $landlordGross = $remainingPool;
            $hariGross = 0.0;
            $hariNet = 0.0;
        } else {
            $landlordGross = $remainingPool * ($projectRule->profit_split_landlord_pct / 100);
            $hariGross = $remainingPool * ($projectRule->profit_split_hari_pct / 100);
            $hariNet = $hariGross; // No separate hari_only deduction; expense already in ledger
        }

        return [
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'pool_revenue' => $totalRevenue,
            'shared_costs' => 0.0, // Legacy key; not used when using ledger
            'landlord_only_costs' => 0.0,
            'pool_profit' => $poolProfit,
            'kamdari_amount' => $kamdariAmount,
            'remaining_pool' => $remainingPool,
            'landlord_gross' => $landlordGross,
            'landlord_net' => $landlordGross,
            'hari_gross' => $hariGross,
            'hari_only_deductions' => 0.0,
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
            ->with('party')
            ->firstOrFail();

        $projectRule = ProjectRule::where('project_id', $projectId)->first();
        $isOwnerOperated = $projectRule && (
            $projectRule->profit_split_hari_pct == 0 || 
            ($project->party && !in_array('HARI', $project->party->party_types ?? []))
        );

        // For owner-operated projects, return zeros
        if ($isOwnerOperated) {
            return [
                'hari_party_id' => null,
                'hari_payable_amount' => 0,
                'outstanding_advance' => 0,
                'suggested_offset' => 0,
                'max_offset' => 0,
            ];
        }

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
                ->with('party')
                ->firstOrFail();

            $projectRule = ProjectRule::where('project_id', $projectId)->first();
            if (!$projectRule) {
                throw new \Exception('Project rules not found');
            }

            // Check if project is owner-operated (no HARI party or hari_pct is 0)
            $isOwnerOperated = $projectRule->profit_split_hari_pct == 0 || 
                              ($project->party && !in_array('HARI', $project->party->party_types ?? []));

            $this->guard->ensureCropCycleOpenForProject($projectId, $tenantId);

            $cropCycle = CropCycle::where('id', $project->crop_cycle_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            // Ensure landlord party exists
            $landlordParty = $this->partyService->ensureSystemLandlordParty($tenantId);

            // Calculate settlement amounts
            $upToDateObj = $upToDate ? Carbon::parse($upToDate) : Carbon::parse($postingDate);
            $calculations = $this->previewSettlement($projectId, $tenantId, $upToDateObj->format('Y-m-d'));

            // Validate and process offset if requested (only for HARI projects)
            $offsetAmount = 0;
            $hariPartyId = $project->party_id;
            
            if ($applyAdvanceOffset && !$isOwnerOperated) {
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
            } elseif ($applyAdvanceOffset && $isOwnerOperated) {
                throw new \Exception('Advance offset is not applicable for owner-operated projects');
            }

            // Settlement uses PROFIT_DISTRIBUTION_CLEARING and PARTY_CONTROL_* only (never in operational postings)
            $clearingAccount = $this->accountService->getByCode($tenantId, 'PROFIT_DISTRIBUTION_CLEARING');
            $partyControlLandlord = $this->partyAccountService->getPartyControlAccountByRole($tenantId, 'LANDLORD');
            $partyControlHari = null;
            if (!$isOwnerOperated) {
                $partyControlHari = $this->partyAccountService->getPartyControlAccountByRole($tenantId, 'HARI');
            }
            $partyControlKamdar = null;
            if ($projectRule->kamdar_party_id && $calculations['kamdari_amount'] > 0) {
                try {
                    $partyControlKamdar = $this->partyAccountService->getPartyControlAccountByRole($tenantId, 'KAMDAR');
                } catch (\Exception $e) {
                    // Account might not exist
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

            // Only create allocation rows and ledger entries for non-negative distribution (loss = 0 distribution)
            $landlordGross = max(0, $calculations['landlord_gross']);
            $hariNet = max(0, $calculations['hari_net']);
            $kamdariAmount = max(0, $calculations['kamdari_amount']);
            $totalDistribution = $landlordGross + $hariNet + $kamdariAmount;

            if ($totalDistribution < 0.01) {
                throw new \Exception('No profit to distribute; settlement has no positive distribution amount to post.');
            }

            // When advance offset applies: post net amounts only (no self-offsetting entries)
            $effectiveTotalDistribution = $totalDistribution;
            $effectiveHariCredit = $hariNet;
            if ($offsetAmount > 0 && !$isOwnerOperated) {
                $effectiveTotalDistribution = $totalDistribution - $offsetAmount;
                $effectiveHariCredit = $hariNet - $offsetAmount;
            }

            // KAMDARI allocation (if applicable)
            if ($projectRule->kamdar_party_id && $kamdariAmount > 0) {
                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $projectId,
                    'party_id' => $projectRule->kamdar_party_id,
                    'allocation_type' => 'KAMDARI',
                    'amount' => $kamdariAmount,
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
                'amount' => $landlordGross,
                'rule_snapshot' => $ruleSnapshot,
            ]);

            // POOL_SHARE for hari (only if not owner-operated)
            if (!$isOwnerOperated && $hariNet > 0) {
                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $projectId,
                    'party_id' => $project->party_id,
                    'allocation_type' => 'POOL_SHARE',
                    'amount' => $hariNet,
                    'rule_snapshot' => $ruleSnapshot,
                ]);
            }

            // HARI_ONLY deductions (for statement clarity, only if not owner-operated)
            if (!$isOwnerOperated && $calculations['hari_only_deductions'] > 0) {
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

            $ledgerLines = [
                ['account_id' => $clearingAccount->id],
                ['account_id' => $partyControlLandlord->id],
            ];
            if (!$isOwnerOperated && $effectiveHariCredit > 0) {
                $ledgerLines[] = ['account_id' => $partyControlHari->id];
            }
            if ($partyControlKamdar && $kamdariAmount > 0) {
                $ledgerLines[] = ['account_id' => $partyControlKamdar->id];
            }
            $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

            // Create ledger entries: Dr PROFIT_DISTRIBUTION_CLEARING, Cr PARTY_CONTROL_* (credit = we owe them)
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $clearingAccount->id,
                'debit_amount' => $effectiveTotalDistribution,
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $partyControlLandlord->id,
                'debit_amount' => 0,
                'credit_amount' => $landlordGross,
                'currency_code' => 'GBP',
            ]);

            if (!$isOwnerOperated && $effectiveHariCredit > 0) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $partyControlHari->id,
                    'debit_amount' => 0,
                    'credit_amount' => $effectiveHariCredit,
                    'currency_code' => 'GBP',
                ]);
            }

            // Record advance offset for audit only (no self-offsetting ledger entries)
            if ($offsetAmount > 0 && !$isOwnerOperated) {
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
                SettlementOffset::create([
                    'tenant_id' => $tenantId,
                    'settlement_id' => $settlement->id,
                    'party_id' => $hariPartyId,
                    'posting_group_id' => $postingGroup->id,
                    'posting_date' => Carbon::parse($postingDate)->format('Y-m-d'),
                    'offset_amount' => $offsetAmount,
                ]);
            }

            if ($partyControlKamdar && $kamdariAmount > 0) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $partyControlKamdar->id,
                    'debit_amount' => 0,
                    'credit_amount' => $kamdariAmount,
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

    /**
     * Preview crop cycle settlement: aggregate profit from all projects in the cycle (ledger-based).
     *
     * @return array{total_revenue: float, total_expenses: float, pool_profit: float, landlord_gross: float, hari_net: float, kamdari_amount: float, projects: array}
     */
    public function previewCropCycleSettlement(string $cropCycleId, string $tenantId, string $upToDate): array
    {
        $this->guard->ensureCropCycleOpen($cropCycleId, $tenantId);

        $cropCycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();
        $projects = Project::where('crop_cycle_id', $cropCycleId)->where('tenant_id', $tenantId)->with('party')->get();
        $landlordParty = $this->partyService->ensureSystemLandlordParty($tenantId);

        $totalRevenue = 0.0;
        $totalExpenses = 0.0;
        $landlordGross = 0.0;
        $hariNet = 0.0;
        $kamdariAmount = 0.0;
        $projectSummaries = [];

        foreach ($projects as $project) {
            $rule = ProjectRule::where('project_id', $project->id)->first();
            if (!$rule) {
                continue;
            }
            $ledger = $this->getProjectProfitFromLedger($project->id, $tenantId, $upToDate);
            $poolProfit = $ledger['pool_profit'];
            $totalRevenue += $ledger['total_revenue'];
            $totalExpenses += $ledger['total_expenses'];

            $isOwnerOperated = $rule->profit_split_hari_pct == 0 ||
                ($project->party && !in_array('HARI', $project->party->party_types ?? []));
            $kamdari = $poolProfit * ($rule->kamdari_pct / 100);
            $remaining = $poolProfit - $kamdari;
            $kamdariAmount += $kamdari;

            if ($isOwnerOperated) {
                $landlordGross += $remaining;
                $projectSummaries[] = ['project_id' => $project->id, 'landlord_gross' => $remaining, 'hari_net' => 0.0, 'kamdari_amount' => $kamdari];
            } else {
                $lg = $remaining * ($rule->profit_split_landlord_pct / 100);
                $hn = $remaining * ($rule->profit_split_hari_pct / 100);
                $landlordGross += $lg;
                $hariNet += $hn;
                $projectSummaries[] = ['project_id' => $project->id, 'landlord_gross' => $lg, 'hari_net' => $hn, 'kamdari_amount' => $kamdari];
            }
        }

        return [
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'pool_profit' => $totalRevenue - $totalExpenses,
            'landlord_gross' => $landlordGross,
            'hari_net' => $hariNet,
            'kamdari_amount' => $kamdariAmount,
            'projects' => $projectSummaries,
        ];
    }

    /**
     * Post one settlement for the entire crop cycle (one PostingGroup, idempotent by idempotency_key).
     *
     * @param string $cropCycleId
     * @param string $tenantId
     * @param string $postingDate YYYY-MM-DD
     * @param string $idempotencyKey
     * @return array{posting_group: PostingGroup}
     */
    public function settleCropCycle(string $cropCycleId, string $tenantId, string $postingDate, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($cropCycleId, $tenantId, $postingDate, $idempotencyKey) {
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return ['posting_group' => $existing->load(['allocationRows', 'ledgerEntries.account'])];
            }

            $this->guard->ensureCropCycleOpen($cropCycleId, $tenantId);

            $cropCycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();
            $upToDate = Carbon::parse($postingDate)->format('Y-m-d');
            $preview = $this->previewCropCycleSettlement($cropCycleId, $tenantId, $upToDate);
            $totalDistribution = $preview['landlord_gross'] + $preview['hari_net'] + $preview['kamdari_amount'];

            if (abs($totalDistribution) < 0.01) {
                throw new \Exception('No distribution amount to post for this crop cycle as of the given date');
            }

            $clearingAccount = $this->accountService->getByCode($tenantId, 'PROFIT_DISTRIBUTION_CLEARING');
            $partyControlLandlord = $this->partyAccountService->getPartyControlAccountByRole($tenantId, 'LANDLORD');
            $partyControlHari = $this->partyAccountService->getPartyControlAccountByRole($tenantId, 'HARI');
            $partyControlKamdar = null;
            try {
                $partyControlKamdar = $this->partyAccountService->getPartyControlAccountByRole($tenantId, 'KAMDAR');
            } catch (\Exception $e) {
            }

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $cropCycleId,
                'source_type' => 'CROP_CYCLE_SETTLEMENT',
                'source_id' => $cropCycleId,
                'posting_date' => $upToDate,
                'idempotency_key' => $idempotencyKey,
            ]);

            $cropCycleLedgerLines = [
                ['account_id' => $clearingAccount->id],
                ['account_id' => $partyControlLandlord->id],
            ];
            if ($preview['hari_net'] > 0.01) {
                $cropCycleLedgerLines[] = ['account_id' => $partyControlHari->id];
            }
            if ($partyControlKamdar && $preview['kamdari_amount'] > 0.01) {
                $cropCycleLedgerLines[] = ['account_id' => $partyControlKamdar->id];
            }
            $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $cropCycleLedgerLines);

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $clearingAccount->id,
                'debit_amount' => $totalDistribution,
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $partyControlLandlord->id,
                'debit_amount' => 0,
                'credit_amount' => $preview['landlord_gross'],
                'currency_code' => 'GBP',
            ]);
            if ($preview['hari_net'] > 0.01) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $partyControlHari->id,
                    'debit_amount' => 0,
                    'credit_amount' => $preview['hari_net'],
                    'currency_code' => 'GBP',
                ]);
            }
            if ($partyControlKamdar && $preview['kamdari_amount'] > 0.01) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $partyControlKamdar->id,
                    'debit_amount' => 0,
                    'credit_amount' => $preview['kamdari_amount'],
                    'currency_code' => 'GBP',
                ]);
            }

            $totalDebits = LedgerEntry::where('posting_group_id', $postingGroup->id)->sum('debit_amount');
            $totalCredits = LedgerEntry::where('posting_group_id', $postingGroup->id)->sum('credit_amount');
            if (abs($totalDebits - $totalCredits) > 0.01) {
                throw new \Exception('Debits and credits do not balance');
            }

            return ['posting_group' => $postingGroup->load(['allocationRows', 'ledgerEntries.account'])];
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

            // Create settlement (project_id optional for sales-based; posting_group_id set on post)
            // Legacy columns (pool_revenue, etc.) are NOT NULL in schema; use 0 for sales-based DRAFT
            $settlement = Settlement::create([
                'tenant_id' => $tenantId,
                'project_id' => $data['project_id'] ?? $sales->first()?->project_id,
                'pool_revenue' => 0,
                'shared_costs' => 0,
                'pool_profit' => 0,
                'kamdari_amount' => 0,
                'landlord_share' => 0,
                'hari_share' => 0,
                'hari_only_deductions' => 0,
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
            // Idempotent: already posted -> return existing result
            if ($settlement->status === 'POSTED' && $settlement->posting_group_id) {
                $postingGroup = PostingGroup::with(['allocationRows', 'ledgerEntries.account'])->find($settlement->posting_group_id);
                if ($postingGroup) {
                    return [
                        'settlement' => $settlement->fresh(['postingGroup', 'lines.party']),
                        'posting_group' => $postingGroup,
                    ];
                }
            }

            // Assert status is DRAFT
            if ($settlement->status !== 'DRAFT') {
                throw new \Exception('Settlement must be in DRAFT status to post');
            }

            // Ensure referenced sales are POSTED and not already settled
            $saleIds = $settlement->sales->pluck('id')->toArray();
            $this->checkSalesAlreadySettled($saleIds, $settlement->tenant_id);

            if ($settlement->crop_cycle_id) {
                $this->guard->ensureCropCycleOpen($settlement->crop_cycle_id, $settlement->tenant_id);
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

            // Settlement uses PROFIT_DISTRIBUTION_CLEARING only (same as project/crop cycle settlements)
            $settlementClearingAccount = $this->accountService->getByCode($settlement->tenant_id, 'PROFIT_DISTRIBUTION_CLEARING');
            
            // Use AP (Accounts Payable) as generic payable account
            // Note: ACCOUNTS_PAYABLE account should be added to SystemAccountsSeeder in future
            try {
                $accountsPayableAccount = $this->accountService->getByCode($settlement->tenant_id, 'ACCOUNTS_PAYABLE');
            } catch (\Exception $e) {
                // Fallback to AP if ACCOUNTS_PAYABLE doesn't exist
                $accountsPayableAccount = $this->accountService->getByCode($settlement->tenant_id, 'AP');
            }

            $this->postValidationService->validateNoDeprecatedAccounts($settlement->tenant_id, [
                ['account_id' => $settlementClearingAccount->id],
                ['account_id' => $accountsPayableAccount->id],
            ]);

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

            if ($settlement->crop_cycle_id) {
                $this->guard->ensureCropCycleOpen($settlement->crop_cycle_id, $settlement->tenant_id);
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

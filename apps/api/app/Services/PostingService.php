<?php

namespace App\Services;

use App\Models\OperationalTransaction;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\CropCycle;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class PostingService
{
    public function __construct(
        private SystemAccountService $accountService,
        private SystemPartyService $partyService
    ) {}

    /**
     * Post an OperationalTransaction to the accounting system.
     * 
     * This is idempotent: if a posting_group exists for tenant+idempotency_key, returns existing.
     * 
     * @param string $transactionId
     * @param string $tenantId
     * @param string $postingDate YYYY-MM-DD format
     * @param string $idempotencyKey
     * @return PostingGroup
     * @throws \Exception
     */
    public function postOperationalTransaction(
        string $transactionId,
        string $tenantId,
        string $postingDate,
        string $idempotencyKey
    ): PostingGroup {
        return DB::transaction(function () use ($transactionId, $tenantId, $postingDate, $idempotencyKey) {
            // Check idempotency first
            $existingPostingGroup = PostingGroup::where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingPostingGroup) {
                return $existingPostingGroup->load(['allocationRows', 'ledgerEntries.account']);
            }

            // Load transaction, must be DRAFT and belong to tenant
            $transaction = OperationalTransaction::where('id', $transactionId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'DRAFT')
                ->firstOrFail();

            // Determine crop_cycle_id
            $cropCycleId = $transaction->crop_cycle_id;
            if (!$cropCycleId && $transaction->project_id) {
                $project = Project::where('id', $transaction->project_id)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();
                $cropCycleId = $project->crop_cycle_id;
            }

            if (!$cropCycleId) {
                throw new \Exception('Crop cycle ID is required for posting');
            }

            // Verify crop cycle exists and is OPEN
            $cropCycle = CropCycle::where('id', $cropCycleId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            if ($cropCycle->status !== 'OPEN') {
                throw new \Exception('Cannot post to a closed crop cycle');
            }

            // Validate posting_date is within crop cycle range (if dates are set)
            $postingDateObj = \Carbon\Carbon::parse($postingDate);
            if ($cropCycle->start_date && $postingDateObj->lt($cropCycle->start_date)) {
                throw new \Exception('Posting date is before crop cycle start date');
            }
            if ($cropCycle->end_date && $postingDateObj->gt($cropCycle->end_date)) {
                throw new \Exception('Posting date is after crop cycle end date');
            }

            // Get system accounts
            $cashAccount = $this->accountService->getByCode($tenantId, 'CASH');
            $projectRevenueAccount = $this->accountService->getByCode($tenantId, 'PROJECT_REVENUE');
            $expSharedAccount = $this->accountService->getByCode($tenantId, 'EXP_SHARED');
            $expHariOnlyAccount = $this->accountService->getByCode($tenantId, 'EXP_HARI_ONLY');
            $expFarmOverheadAccount = $this->accountService->getByCode($tenantId, 'EXP_FARM_OVERHEAD');

            // Create posting group
            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $cropCycleId,
                'source_type' => 'OPERATIONAL',
                'source_id' => $transactionId,
                'posting_date' => $postingDateObj->format('Y-m-d'),
                'idempotency_key' => $idempotencyKey,
            ]);

            // Determine project_id for allocation rows
            $projectId = $transaction->project_id;
            
            // For FARM_OVERHEAD, allocation_rows still requires project_id
            // We'll use the first project in the crop cycle as a placeholder
            // This is a workaround since allocation_rows.project_id is NOT NULL
            if (!$projectId && $transaction->classification === 'FARM_OVERHEAD') {
                $firstProject = Project::where('tenant_id', $tenantId)
                    ->where('crop_cycle_id', $cropCycleId)
                    ->first();
                if ($firstProject) {
                    $projectId = $firstProject->id;
                } else {
                    throw new \Exception('Cannot post FARM_OVERHEAD: no projects exist in crop cycle');
                }
            }

            // Create allocation row based on classification
            $allocationType = null;
            $partyId = null;
            $ruleSnapshot = [];

            if ($transaction->classification === 'SHARED') {
                $allocationType = 'POOL_SHARE';
                // For SHARED transactions, use project's party_id as placeholder
                // The actual split will be calculated during settlement
                $project = Project::where('id', $projectId)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();
                $partyId = $project->party_id;
                $ruleSnapshot = ['classification' => 'SHARED'];
            } elseif ($transaction->classification === 'HARI_ONLY') {
                $allocationType = 'HARI_ONLY';
                $project = Project::where('id', $projectId)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();
                $partyId = $project->party_id;
                $ruleSnapshot = ['classification' => 'HARI_ONLY'];
            } elseif ($transaction->classification === 'FARM_OVERHEAD') {
                $allocationType = 'HARI_ONLY'; // Use HARI_ONLY type for farm overhead
                $landlordParty = $this->partyService->ensureSystemLandlordParty($tenantId);
                $partyId = $landlordParty->id;
                $ruleSnapshot = ['classification' => 'FARM_OVERHEAD'];
            }

            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $projectId,
                'party_id' => $partyId,
                'allocation_type' => $allocationType,
                'amount' => $transaction->amount,
                'rule_snapshot' => $ruleSnapshot,
            ]);

            // Create ledger entries per Decision C (cash-based)
            if ($transaction->type === 'INCOME') {
                // Dr CASH, Cr PROJECT_REVENUE
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $cashAccount->id,
                    'debit_amount' => $transaction->amount,
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $projectRevenueAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $transaction->amount,
                    'currency_code' => 'GBP',
                ]);
            } else {
                // EXPENSE
                $expenseAccount = null;
                if ($transaction->classification === 'SHARED') {
                    $expenseAccount = $expSharedAccount;
                } elseif ($transaction->classification === 'HARI_ONLY') {
                    $expenseAccount = $expHariOnlyAccount;
                } elseif ($transaction->classification === 'FARM_OVERHEAD') {
                    $expenseAccount = $expFarmOverheadAccount;
                }

                if (!$expenseAccount) {
                    throw new \Exception('Invalid expense classification');
                }

                // Dr expense account, Cr CASH
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $expenseAccount->id,
                    'debit_amount' => $transaction->amount,
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $cashAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $transaction->amount,
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

            // Update transaction status to POSTED
            $transaction->update(['status' => 'POSTED']);

            // Reload posting group with relationships
            return $postingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
        });
    }
}

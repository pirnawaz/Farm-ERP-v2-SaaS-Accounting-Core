<?php

namespace App\Services;

use App\Models\Advance;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\LedgerEntry;
use App\Models\CropCycle;
use App\Models\AllocationRow;
use App\Services\Accounting\PostValidationService;
use App\Services\OperationalPostingGuard;
use App\Services\PartyAccountService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdvanceService
{
    public function __construct(
        private SystemAccountService $accountService,
        private PartyAccountService $partyAccountService,
        private PostValidationService $postValidationService
    ) {}

    /**
     * Post an advance to the accounting system.
     * 
     * This is idempotent: if a posting_group exists for tenant+idempotency_key, returns existing.
     * 
     * @param string $advanceId
     * @param string $tenantId
     * @param string $postingDate YYYY-MM-DD format
     * @param string $idempotencyKey
     * @param string|null $cropCycleId Required if advance has no project_id
     * @param string|null $userRole For role validation
     * @return PostingGroup
     * @throws \Exception
     */
    public function postAdvance(
        string $advanceId,
        string $tenantId,
        string $postingDate,
        string $idempotencyKey,
        ?string $cropCycleId = null,
        ?string $userRole = null
    ): PostingGroup {
        return DB::transaction(function () use ($advanceId, $tenantId, $postingDate, $idempotencyKey, $cropCycleId, $userRole) {
            // Validate role
            if ($userRole && !in_array($userRole, ['accountant', 'tenant_admin'])) {
                throw new \Exception('Only accountant or tenant_admin can post advances');
            }

            // Check idempotency first
            $existingPostingGroup = PostingGroup::where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingPostingGroup) {
                // Ensure advance is linked
                $advance = Advance::where('id', $advanceId)
                    ->where('tenant_id', $tenantId)
                    ->first();
                if ($advance && $advance->status !== 'POSTED') {
                    $advance->update([
                        'status' => 'POSTED',
                        'posting_group_id' => $existingPostingGroup->id,
                        'posted_at' => now(),
                    ]);
                }
                return $existingPostingGroup->load(['ledgerEntries.account', 'allocationRows']);
            }

            // Load advance, must be DRAFT and belong to tenant
            $advance = Advance::where('id', $advanceId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'DRAFT')
                ->firstOrFail();

            // Load party
            $party = Party::where('id', $advance->party_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            // Determine crop_cycle_id
            $finalCropCycleId = null;
            if ($advance->project_id) {
                $project = \App\Models\Project::where('id', $advance->project_id)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();
                $finalCropCycleId = $project->crop_cycle_id;
            } elseif ($advance->crop_cycle_id) {
                $finalCropCycleId = $advance->crop_cycle_id;
            } else {
                // Require crop_cycle_id parameter if not set on advance
                if (!$cropCycleId) {
                    throw new \Exception('Crop cycle ID is required when advance has no project or crop cycle');
                }
                $finalCropCycleId = $cropCycleId;
            }

            $this->guard->ensureCropCycleOpen($finalCropCycleId, $tenantId);

            $cropCycle = CropCycle::where('id', $finalCropCycleId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            // Use PARTY_CONTROL_* for party advances; other types use dedicated accounts
            $advanceAccount = null;
            switch ($advance->type) {
                case 'HARI_ADVANCE':
                    $advanceAccount = $this->partyAccountService->getPartyControlAccountByRole($tenantId, 'HARI');
                    break;
                case 'VENDOR_ADVANCE':
                    $advanceAccount = $this->accountService->getByCode($tenantId, 'ADVANCE_VENDOR');
                    break;
                case 'LOAN':
                    $advanceAccount = $this->accountService->getByCode($tenantId, 'LOAN_RECEIVABLE');
                    break;
                default:
                    throw new \Exception("Invalid advance type: {$advance->type}");
            }

            // Get CASH account (for now, always use CASH - method field can be used for reporting)
            $cashAccount = $this->accountService->getByCode($tenantId, 'CASH');

            $this->postValidationService->validateNoDeprecatedAccounts($tenantId, [
                ['account_id' => $advanceAccount->id],
                ['account_id' => $cashAccount->id],
            ]);

            // Create posting group
            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $finalCropCycleId,
                'source_type' => 'ADVANCE',
                'source_id' => $advance->id,
                'posting_date' => Carbon::parse($postingDate)->format('Y-m-d'),
                'idempotency_key' => $idempotencyKey,
            ]);

            // Create allocation row
            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $advance->project_id,
                'party_id' => $advance->party_id,
                'allocation_type' => 'ADVANCE',
                'amount' => $advance->amount,
            ]);

            // Create ledger entries
            if ($advance->direction === 'OUT') {
                // Disbursement: Debit ADVANCE_ASSET, Credit CASH
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $advanceAccount->id,
                    'debit_amount' => $advance->amount,
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $cashAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $advance->amount,
                    'currency_code' => 'GBP',
                ]);
            } else {
                // Repayment: Debit CASH, Credit ADVANCE_ASSET
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $cashAccount->id,
                    'debit_amount' => $advance->amount,
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $advanceAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $advance->amount,
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

            // Update advance status to POSTED
            $advance->update([
                'status' => 'POSTED',
                'posting_group_id' => $postingGroup->id,
                'posted_at' => now(),
            ]);

            // Reload posting group with relationships
            return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
        });
    }
}

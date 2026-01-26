<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\LedgerEntry;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SaleService
{
    public function __construct(
        private SystemAccountService $accountService,
        private SaleCOGSService $cogsService
    ) {}

    /**
     * Post a sale to the accounting system.
     * 
     * This is idempotent: if a posting_group exists for tenant+idempotency_key, returns existing.
     * 
     * @param string $saleId
     * @param string $tenantId
     * @param string $postingDate YYYY-MM-DD format
     * @param string $idempotencyKey
     * @param string|null $userRole For role validation
     * @return PostingGroup
     * @throws \Exception
     */
    public function postSale(
        string $saleId,
        string $tenantId,
        string $postingDate,
        string $idempotencyKey,
        ?string $userRole = null
    ): PostingGroup {
        return DB::transaction(function () use ($saleId, $tenantId, $postingDate, $idempotencyKey, $userRole) {
            // Validate role
            if ($userRole && !in_array($userRole, ['accountant', 'tenant_admin'])) {
                throw new \Exception('Only accountant or tenant_admin can post sales');
            }

            // Check idempotency first
            $existingPostingGroup = PostingGroup::where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingPostingGroup) {
                // Ensure sale is linked
                $sale = Sale::where('id', $saleId)
                    ->where('tenant_id', $tenantId)
                    ->first();
                if ($sale && $sale->status !== 'POSTED') {
                    $sale->update([
                        'status' => 'POSTED',
                        'posting_group_id' => $existingPostingGroup->id,
                        'posted_at' => now(),
                    ]);
                }
                return $existingPostingGroup->load(['ledgerEntries.account', 'allocationRows']);
            }

            // Load sale, must be DRAFT and belong to tenant
            $sale = Sale::where('id', $saleId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'DRAFT')
                ->with('lines')
                ->firstOrFail();

            // If sale has lines, use COGS service
            if ($sale->lines->isNotEmpty()) {
                return $this->cogsService->postSaleWithCOGS($sale, $postingDate, $idempotencyKey);
            }

            // Load buyer party
            $buyerParty = Party::where('id', $sale->buyer_party_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            // Determine crop_cycle_id
            $finalCropCycleId = null;
            if ($sale->project_id) {
                $project = Project::where('id', $sale->project_id)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();
                $finalCropCycleId = $project->crop_cycle_id;
            } elseif ($sale->crop_cycle_id) {
                $finalCropCycleId = $sale->crop_cycle_id;
            }

            // If crop cycle is set, verify it exists and posting_date is within cycle
            if ($finalCropCycleId) {
                $cropCycle = CropCycle::where('id', $finalCropCycleId)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();

                $postingDateObj = Carbon::parse($postingDate);
                if ($cropCycle->start_date && $postingDateObj->lt($cropCycle->start_date)) {
                    throw new \Exception('Posting date must be within crop cycle start date');
                }
                if ($cropCycle->end_date && $postingDateObj->gt($cropCycle->end_date)) {
                    throw new \Exception('Posting date must be within crop cycle end date');
                }
            }

            // Get accounts
            $arAccount = $this->accountService->getByCode($tenantId, 'AR');
            $revenueAccount = $this->accountService->getByCode($tenantId, 'PROJECT_REVENUE');

            // Create posting group
            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $finalCropCycleId,
                'source_type' => 'SALE',
                'source_id' => $sale->id,
                'posting_date' => Carbon::parse($postingDate)->format('Y-m-d'),
                'idempotency_key' => $idempotencyKey,
            ]);

            // Create allocation row for revenue
            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $sale->project_id,
                'party_id' => $sale->buyer_party_id,
                'allocation_type' => 'SALE_REVENUE',
                'amount' => $sale->amount,
            ]);

            // Create ledger entries
            // Debit: ACCOUNTS_RECEIVABLE (buyer owes us)
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $arAccount->id,
                'debit_amount' => $sale->amount,
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);

            // Credit: PROJECT_REVENUE (income already earned)
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $revenueAccount->id,
                'debit_amount' => 0,
                'credit_amount' => $sale->amount,
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

            // Update sale status to POSTED
            $sale->update([
                'status' => 'POSTED',
                'posting_group_id' => $postingGroup->id,
                'posted_at' => now(),
            ]);

            // Reload posting group with relationships
            return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
        });
    }
}

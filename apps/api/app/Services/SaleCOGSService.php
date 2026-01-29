<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleInventoryAllocation;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\InvStockMovement;
use App\Models\InvStockBalance;
use App\Models\OperationalTransaction;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SaleCOGSService
{
    public function __construct(
        private SystemAccountService $accountService,
        private InventoryStockService $stockService,
        private ReversalService $reversalService
    ) {}

    /**
     * Compute Weighted Average Cost for an item at a specific date.
     * Computes from stock movements up to (and including) the asOfDate.
     *
     * @param string $tenantId
     * @param string $itemId
     * @param string $storeId
     * @param string $asOfDate YYYY-MM-DD format
     * @return array{qty_on_hand: float, value_on_hand: float, wac: float}
     */
    public function computeWACAtDate(string $tenantId, string $itemId, string $storeId, string $asOfDate): array
    {
        $asOfDateTime = Carbon::parse($asOfDate)->endOfDay();

        // Sum all movements up to asOfDate
        $result = InvStockMovement::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('store_id', $storeId)
            ->where('occurred_at', '<=', $asOfDateTime)
            ->selectRaw('SUM(qty_delta) as qty_on_hand, SUM(value_delta) as value_on_hand')
            ->first();

        $qtyOnHand = (float) ($result->qty_on_hand ?? 0);
        $valueOnHand = (float) ($result->value_on_hand ?? 0);
        $wac = $qtyOnHand > 0 ? $valueOnHand / $qtyOnHand : 0;

        return [
            'qty_on_hand' => $qtyOnHand,
            'value_on_hand' => $valueOnHand,
            'wac' => $wac,
        ];
    }

    /**
     * Validate that a sale can be posted.
     *
     * @throws \Exception
     */
    public function validateSalePosting(Sale $sale, string $postingDate): void
    {
        // Sale status must be DRAFT
        if (!$sale->isDraft()) {
            throw new \Exception('Only DRAFT sales can be posted.');
        }

        // Sale must have at least one line
        $sale->load('lines.item', 'lines.store');
        if ($sale->lines->isEmpty()) {
            throw new \Exception('Sale must have at least one line to post.');
        }

        // Validate all lines have required fields
        foreach ($sale->lines as $line) {
            if (!$line->inventory_item_id) {
                throw new \Exception('All sale lines must have an inventory item.');
            }
            if (!$line->store_id) {
                throw new \Exception('All sale lines must have a store.');
            }
            if ($line->quantity <= 0) {
                throw new \Exception('All sale lines must have quantity > 0.');
            }
        }

        // Check stock availability for each line
        foreach ($sale->lines as $line) {
            $stock = $this->computeWACAtDate(
                $sale->tenant_id,
                $line->inventory_item_id,
                $line->store_id,
                $postingDate
            );

            if ($stock['qty_on_hand'] < $line->quantity) {
                $itemName = $line->item->name ?? 'Unknown';
                throw new \Exception(
                    "Insufficient stock for item {$itemName}: on hand {$stock['qty_on_hand']}, required {$line->quantity}."
                );
            }
        }

        // Validate crop cycle if set
        if ($sale->crop_cycle_id) {
            $cropCycle = CropCycle::where('id', $sale->crop_cycle_id)
                ->where('tenant_id', $sale->tenant_id)
                ->firstOrFail();

            if ($cropCycle->status !== 'OPEN') {
                throw new \Exception('Cannot post sale: crop cycle is closed.');
            }

            $postingDateObj = Carbon::parse($postingDate);
            if ($cropCycle->start_date && $postingDateObj->lt($cropCycle->start_date)) {
                throw new \Exception('Posting date must be within crop cycle start date.');
            }
            if ($cropCycle->end_date && $postingDateObj->gt($cropCycle->end_date)) {
                throw new \Exception('Posting date must be within crop cycle end date.');
            }
        }
    }

    /**
     * Post a sale with COGS calculation and inventory reduction.
     * This is idempotent: if already posted, returns existing posting group.
     *
     * @throws \Exception
     */
    public function postSaleWithCOGS(Sale $sale, string $postingDate, string $idempotencyKey): PostingGroup
    {
        return DB::transaction(function () use ($sale, $postingDate, $idempotencyKey) {
            // Check idempotency first
            $existingPostingGroup = PostingGroup::where('tenant_id', $sale->tenant_id)
                ->where(function ($query) use ($sale, $idempotencyKey) {
                    $query->where('idempotency_key', $idempotencyKey)
                        ->orWhere(function ($q) use ($sale) {
                            $q->where('source_type', 'SALE')
                                ->where('source_id', $sale->id);
                        });
                })
                ->first();

            if ($existingPostingGroup) {
                // Already posted - return existing
                $sale->refresh();
                if ($sale->posting_group_id === $existingPostingGroup->id) {
                    return $existingPostingGroup->load(['ledgerEntries.account', 'allocationRows']);
                }
                // Update sale to link to existing posting group
                $sale->update([
                    'status' => 'POSTED',
                    'posting_date' => $postingDate,
                    'posted_at' => $sale->posted_at ?? now(),
                    'posting_group_id' => $existingPostingGroup->id,
                ]);
                return $existingPostingGroup->load(['ledgerEntries.account', 'allocationRows']);
            }

            // Validate sale posting
            $this->validateSalePosting($sale, $postingDate);

            // Load relationships
            $sale->load(['lines.item', 'lines.store', 'cropCycle', 'project']);

            $postingDateObj = Carbon::parse($postingDate);

            // Determine crop_cycle_id
            $finalCropCycleId = null;
            if ($sale->project_id) {
                $project = Project::where('id', $sale->project_id)
                    ->where('tenant_id', $sale->tenant_id)
                    ->firstOrFail();
                $finalCropCycleId = $project->crop_cycle_id;
            } elseif ($sale->crop_cycle_id) {
                $finalCropCycleId = $sale->crop_cycle_id;
            }

            // Get project for allocation rows (required for settlement)
            $project = null;
            if ($finalCropCycleId) {
                $project = Project::where('tenant_id', $sale->tenant_id)
                    ->where('crop_cycle_id', $finalCropCycleId)
                    ->first();
            }
            if (!$project) {
                throw new \Exception('Sale must have a project_id to be posted for settlement.');
            }

            // Create posting group
            $postingGroup = PostingGroup::create([
                'tenant_id' => $sale->tenant_id,
                'crop_cycle_id' => $finalCropCycleId,
                'source_type' => 'SALE',
                'source_id' => $sale->id,
                'posting_date' => $postingDateObj->format('Y-m-d'),
                'idempotency_key' => $idempotencyKey,
            ]);

            // Get accounts
            $arAccount = $this->accountService->getByCode($sale->tenant_id, 'AR');
            $revenueAccount = $this->accountService->getByCode($sale->tenant_id, 'PROJECT_REVENUE');
            $cogsAccount = $this->accountService->getByCode($sale->tenant_id, 'COGS_PRODUCE');
            $inventoryAccount = $this->accountService->getByCode($sale->tenant_id, 'INVENTORY_PRODUCE');

            $totalCogs = 0;
            $totalRevenue = 0;

            // Process each sale line
            foreach ($sale->lines as $index => $line) {
                // Compute WAC at posting date
                $stock = $this->computeWACAtDate(
                    $sale->tenant_id,
                    $line->inventory_item_id,
                    $line->store_id,
                    $postingDate
                );

                $unitCost = $stock['wac'];
                $totalCost = (float) $line->quantity * $unitCost;
                $totalCogs += $totalCost;
                $totalRevenue += (float) $line->line_total;

                // Create sale inventory allocation
                SaleInventoryAllocation::create([
                    'tenant_id' => $sale->tenant_id,
                    'sale_id' => $sale->id,
                    'sale_line_id' => $line->id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'crop_cycle_id' => $finalCropCycleId,
                    'store_id' => $line->store_id,
                    'quantity' => (string) $line->quantity,
                    'unit_cost' => (string) round($unitCost, 6),
                    'total_cost' => (string) round($totalCost, 2),
                    'costing_method' => 'WAC',
                    'posting_group_id' => $postingGroup->id,
                ]);

                // Create AllocationRow for COGS
                if ($project) {
                    AllocationRow::create([
                        'tenant_id' => $sale->tenant_id,
                        'posting_group_id' => $postingGroup->id,
                        'project_id' => $project->id,
                        'party_id' => $project->party_id,
                        'allocation_type' => 'SALE_COGS',
                        'amount' => (string) round($totalCost, 2),
                        'rule_snapshot' => [
                            'type' => 'SALE',
                            'sale_line_id' => $line->id,
                            'item_id' => $line->inventory_item_id,
                            'quantity' => (string) $line->quantity,
                            'unit_cost' => (string) round($unitCost, 6),
                            'costing_method' => 'WAC',
                        ],
                    ]);
                }

                // Create stock movement (negative qty/value for sale)
                $this->stockService->applyMovement(
                    $sale->tenant_id,
                    $postingGroup->id,
                    $line->store_id,
                    $line->inventory_item_id,
                    'SALE',
                    (string) (-(float) $line->quantity),
                    (string) (-round($totalCost, 2)),
                    (string) round($unitCost, 6),
                    $postingDateObj,
                    'sale',
                    $sale->id
                );

                // Create LedgerEntry for COGS (debit expense)
                if ($totalCost > 0.001) {
                    LedgerEntry::create([
                        'tenant_id' => $sale->tenant_id,
                        'posting_group_id' => $postingGroup->id,
                        'account_id' => $cogsAccount->id,
                        'debit_amount' => (string) round($totalCost, 2),
                        'credit_amount' => 0,
                        'currency_code' => 'GBP',
                    ]);
                }

                // Create LedgerEntry for Inventory (credit asset)
                if ($totalCost > 0.001) {
                    LedgerEntry::create([
                        'tenant_id' => $sale->tenant_id,
                        'posting_group_id' => $postingGroup->id,
                        'account_id' => $inventoryAccount->id,
                        'debit_amount' => 0,
                        'credit_amount' => (string) round($totalCost, 2),
                        'currency_code' => 'GBP',
                    ]);
                }
            }

            // Create AllocationRow for revenue (existing pattern)
            if ($project) {
                AllocationRow::create([
                    'tenant_id' => $sale->tenant_id,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $project->id,
                    'party_id' => $sale->buyer_party_id,
                    'allocation_type' => 'SALE_REVENUE',
                    'amount' => (string) round($totalRevenue, 2),
                    'rule_snapshot' => [
                        'type' => 'SALE',
                        'sale_id' => $sale->id,
                    ],
                ]);
            }

            // Create LedgerEntries for revenue (existing pattern)
            // Debit: AR
            LedgerEntry::create([
                'tenant_id' => $sale->tenant_id,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $arAccount->id,
                'debit_amount' => (string) round($totalRevenue, 2),
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);

            // Credit: Revenue
            LedgerEntry::create([
                'tenant_id' => $sale->tenant_id,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $revenueAccount->id,
                'debit_amount' => 0,
                'credit_amount' => (string) round($totalRevenue, 2),
                'currency_code' => 'GBP',
            ]);

            // Verify double-entry balance
            $totalDebits = LedgerEntry::where('posting_group_id', $postingGroup->id)
                ->sum('debit_amount');
            $totalCredits = LedgerEntry::where('posting_group_id', $postingGroup->id)
                ->sum('credit_amount');

            if (abs((float) $totalDebits - (float) $totalCredits) > 0.01) {
                throw new \Exception('Debits and credits do not balance.');
            }

            // Create INCOME OT for settlement (idempotent: one per posting group)
            $existingOt = OperationalTransaction::where('posting_group_id', $postingGroup->id)
                ->where('type', 'INCOME')
                ->first();
            if (!$existingOt) {
                OperationalTransaction::create([
                    'tenant_id' => $sale->tenant_id,
                    'project_id' => $project->id,
                    'crop_cycle_id' => $finalCropCycleId,
                    'type' => 'INCOME',
                    'status' => 'POSTED',
                    'transaction_date' => $postingDateObj->format('Y-m-d'),
                    'amount' => (string) round($totalRevenue, 2),
                    'classification' => 'SHARED',
                    'posting_group_id' => $postingGroup->id,
                ]);
            }

            // Update sale status
            $sale->update([
                'status' => 'POSTED',
                'posting_date' => $postingDateObj->format('Y-m-d'),
                'posted_at' => now(),
                'posting_group_id' => $postingGroup->id,
            ]);

            return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
        });
    }

    /**
     * Reverse a posted sale.
     *
     * @throws \Exception
     */
    public function reverseSale(Sale $sale, string $reversalDate, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $reversalDate, $reason) {
            // Validate sale status
            if (!$sale->isPosted()) {
                throw new \Exception('Only POSTED sales can be reversed.');
            }

            // Validate reversal_date
            $reversalDateObj = Carbon::parse($reversalDate)->format('Y-m-d');

            // Validate crop cycle if set
            if ($sale->crop_cycle_id) {
                $cropCycle = CropCycle::where('id', $sale->crop_cycle_id)
                    ->where('tenant_id', $sale->tenant_id)
                    ->firstOrFail();

                if ($cropCycle->status !== 'OPEN') {
                    throw new \Exception('Cannot reverse sale: crop cycle is closed.');
                }

                if ($cropCycle->start_date && $reversalDateObj < $cropCycle->start_date->format('Y-m-d')) {
                    throw new \Exception('Reversal date is before crop cycle start date.');
                }
                if ($cropCycle->end_date && $reversalDateObj > $cropCycle->end_date->format('Y-m-d')) {
                    throw new \Exception('Reversal date is after crop cycle end date.');
                }
            }

            // Check if reversal already exists (idempotency)
            if ($sale->reversal_posting_group_id) {
                $existingReversal = PostingGroup::where('id', $sale->reversal_posting_group_id)
                    ->where('tenant_id', $sale->tenant_id)
                    ->first();
                if ($existingReversal) {
                    return $sale->fresh();
                }
            }

            // Create reversal posting group
            $reversalPostingGroup = $this->reversalService->reversePostingGroup(
                $sale->posting_group_id,
                $sale->tenant_id,
                $reversalDate,
                $reason
            );

            // Mark INCOME OT as VOID so settlement excludes it
            OperationalTransaction::where('posting_group_id', $sale->posting_group_id)
                ->where('type', 'INCOME')
                ->update(['status' => 'VOID']);

            // Check if stock movements already reversed (idempotency)
            $existing = InvStockMovement::where('tenant_id', $sale->tenant_id)
                ->where('posting_group_id', $reversalPostingGroup->id)
                ->exists();

            if (!$existing) {
                // Find original stock movements and reverse them
                $originalMovements = InvStockMovement::where('tenant_id', $sale->tenant_id)
                    ->where('posting_group_id', $sale->posting_group_id)
                    ->get();

                foreach ($originalMovements as $original) {
                    $this->stockService->applyMovement(
                        $sale->tenant_id,
                        $reversalPostingGroup->id,
                        $original->store_id,
                        $original->item_id,
                        $original->movement_type,
                        (string) (-(float) $original->qty_delta),
                        (string) (-(float) $original->value_delta),
                        (string) $original->unit_cost_snapshot,
                        $reversalDateObj,
                        $original->source_type,
                        $original->source_id
                    );
                }
            }

            // Update sale
            $sale->update([
                'status' => 'REVERSED',
                'reversed_at' => now(),
                'reversal_posting_group_id' => $reversalPostingGroup->id,
            ]);

            return $sale->fresh();
        });
    }
}

<?php

namespace App\Services;

use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\InvStockMovement;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class HarvestService
{
    public function __construct(
        private SystemAccountService $accountService,
        private InventoryStockService $stockService,
        private ReversalService $reversalService
    ) {}

    /**
     * Create a DRAFT harvest.
     *
     * @throws \Exception
     */
    public function create(array $data): Harvest
    {
        return DB::transaction(function () use ($data) {
            $tenantId = $data['tenant_id'];
            $cropCycleId = $data['crop_cycle_id'];

            // Validate crop cycle exists and is OPEN
            $cropCycle = CropCycle::where('id', $cropCycleId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            if ($cropCycle->status !== 'OPEN') {
                throw new \Exception('Cannot create harvest: crop cycle is closed.');
            }

            $harvest = Harvest::create([
                'tenant_id' => $tenantId,
                'harvest_no' => $data['harvest_no'] ?? null,
                'crop_cycle_id' => $cropCycleId,
                'land_parcel_id' => $data['land_parcel_id'] ?? null,
                'harvest_date' => $data['harvest_date'],
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? null,
            ]);

            return $harvest;
        });
    }

    /**
     * Update a DRAFT harvest.
     *
     * @throws \Exception
     */
    public function update(Harvest $harvest, array $data): Harvest
    {
        return DB::transaction(function () use ($harvest, $data) {
            if (!$harvest->isDraft()) {
                throw new \Exception('Only DRAFT harvests can be updated.');
            }

            // Validate crop cycle still OPEN
            $cropCycle = $harvest->cropCycle;
            if ($cropCycle->status !== 'OPEN') {
                throw new \Exception('Cannot update harvest: crop cycle is closed.');
            }

            $harvest->update([
                'harvest_no' => $data['harvest_no'] ?? $harvest->harvest_no,
                'land_parcel_id' => $data['land_parcel_id'] ?? $harvest->land_parcel_id,
                'harvest_date' => $data['harvest_date'] ?? $harvest->harvest_date,
                'notes' => $data['notes'] ?? $harvest->notes,
            ]);

            return $harvest->fresh();
        });
    }

    /**
     * Add a line to a DRAFT harvest.
     *
     * @throws \Exception
     */
    public function addLine(Harvest $harvest, array $lineData): HarvestLine
    {
        return DB::transaction(function () use ($harvest, $lineData) {
            if (!$harvest->isDraft()) {
                throw new \Exception('Lines can only be added to DRAFT harvests.');
            }

            $quantity = (float) ($lineData['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new \Exception('Quantity must be greater than zero.');
            }

            // Validate inventory item and store belong to tenant
            \App\Models\InvItem::where('id', $lineData['inventory_item_id'])
                ->where('tenant_id', $harvest->tenant_id)
                ->firstOrFail();

            \App\Models\InvStore::where('id', $lineData['store_id'])
                ->where('tenant_id', $harvest->tenant_id)
                ->firstOrFail();

            $line = HarvestLine::create([
                'tenant_id' => $harvest->tenant_id,
                'harvest_id' => $harvest->id,
                'inventory_item_id' => $lineData['inventory_item_id'],
                'store_id' => $lineData['store_id'],
                'quantity' => $quantity,
                'uom' => $lineData['uom'] ?? null,
                'notes' => $lineData['notes'] ?? null,
            ]);

            return $line;
        });
    }

    /**
     * Update a harvest line (only if harvest is DRAFT).
     *
     * @throws \Exception
     */
    public function updateLine(HarvestLine $line, array $lineData): HarvestLine
    {
        return DB::transaction(function () use ($line, $lineData) {
            if (!$line->harvest->isDraft()) {
                throw new \Exception('Lines can only be updated when harvest is DRAFT.');
            }

            if (isset($lineData['quantity'])) {
                $quantity = (float) $lineData['quantity'];
                if ($quantity <= 0) {
                    throw new \Exception('Quantity must be greater than zero.');
                }
                $lineData['quantity'] = $quantity;
            }

            if (isset($lineData['inventory_item_id'])) {
                \App\Models\InvItem::where('id', $lineData['inventory_item_id'])
                    ->where('tenant_id', $line->tenant_id)
                    ->firstOrFail();
            }

            if (isset($lineData['store_id'])) {
                \App\Models\InvStore::where('id', $lineData['store_id'])
                    ->where('tenant_id', $line->tenant_id)
                    ->firstOrFail();
            }

            $line->update($lineData);

            return $line->fresh();
        });
    }

    /**
     * Delete a harvest line (only if harvest is DRAFT).
     *
     * @throws \Exception
     */
    public function deleteLine(HarvestLine $line): void
    {
        if (!$line->harvest->isDraft()) {
            throw new \Exception('Lines can only be deleted when harvest is DRAFT.');
        }

        $line->delete();
    }

    /**
     * Calculate NET WIP balance to transfer for a crop cycle up to a posting date.
     * 
     * WHY NET BALANCE (not cumulative debits):
     * - Multiple harvests in the same crop cycle must only transfer remaining WIP.
     * - If we summed only debits, each harvest would re-transfer the same cost.
     * - NET = (debits - credits) ensures:
     *   * Harvest 1 transfers full WIP balance (e.g., 100)
     *   * Harvest 2 sees remaining balance after Harvest 1 credit (e.g., 0)
     * - This prevents double-transfer and maintains correct inventory valuation.
     *
     * @return float Net WIP balance (debits - credits), clamped to >= 0
     */
    private function calculateWipCost(string $tenantId, string $cropCycleId, string $postingDate): float
    {
        $cropWipAccount = $this->accountService->getByCode($tenantId, 'CROP_WIP');

        $netBalance = DB::table('ledger_entries')
            ->join('posting_groups', 'ledger_entries.posting_group_id', '=', 'posting_groups.id')
            ->where('ledger_entries.tenant_id', $tenantId)
            ->where('posting_groups.crop_cycle_id', $cropCycleId)
            ->where('posting_groups.posting_date', '<=', $postingDate)
            ->where('ledger_entries.account_id', $cropWipAccount->id)
            ->selectRaw('SUM(ledger_entries.debit_amount - ledger_entries.credit_amount) as net')
            ->value('net');

        // Return net balance, but never negative (if net <= 0, allow posting with 0 cost)
        return max(0, (float) ($netBalance ?? 0));
    }

    /**
     * Allocate cost across harvest lines.
     *
     * @return array<int, float> Array of allocated costs indexed by line index
     */
    private function allocateCost(float $totalCost, \Illuminate\Support\Collection $lines): array
    {
        $allocated = [];
        $totalQty = $lines->sum('quantity');

        if ($totalQty > 0) {
            // Proportional allocation by quantity
            $allocatedTotal = 0;
            $lineCount = $lines->count();
            foreach ($lines as $index => $line) {
                if ($index === $lineCount - 1) {
                    // Last line gets remainder to ensure exact total
                    $allocated[$index] = $totalCost - $allocatedTotal;
                } else {
                    $lineAllocation = $totalCost * ((float) $line->quantity / $totalQty);
                    $allocated[$index] = round($lineAllocation, 2);
                    $allocatedTotal += $allocated[$index];
                }
            }
        } else {
            // Equal allocation by line count
            $perLine = $totalCost / $lines->count();
            $allocatedTotal = 0;
            $lineCount = $lines->count();
            foreach ($lines as $index => $line) {
                if ($index === $lineCount - 1) {
                    $allocated[$index] = $totalCost - $allocatedTotal;
                } else {
                    $allocated[$index] = round($perLine, 2);
                    $allocatedTotal += $allocated[$index];
                }
            }
        }

        return $allocated;
    }

    /**
     * Post a harvest. Creates posting group, allocation rows, ledger entries, and inventory movements.
     *
     * @throws \Exception
     */
    public function post(Harvest $harvest, array $payload): Harvest
    {
        return DB::transaction(function () use ($harvest, $payload) {
            // Check idempotency first - if already posted, return existing
            $idempotencyKey = "HARVEST:{$harvest->id}:POST";
            $existingPostingGroup = PostingGroup::where('tenant_id', $harvest->tenant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingPostingGroup) {
                // Already posted - return existing harvest with posting group
                $harvest->refresh();
                if ($harvest->posting_group_id === $existingPostingGroup->id) {
                    return $harvest->fresh(['cropCycle', 'postingGroup', 'lines.item', 'lines.store']);
                }
                // Update harvest to link to existing posting group
                $harvest->update([
                    'status' => 'POSTED',
                    'posting_date' => $payload['posting_date'] ?? $harvest->posting_date,
                    'posted_at' => $harvest->posted_at ?? now(),
                    'posting_group_id' => $existingPostingGroup->id,
                ]);
                return $harvest->fresh(['cropCycle', 'postingGroup', 'lines.item', 'lines.store']);
            }

            // Validate harvest status
            if (!$harvest->isDraft()) {
                throw new \Exception('Only DRAFT harvests can be posted.');
            }

            // Load relationships
            $harvest->load(['lines.item', 'lines.store', 'cropCycle']);

            // Validate has lines
            if ($harvest->lines->isEmpty()) {
                throw new \Exception('Harvest must have at least one line to post.');
            }

            // Validate all quantities > 0
            foreach ($harvest->lines as $line) {
                if ((float) $line->quantity <= 0) {
                    throw new \Exception("Harvest line quantity must be greater than zero.");
                }
            }

            // Validate crop cycle OPEN
            $cropCycle = $harvest->cropCycle;
            if ($cropCycle->status !== 'OPEN') {
                throw new \Exception('Cannot post harvest: crop cycle is closed.');
            }

            // Validate posting_date
            $postingDate = $payload['posting_date'] ?? null;
            if (!$postingDate) {
                throw new \Exception('posting_date is required.');
            }

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');

            // Validate posting_date within crop cycle range
            if ($cropCycle->start_date && $postingDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Posting date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $postingDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Posting date is after crop cycle end date.');
            }

            // Calculate total WIP cost to transfer
            $totalWipCost = $this->calculateWipCost($harvest->tenant_id, $harvest->crop_cycle_id, $postingDateObj);

            // If WIP cost is 0, still allow posting (creates inventory qty with 0 cost)
            // This is documented in code comment per requirements

            // Get first project from crop cycle for allocation rows
            $project = Project::where('tenant_id', $harvest->tenant_id)
                ->where('crop_cycle_id', $harvest->crop_cycle_id)
                ->first();

            if (!$project) {
                throw new \Exception('Cannot post harvest: no projects exist in crop cycle.');
            }

            // Create posting group
            $postingGroup = PostingGroup::create([
                'tenant_id' => $harvest->tenant_id,
                'crop_cycle_id' => $harvest->crop_cycle_id,
                'source_type' => 'HARVEST',
                'source_id' => $harvest->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Allocate cost across lines
            $allocatedCosts = $this->allocateCost($totalWipCost, $harvest->lines);

            // Get accounts
            // Use INVENTORY_PRODUCE for harvest output (produce), not INVENTORY_INPUTS (which is for inputs like seed/fert)
            $cropWipAccount = $this->accountService->getByCode($harvest->tenant_id, 'CROP_WIP');
            $inventoryAccount = $this->accountService->getByCode($harvest->tenant_id, 'INVENTORY_PRODUCE');

            // Create allocation rows, ledger entries, and stock movements for each line
            $totalDebitedToInventory = 0;
            foreach ($harvest->lines as $index => $line) {
                $allocatedCost = $allocatedCosts[$index] ?? 0;
                $quantity = (float) $line->quantity;
                $unitCost = $quantity > 0 ? $allocatedCost / $quantity : 0;

                // Create allocation row
                // Include harvest_line_id in snapshot for traceability (enables accurate cost-per-unit reporting)
                AllocationRow::create([
                    'tenant_id' => $harvest->tenant_id,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $project->id,
                    'party_id' => $project->party_id,
                    'allocation_type' => 'HARVEST_PRODUCTION',
                    'amount' => (string) round($allocatedCost, 2),
                    'rule_snapshot' => [
                        'type' => 'HARVEST',
                        'harvest_line_id' => $line->id,
                        'allocation' => $quantity > 0 ? 'quantity_proportional' : 'equal_by_line_count',
                        'total_wip_transferred' => $totalWipCost,
                        'line_quantity' => $quantity,
                        'line_index' => $index,
                    ],
                ]);

                // Create ledger entry for inventory (debit)
                if ($allocatedCost > 0.001) {
                    LedgerEntry::create([
                        'tenant_id' => $harvest->tenant_id,
                        'posting_group_id' => $postingGroup->id,
                        'account_id' => $inventoryAccount->id,
                        'debit_amount' => (string) round($allocatedCost, 2),
                        'credit_amount' => 0,
                        'currency_code' => 'GBP',
                    ]);
                    $totalDebitedToInventory += $allocatedCost;
                }

                // Create stock movement
                $this->stockService->applyMovement(
                    $harvest->tenant_id,
                    $postingGroup->id,
                    $line->store_id,
                    $line->inventory_item_id,
                    'HARVEST',
                    (string) $quantity,
                    (string) round($allocatedCost, 2),
                    (string) round($unitCost, 6),
                    $postingDateObj,
                    'harvest',
                    $harvest->id
                );
            }

            // Create ledger entry to credit CROP_WIP
            if ($totalWipCost > 0.001) {
                LedgerEntry::create([
                    'tenant_id' => $harvest->tenant_id,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $cropWipAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => (string) round($totalWipCost, 2),
                    'currency_code' => 'GBP',
                ]);
            }

            // Update harvest
            $harvest->update([
                'status' => 'POSTED',
                'posting_date' => $postingDateObj,
                'posted_at' => now(),
                'posting_group_id' => $postingGroup->id,
            ]);

            return $harvest->fresh();
        });
    }

    /**
     * Reverse a posted harvest.
     *
     * @throws \Exception
     */
    public function reverse(Harvest $harvest, array $payload): Harvest
    {
        return DB::transaction(function () use ($harvest, $payload) {
            // Validate harvest status
            if (!$harvest->isPosted()) {
                throw new \Exception('Only POSTED harvests can be reversed.');
            }

            // Validate reversal_date
            $reversalDate = $payload['reversal_date'] ?? null;
            if (!$reversalDate) {
                throw new \Exception('reversal_date is required.');
            }

            $reversalDateObj = Carbon::parse($reversalDate)->format('Y-m-d');

            // Validate crop cycle OPEN
            $cropCycle = $harvest->cropCycle;
            if ($cropCycle->status !== 'OPEN') {
                throw new \Exception('Cannot reverse harvest: crop cycle is closed.');
            }

            // Validate reversal_date within crop cycle range
            if ($cropCycle->start_date && $reversalDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Reversal date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $reversalDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Reversal date is after crop cycle end date.');
            }

            $reason = $payload['reason'] ?? '';

            // Create reversal posting group
            $reversalPostingGroup = $this->reversalService->reversePostingGroup(
                $harvest->posting_group_id,
                $harvest->tenant_id,
                $reversalDate,
                $reason
            );

            // Check if stock movements already reversed (idempotency)
            $existing = InvStockMovement::where('tenant_id', $harvest->tenant_id)
                ->where('posting_group_id', $reversalPostingGroup->id)
                ->exists();

            if ($existing) {
                $harvest->update([
                    'status' => 'REVERSED',
                    'reversed_at' => now(),
                    'reversal_posting_group_id' => $reversalPostingGroup->id,
                ]);
                return $harvest->fresh();
            }

            // Find original stock movements and reverse them
            $originalMovements = InvStockMovement::where('tenant_id', $harvest->tenant_id)
                ->where('posting_group_id', $harvest->posting_group_id)
                ->get();

            foreach ($originalMovements as $original) {
                $this->stockService->applyMovement(
                    $harvest->tenant_id,
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

            // Update harvest
            $harvest->update([
                'status' => 'REVERSED',
                'reversed_at' => now(),
                'reversal_posting_group_id' => $reversalPostingGroup->id,
            ]);

            return $harvest->fresh();
        });
    }
}

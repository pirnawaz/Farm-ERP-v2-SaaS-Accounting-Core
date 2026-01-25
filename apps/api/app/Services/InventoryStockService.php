<?php

namespace App\Services;

use App\Models\InvStockBalance;
use App\Models\InvStockMovement;
use Illuminate\Support\Collection;

class InventoryStockService
{
    /**
     * Get or create a stock balance for (tenant, store, item). Creates with zeros if missing.
     */
    public function getOrCreateBalance(string $tenantId, string $storeId, string $itemId): InvStockBalance
    {
        $balance = InvStockBalance::where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('item_id', $itemId)
            ->first();

        if ($balance) {
            return $balance;
        }

        return InvStockBalance::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'item_id' => $itemId,
            'qty_on_hand' => 0,
            'value_on_hand' => 0,
            'wac_cost' => 0,
            'updated_at' => now(),
        ]);
    }

    /**
     * Record a stock movement and update the balance.
     * qty_delta and value_delta can be positive (in) or negative (out).
     */
    public function applyMovement(
        string $tenantId,
        string $postingGroupId,
        string $storeId,
        string $itemId,
        string $movementType,
        string $qtyDelta,
        string $valueDelta,
        string $unitCostSnapshot,
        \DateTimeInterface|string $occurredAt,
        string $sourceType,
        string $sourceId
    ): InvStockMovement {
        $occurredAt = $occurredAt instanceof \DateTimeInterface
            ? $occurredAt->format('Y-m-d H:i:s')
            : \Carbon\Carbon::parse($occurredAt)->format('Y-m-d H:i:s');

        $movement = InvStockMovement::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $postingGroupId,
            'movement_type' => $movementType,
            'store_id' => $storeId,
            'item_id' => $itemId,
            'qty_delta' => $qtyDelta,
            'value_delta' => $valueDelta,
            'unit_cost_snapshot' => $unitCostSnapshot,
            'occurred_at' => $occurredAt,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);

        $balance = $this->getOrCreateBalance($tenantId, $storeId, $itemId);
        $newQty = (float) $balance->qty_on_hand + (float) $qtyDelta;
        $newValue = (float) $balance->value_on_hand + (float) $valueDelta;
        $wac = $newQty != 0 ? $newValue / $newQty : '0';

        $balance->update([
            'qty_on_hand' => $newQty,
            'value_on_hand' => $newValue,
            'wac_cost' => $wac,
            'updated_at' => now(),
        ]);

        return $movement;
    }

    /**
     * Get stock on hand with optional store_id and item_id filters.
     *
     * @return Collection<int, InvStockBalance>
     */
    public function getStockOnHand(string $tenantId, ?string $storeId = null, ?string $itemId = null): Collection
    {
        $query = InvStockBalance::where('tenant_id', $tenantId)
            ->with(['store', 'item']);

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }
        if ($itemId !== null) {
            $query->where('item_id', $itemId);
        }

        return $query->orderBy('store_id')->orderBy('item_id')->get();
    }

    /**
     * Get stock movements with optional filters.
     *
     * @return Collection<int, InvStockMovement>
     */
    public function getMovements(
        string $tenantId,
        ?string $storeId = null,
        ?string $itemId = null,
        ?string $from = null,
        ?string $to = null
    ): Collection {
        $query = InvStockMovement::where('tenant_id', $tenantId)
            ->with(['store', 'item', 'postingGroup']);

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }
        if ($itemId !== null) {
            $query->where('item_id', $itemId);
        }
        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('occurred_at', '<=', $to);
        }

        return $query->orderBy('occurred_at', 'desc')->get();
    }
}

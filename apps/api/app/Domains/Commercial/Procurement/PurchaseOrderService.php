<?php

namespace App\Domains\Commercial\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseOrderService
{
    /**
     * @param  array{supplier_id:string, po_no?:string, po_date:string, notes?:?string, lines?:list<array<string,mixed>>}  $data
     */
    public function create(string $tenantId, array $data, ?string $createdBy): PurchaseOrder
    {
        return DB::transaction(function () use ($tenantId, $data, $createdBy) {
            $po = PurchaseOrder::create([
                'tenant_id' => $tenantId,
                'supplier_id' => $data['supplier_id'],
                'po_no' => $data['po_no'],
                'po_date' => $data['po_date'],
                'status' => PurchaseOrder::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            foreach (($data['lines'] ?? []) as $i => $l) {
                PurchaseOrderLine::create([
                    'tenant_id' => $tenantId,
                    'purchase_order_id' => $po->id,
                    'line_no' => (int) ($l['line_no'] ?? ($i + 1)),
                    'item_id' => $l['item_id'] ?? null,
                    'description' => $l['description'] ?? null,
                    'qty_ordered' => $l['qty_ordered'] ?? 0,
                    'qty_overbill_tolerance' => $l['qty_overbill_tolerance'] ?? 0,
                    'expected_unit_cost' => $l['expected_unit_cost'] ?? null,
                ]);
            }

            return $po->fresh(['lines.item', 'supplier']);
        });
    }

    /**
     * @param  array{po_no?:string, po_date?:string, notes?:?string, lines?:list<array<string,mixed>>}  $data
     */
    public function update(PurchaseOrder $po, string $tenantId, array $data): PurchaseOrder
    {
        if (! $po->canBeUpdated()) {
            throw ValidationException::withMessages([
                'status' => ['Only DRAFT purchase orders can be edited.'],
            ]);
        }

        return DB::transaction(function () use ($po, $tenantId, $data) {
            $po->update(array_filter([
                'po_no' => $data['po_no'] ?? null,
                'po_date' => $data['po_date'] ?? null,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : null,
            ], fn ($v) => $v !== null));

            if (array_key_exists('lines', $data)) {
                PurchaseOrderLine::query()->where('tenant_id', $tenantId)->where('purchase_order_id', $po->id)->delete();
                foreach (($data['lines'] ?? []) as $i => $l) {
                    PurchaseOrderLine::create([
                        'tenant_id' => $tenantId,
                        'purchase_order_id' => $po->id,
                        'line_no' => (int) ($l['line_no'] ?? ($i + 1)),
                        'item_id' => $l['item_id'] ?? null,
                        'description' => $l['description'] ?? null,
                        'qty_ordered' => $l['qty_ordered'] ?? 0,
                        'qty_overbill_tolerance' => $l['qty_overbill_tolerance'] ?? 0,
                        'expected_unit_cost' => $l['expected_unit_cost'] ?? null,
                    ]);
                }
            }

            return $po->fresh(['lines.item', 'supplier']);
        });
    }

    public function approve(PurchaseOrder $po, string $tenantId, ?string $approvedBy): PurchaseOrder
    {
        if ($po->tenant_id !== $tenantId) {
            abort(404);
        }
        if ($po->status !== PurchaseOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only DRAFT purchase orders can be approved.'],
            ]);
        }

        $lineCount = PurchaseOrderLine::query()->where('tenant_id', $tenantId)->where('purchase_order_id', $po->id)->count();
        if ($lineCount <= 0) {
            throw ValidationException::withMessages([
                'lines' => ['Purchase order must have at least one line before approval.'],
            ]);
        }

        $po->update([
            'status' => PurchaseOrder::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $approvedBy,
        ]);

        return $po->fresh(['lines.item', 'supplier']);
    }
}


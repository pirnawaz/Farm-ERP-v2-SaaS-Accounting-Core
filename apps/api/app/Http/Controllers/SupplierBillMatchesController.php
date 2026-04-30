<?php

namespace App\Http\Controllers;

use App\Domains\Commercial\AccountsPayable\SupplierBillMatchService;
use App\Models\SupplierBill;
use App\Models\SupplierBillLineMatch;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierBillMatchesController extends Controller
{
    public function __construct(
        private SupplierBillMatchService $service
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $bill = SupplierBill::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with(['lines.matches.grnLine.grn', 'lines.matches.purchaseOrderLine.purchaseOrder'])
            ->firstOrFail();

        $lineIds = $bill->lines->pluck('id')->all();
        $matches = SupplierBillLineMatch::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('supplier_bill_line_id', $lineIds)
            ->with(['grnLine.grn', 'purchaseOrderLine.purchaseOrder'])
            ->get();

        return response()->json([
            'supplier_bill_id' => $bill->id,
            'status' => $bill->status,
            'matches' => $matches,
        ]);
    }

    public function sync(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $bill = SupplierBill::query()->where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();

        $validated = $request->validate([
            'matches' => ['required', 'array'],
            'matches.*.supplier_bill_line_id' => ['required', 'uuid'],
            'matches.*.purchase_order_line_id' => ['nullable', 'uuid'],
            'matches.*.grn_line_id' => ['nullable', 'uuid'],
            'matches.*.matched_qty' => ['required', 'numeric', 'gt:0'],
            'matches.*.matched_amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->service->syncMatches($bill, $validated['matches'], $tenantId);

        return response()->json(['ok' => true]);
    }
}


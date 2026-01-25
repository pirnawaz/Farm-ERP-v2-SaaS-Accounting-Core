<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use App\Services\InventoryStockService;
use Illuminate\Http\Request;

class InvStockController extends Controller
{
    public function __construct(
        private InventoryStockService $stockService
    ) {}

    /**
     * GET /stock/on-hand?store_id=&item_id=
     */
    public function onHand(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $storeId = $request->query('store_id');
        $itemId = $request->query('item_id');
        $rows = $this->stockService->getStockOnHand($tenantId, $storeId ?: null, $itemId ?: null);
        return response()->json($rows);
    }

    /**
     * GET /stock/movements?store_id=&item_id=&from=&to=
     */
    public function movements(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $storeId = $request->query('store_id');
        $itemId = $request->query('item_id');
        $from = $request->query('from');
        $to = $request->query('to');
        $rows = $this->stockService->getMovements($tenantId, $storeId ?: null, $itemId ?: null, $from ?: null, $to ?: null);
        return response()->json($rows);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\CropCycle;
use App\Models\LandParcel;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Services\TenantContext;
use App\Services\HarvestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HarvestController extends Controller
{
    public function __construct(
        private HarvestService $harvestService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = Harvest::where('tenant_id', $tenantId)
            ->with(['cropCycle', 'landParcel', 'postingGroup', 'lines.item', 'lines.store']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }
        if ($request->filled('from')) {
            $query->where('harvest_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('harvest_date', '<=', $request->to);
        }

        $harvests = $query->orderBy('harvest_date', 'desc')->orderBy('created_at', 'desc')->get();
        return response()->json($harvests);
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'harvest_no' => 'nullable|string|max:255',
            'crop_cycle_id' => 'required|uuid|exists:crop_cycles,id',
            'land_parcel_id' => 'nullable|uuid|exists:land_parcels,id',
            'harvest_date' => 'required|date|date_format:Y-m-d',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Validate tenant ownership
        CropCycle::where('id', $request->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
        if ($request->land_parcel_id) {
            LandParcel::where('id', $request->land_parcel_id)->where('tenant_id', $tenantId)->firstOrFail();
        }

        $harvest = $this->harvestService->create([
            'tenant_id' => $tenantId,
            'harvest_no' => $request->harvest_no,
            'crop_cycle_id' => $request->crop_cycle_id,
            'land_parcel_id' => $request->land_parcel_id,
            'harvest_date' => $request->harvest_date,
            'notes' => $request->notes,
        ]);

        return response()->json($harvest->load(['cropCycle', 'landParcel', 'lines']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $harvest = Harvest::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['cropCycle', 'landParcel', 'postingGroup', 'reversalPostingGroup', 'lines.item', 'lines.store'])
            ->firstOrFail();
        return response()->json($harvest);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $harvest = Harvest::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'harvest_no' => 'nullable|string|max:255',
            'land_parcel_id' => 'nullable|uuid|exists:land_parcels,id',
            'harvest_date' => 'sometimes|required|date|date_format:Y-m-d',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->land_parcel_id) {
            LandParcel::where('id', $request->land_parcel_id)->where('tenant_id', $tenantId)->firstOrFail();
        }

        $data = $request->only(['harvest_no', 'land_parcel_id', 'harvest_date', 'notes']);
        $data = array_filter($data, fn ($v) => $v !== null);
        if ($request->has('land_parcel_id') && $request->land_parcel_id === null) {
            $data['land_parcel_id'] = null;
        }

        try {
            $harvest = $this->harvestService->update($harvest, $data);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($harvest->load(['cropCycle', 'landParcel', 'lines']));
    }

    public function addLine(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $harvest = Harvest::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'inventory_item_id' => 'required|uuid|exists:inv_items,id',
            'store_id' => 'required|uuid|exists:inv_stores,id',
            'quantity' => 'required|numeric|min:0.001',
            'uom' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Validate tenant ownership
        InvItem::where('id', $request->inventory_item_id)->where('tenant_id', $tenantId)->firstOrFail();
        InvStore::where('id', $request->store_id)->where('tenant_id', $tenantId)->firstOrFail();

        try {
            $line = $this->harvestService->addLine($harvest, [
                'inventory_item_id' => $request->inventory_item_id,
                'store_id' => $request->store_id,
                'quantity' => $request->quantity,
                'uom' => $request->uom,
                'notes' => $request->notes,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($line->load(['item', 'store']), 201);
    }

    public function updateLine(Request $request, string $id, string $lineId)
    {
        $tenantId = TenantContext::getTenantId($request);
        $harvest = Harvest::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        $line = HarvestLine::where('id', $lineId)
            ->where('harvest_id', $harvest->id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'inventory_item_id' => 'sometimes|required|uuid|exists:inv_items,id',
            'store_id' => 'sometimes|required|uuid|exists:inv_stores,id',
            'quantity' => 'sometimes|required|numeric|min:0.001',
            'uom' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('inventory_item_id')) {
            InvItem::where('id', $request->inventory_item_id)->where('tenant_id', $tenantId)->firstOrFail();
        }
        if ($request->has('store_id')) {
            InvStore::where('id', $request->store_id)->where('tenant_id', $tenantId)->firstOrFail();
        }

        try {
            $line = $this->harvestService->updateLine($line, $request->only([
                'inventory_item_id', 'store_id', 'quantity', 'uom', 'notes'
            ]));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($line->load(['item', 'store']));
    }

    public function deleteLine(Request $request, string $id, string $lineId)
    {
        $tenantId = TenantContext::getTenantId($request);
        $harvest = Harvest::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        $line = HarvestLine::where('id', $lineId)
            ->where('harvest_id', $harvest->id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->harvestService->deleteLine($line);

        return response()->json(null, 204);
    }

    public function post(Request $request, string $id)
    {
        $this->authorizePosting($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $harvest = Harvest::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'posting_date' => 'required|date|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $harvest = $this->harvestService->post($harvest, [
                'posting_date' => $request->posting_date,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($harvest->load(['cropCycle', 'postingGroup', 'lines.item', 'lines.store']), 200);
    }

    public function reverse(Request $request, string $id)
    {
        $this->authorizeReversal($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $harvest = Harvest::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'reversal_date' => 'required|date|date_format:Y-m-d',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $harvest = $this->harvestService->reverse($harvest, [
                'reversal_date' => $request->reversal_date,
                'reason' => $request->reason ?? '',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($harvest->load(['cropCycle', 'postingGroup', 'reversalPostingGroup', 'lines.item', 'lines.store']), 200);
    }
}

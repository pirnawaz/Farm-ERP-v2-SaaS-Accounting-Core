<?php

namespace App\Http\Controllers;

use App\Models\LivestockEvent;
use App\Models\ProductionUnit;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LivestockEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $query = LivestockEvent::where('tenant_id', $tenantId)
            ->with('productionUnit')
            ->orderBy('event_date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('production_unit_id')) {
            $query->where('production_unit_id', $request->input('production_unit_id'));
        }
        if ($request->filled('from')) {
            $query->where('event_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('event_date', '<=', $request->input('to'));
        }

        $items = $query->get();

        return response()->json($items)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'production_unit_id' => ['required', 'uuid', 'exists:production_units,id'],
            'event_date' => ['required', 'date', 'date_format:Y-m-d'],
            'event_type' => ['required', 'string', Rule::in([
                LivestockEvent::TYPE_PURCHASE,
                LivestockEvent::TYPE_SALE,
                LivestockEvent::TYPE_BIRTH,
                LivestockEvent::TYPE_DEATH,
                LivestockEvent::TYPE_ADJUSTMENT,
            ])],
            'quantity' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $unit = ProductionUnit::where('id', $validated['production_unit_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($unit->category !== ProductionUnit::CATEGORY_LIVESTOCK) {
            return response()->json([
                'errors' => ['production_unit_id' => ['Production unit must have category LIVESTOCK.']],
            ], 422);
        }

        $quantity = (int) $validated['quantity'];
        $eventType = $validated['event_type'];

        if (in_array($eventType, [LivestockEvent::TYPE_PURCHASE, LivestockEvent::TYPE_BIRTH], true)) {
            if ($quantity <= 0) {
                return response()->json(['errors' => ['quantity' => ['Quantity must be positive for ' . $eventType . '.']]], 422);
            }
        } elseif (in_array($eventType, [LivestockEvent::TYPE_SALE, LivestockEvent::TYPE_DEATH], true)) {
            if ($quantity <= 0) {
                return response()->json(['errors' => ['quantity' => ['Quantity must be positive for ' . $eventType . '.']]], 422);
            }
            $quantity = -$quantity;
        } else {
            // ADJUSTMENT
            if ($quantity === 0) {
                return response()->json(['errors' => ['quantity' => ['Quantity must be non-zero for ADJUSTMENT.']]], 422);
            }
        }

        $data = [
            'tenant_id' => $tenantId,
            'production_unit_id' => $validated['production_unit_id'],
            'event_date' => $validated['event_date'],
            'event_type' => $eventType,
            'quantity' => $quantity,
            'notes' => $validated['notes'] ?? null,
        ];

        $event = LivestockEvent::create($data);

        return response()->json($event->load('productionUnit'), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $event = LivestockEvent::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with('productionUnit')
            ->firstOrFail();

        return response()->json($event);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $event = LivestockEvent::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'event_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'event_type' => ['sometimes', 'required', 'string', Rule::in([
                LivestockEvent::TYPE_PURCHASE,
                LivestockEvent::TYPE_SALE,
                LivestockEvent::TYPE_BIRTH,
                LivestockEvent::TYPE_DEATH,
                LivestockEvent::TYPE_ADJUSTMENT,
            ])],
            'quantity' => ['sometimes', 'required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $quantity = array_key_exists('quantity', $validated) ? (int) $validated['quantity'] : $event->quantity;
        $eventType = $validated['event_type'] ?? $event->event_type;

        if (in_array($eventType, [LivestockEvent::TYPE_PURCHASE, LivestockEvent::TYPE_BIRTH], true)) {
            if ($quantity <= 0) {
                return response()->json(['errors' => ['quantity' => ['Quantity must be positive for ' . $eventType . '.']]], 422);
            }
        } elseif (in_array($eventType, [LivestockEvent::TYPE_SALE, LivestockEvent::TYPE_DEATH], true)) {
            if ($quantity <= 0) {
                return response()->json(['errors' => ['quantity' => ['Quantity must be positive for ' . $eventType . '.']]], 422);
            }
            $quantity = -$quantity;
        } else {
            if ($quantity === 0) {
                return response()->json(['errors' => ['quantity' => ['Quantity must be non-zero for ADJUSTMENT.']]], 422);
            }
        }

        $event->event_date = $validated['event_date'] ?? $event->event_date;
        $event->event_type = $eventType;
        $event->quantity = $quantity;
        $event->notes = array_key_exists('notes', $validated) ? $validated['notes'] : $event->notes;
        $event->save();

        return response()->json($event->load('productionUnit'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $event = LivestockEvent::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $event->delete();

        return response()->json(null, 204);
    }
}

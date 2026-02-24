<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\PeriodClose\PeriodCloseService;
use App\Models\CropCycle;
use App\Services\CropCycleCloseService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CropCycleController extends Controller
{
    public function __construct(
        private CropCycleCloseService $closeService,
        private PeriodCloseService $periodCloseService
    ) {}
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycles = CropCycle::where('tenant_id', $tenantId)
            ->with('tenantCropItem.cropCatalogItem')
            ->orderBy('start_date', 'desc')
            ->get();

        $data = $cycles->map(fn (CropCycle $c) => $this->cycleToArray($c));
        return response()->json($data)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tenant_crop_item_id' => ['required', 'uuid', Rule::exists('tenant_crop_items', 'id')->where('tenant_id', $tenantId)],
            'crop_variety_id' => ['nullable', 'uuid', Rule::exists('crop_varieties', 'id')->where('tenant_id', $tenantId)],
            'start_date' => ['required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        if ($request->filled('crop_variety_id')) {
            $variety = \App\Models\CropVariety::where('id', $request->crop_variety_id)
                ->where('tenant_id', $tenantId)
                ->where('tenant_crop_item_id', $request->tenant_crop_item_id)
                ->first();
            if (!$variety) {
                throw ValidationException::withMessages([
                    'crop_variety_id' => ['The selected crop variety must belong to the selected crop item.'],
                ])->status(422);
            }
        }

        $data = [
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'tenant_crop_item_id' => $request->tenant_crop_item_id,
            'start_date' => $request->start_date,
            'status' => 'OPEN',
        ];
        $data['crop_variety_id'] = $request->filled('crop_variety_id') ? $request->crop_variety_id : null;
        if ($request->filled('end_date')) {
            $data['end_date'] = $request->end_date;
        }
        $cycle = CropCycle::create($data);
        $cycle->load('tenantCropItem.cropCatalogItem');

        return response()->json($this->cycleToArray($cycle), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with('tenantCropItem.cropCatalogItem', 'cropVariety')
            ->firstOrFail();

        return response()->json($this->cycleToArray($cycle));
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tenant_crop_item_id' => ['sometimes', 'required', 'uuid', Rule::exists('tenant_crop_items', 'id')->where('tenant_id', $tenantId)],
            'crop_variety_id' => [
                'nullable',
                'uuid',
                Rule::exists('crop_varieties', 'id')->where('tenant_id', $tenantId),
            ],
            'start_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        if ($request->filled('crop_variety_id')) {
            $cropItemId = $request->filled('tenant_crop_item_id') ? $request->tenant_crop_item_id : $cycle->tenant_crop_item_id;
            $variety = \App\Models\CropVariety::where('id', $request->crop_variety_id)
                ->where('tenant_id', $tenantId)
                ->where('tenant_crop_item_id', $cropItemId)
                ->first();
            if (!$variety) {
                throw ValidationException::withMessages([
                    'crop_variety_id' => ['The selected crop variety must belong to the selected crop item.'],
                ])->status(422);
            }
        }

        $data = $request->only(['name', 'start_date', 'end_date', 'tenant_crop_item_id', 'crop_variety_id']);
        if (array_key_exists('end_date', $data) && $data['end_date'] === '') {
            $data['end_date'] = null;
        }
        if (array_key_exists('crop_variety_id', $data) && ($data['crop_variety_id'] === '' || $data['crop_variety_id'] === null)) {
            $data['crop_variety_id'] = null;
        }
        $cycle->update($data);
        $cycle->load('tenantCropItem.cropCatalogItem', 'cropVariety');

        return response()->json($this->cycleToArray($cycle));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $cycle->delete();

        return response()->json(null, 204);
    }

    public function closePreview(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        CropCycle::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $preview = $this->closeService->previewClose($id, $tenantId);

        return response()->json($preview);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'as_of' => ['nullable', 'date', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $userId = $request->user()?->id;
        $asOf = $request->input('as_of');

        try {
            $result = $this->periodCloseService->closeCropCycle($tenantId, $id, $userId, $asOf);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['crop_cycle' => [$e->getMessage()]])->status(422);
        }

        $run = $result['close_run'];
        return response()->json([
            'crop_cycle_id' => $result['crop_cycle']->id,
            'status' => $result['crop_cycle']->status,
            'posting_group_id' => $result['posting_group_id'],
            'net_profit' => $result['net_profit'],
            'closed_at' => $result['closed_at'],
            'closed_by_user_id' => $result['closed_by_user_id'],
        ]);
    }

    public function closeRun(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        CropCycle::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $run = $this->periodCloseService->getCloseRun($tenantId, $id);

        if (!$run) {
            return response()->json(['message' => 'No close run for this crop cycle.'], 404);
        }

        return response()->json($run->load(['postingGroup']));
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = $this->closeService->reopen($id, $tenantId);

        return response()->json($cycle);
    }

    public function open(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $cycle->update(['status' => 'OPEN']);
        $cycle->load('tenantCropItem.cropCatalogItem');

        return response()->json($this->cycleToArray($cycle));
    }

    private function cycleToArray(CropCycle $cycle): array
    {
        $item = $cycle->tenantCropItem;
        $displayName = null;
        if ($item) {
            $displayName = $item->display_name !== null && $item->display_name !== ''
                ? $item->display_name
                : ($item->cropCatalogItem ? $item->cropCatalogItem->default_name : $item->custom_name);
        }
        $arr = $cycle->toArray();
        $arr['crop_display_name'] = $displayName;
        return $arr;
    }
}

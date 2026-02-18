<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\PeriodClose\PeriodCloseService;
use App\Models\CropCycle;
use App\Services\CropCycleCloseService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->orderBy('start_date', 'desc')
            ->get();

        return response()->json($cycles)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $data = [
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'start_date' => $request->start_date,
            'status' => 'OPEN',
        ];
        if ($request->filled('end_date')) {
            $data['end_date'] = $request->end_date;
        }
        $cycle = CropCycle::create($data);

        return response()->json($cycle, 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return response()->json($cycle);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $cycle = CropCycle::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'start_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $data = $request->only(['name', 'start_date', 'end_date']);
        if (array_key_exists('end_date', $data) && $data['end_date'] === '') {
            $data['end_date'] = null;
        }
        $cycle->update($data);

        return response()->json($cycle);
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

        return response()->json($cycle);
    }
}

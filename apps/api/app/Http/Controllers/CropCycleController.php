<?php

namespace App\Http\Controllers;

use App\Models\CropCycle;
use App\Services\CropCycleCloseService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CropCycleController extends Controller
{
    public function __construct(
        private CropCycleCloseService $closeService
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
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $userId = $request->user()?->id;
        $note = $request->input('note');

        $cycle = $this->closeService->close($id, $tenantId, $userId, $note);

        return response()->json($cycle);
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

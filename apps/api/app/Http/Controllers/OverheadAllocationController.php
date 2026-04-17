<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use App\Models\OverheadAllocationHeader;
use App\Services\OverheadAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OverheadAllocationController extends Controller
{
    public function __construct(
        private OverheadAllocationService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $q = OverheadAllocationHeader::query()
            ->where('tenant_id', $tenantId)
            ->with(['lines.project:id,name', 'costCenter:id,name', 'postingGroup:id,posting_date,source_type']);

        if ($request->filled('cost_center_id')) {
            $q->where('cost_center_id', $request->input('cost_center_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('source_posting_group_id')) {
            $q->where('source_posting_group_id', $request->input('source_posting_group_id'));
        }

        $rows = $q->orderByDesc('created_at')->limit(200)->get();

        return response()->json($rows);
    }

    public function available(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'source_posting_group_id' => 'required|uuid',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }
        $amount = $this->service->availableAmount(
            $tenantId,
            (string) $request->input('source_posting_group_id'),
            null
        );

        return response()->json(['available_amount' => $amount]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'cost_center_id' => 'required|uuid',
            'source_posting_group_id' => 'required|uuid',
            'allocation_date' => 'required|date',
            'method' => 'required|in:PERCENTAGE,EQUAL_SHARE,AREA',
            'total_amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.project_id' => 'required|uuid',
            'lines.*.percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.basis_value' => 'nullable|numeric|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $header = $this->service->createDraft(
            $tenantId,
            (string) $request->input('cost_center_id'),
            (string) $request->input('source_posting_group_id'),
            (string) $request->input('allocation_date'),
            (string) $request->input('method'),
            (float) $request->input('total_amount'),
            $request->input('lines'),
            $request->input('notes')
        );

        return response()->json($header, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $header = OverheadAllocationHeader::query()
            ->where('tenant_id', $tenantId)
            ->with(['lines.project:id,name', 'costCenter:id,name', 'sourcePostingGroup', 'postingGroup'])
            ->findOrFail($id);

        return response()->json($header);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $header = OverheadAllocationHeader::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $v = Validator::make($request->all(), [
            'allocation_date' => 'sometimes|date',
            'method' => 'sometimes|in:PERCENTAGE,EQUAL_SHARE,AREA',
            'total_amount' => 'sometimes|numeric|min:0.01',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'sometimes|array|min:1',
            'lines.*.project_id' => 'required_with:lines|uuid',
            'lines.*.percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.basis_value' => 'nullable|numeric|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $updated = $this->service->updateDraft(
            $header,
            $request->has('allocation_date') ? (string) $request->input('allocation_date') : null,
            $request->has('method') ? (string) $request->input('method') : null,
            $request->has('total_amount') ? (float) $request->input('total_amount') : null,
            $request->has('lines') ? $request->input('lines') : null,
            $request->has('notes') ? $request->input('notes') : null
        );

        return response()->json($updated);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $header = OverheadAllocationHeader::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);
        $this->service->deleteDraft($header);

        return response()->json(null, 204);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $header = OverheadAllocationHeader::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $v = Validator::make($request->all(), [
            // Posting date is taken from the draft header's allocation_date; do not override via the request.
            'posting_date' => 'prohibited',
            'idempotency_key' => 'nullable|string|max:128',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $pg = $this->service->post($header, $request->input('idempotency_key'));

        return response()->json($pg, 201);
    }
}

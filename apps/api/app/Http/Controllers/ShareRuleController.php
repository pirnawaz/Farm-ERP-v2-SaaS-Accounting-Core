<?php

namespace App\Http\Controllers;

use App\Models\ShareRule;
use App\Services\TenantContext;
use App\Services\ShareRuleService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ShareRuleController extends Controller
{
    public function __construct(
        private ShareRuleService $shareRuleService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $filters = [
            'tenant_id' => $tenantId,
            'applies_to' => $request->input('applies_to'),
            'is_active' => $request->boolean('is_active'),
            'crop_cycle_id' => $request->input('crop_cycle_id'),
        ];

        $rules = $this->shareRuleService->list($filters);

        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'applies_to' => ['required', 'string', 'in:CROP_CYCLE,PROJECT,SALE'],
            'basis' => ['nullable', 'string', 'in:MARGIN,REVENUE'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'is_active' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.party_id' => ['required', 'uuid', 'exists:parties,id'],
            'lines.*.percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'lines.*.role' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->all();
        $data['tenant_id'] = $tenantId;

        try {
            $shareRule = $this->shareRuleService->create($data);
            return response()->json($shareRule, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $shareRule = ShareRule::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with('lines.party')
            ->firstOrFail();

        return response()->json($shareRule);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'applies_to' => ['sometimes', 'string', 'in:CROP_CYCLE,PROJECT,SALE'],
            'basis' => ['nullable', 'string', 'in:MARGIN,REVENUE'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'is_active' => ['nullable', 'boolean'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.party_id' => ['required_with:lines', 'uuid', 'exists:parties,id'],
            'lines.*.percentage' => ['required_with:lines', 'numeric', 'min:0', 'max:100'],
            'lines.*.role' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verify share rule belongs to tenant
        ShareRule::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        try {
            $shareRule = $this->shareRuleService->update($id, $request->all());
            return response()->json($shareRule);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CostCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $q = TenantScoped::for(CostCenter::query(), $tenantId)->orderBy('name');
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        return response()->json($q->get());
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:64',
                Rule::unique('cost_centers', 'code')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'status' => ['nullable', 'string', Rule::in([CostCenter::STATUS_ACTIVE, CostCenter::STATUS_INACTIVE])],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        if (isset($data['code']) && $data['code'] === '') {
            $data['code'] = null;
        }

        $cc = CostCenter::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'status' => $data['status'] ?? CostCenter::STATUS_ACTIVE,
            'description' => $data['description'] ?? null,
        ]);

        return response()->json($cc, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $cc = TenantScoped::for(CostCenter::query(), $tenantId)->findOrFail($id);

        return response()->json($cc);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $cc = TenantScoped::for(CostCenter::query(), $tenantId)->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:64',
                Rule::unique('cost_centers', 'code')->where(fn ($q) => $q->where('tenant_id', $tenantId))->ignore($cc->id),
            ],
            'status' => ['sometimes', 'string', Rule::in([CostCenter::STATUS_ACTIVE, CostCenter::STATUS_INACTIVE])],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        if (array_key_exists('code', $data) && $data['code'] === '') {
            $data['code'] = null;
        }

        $cc->update($data);

        return response()->json($cc->fresh());
    }
}

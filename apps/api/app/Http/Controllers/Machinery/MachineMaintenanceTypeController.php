<?php

namespace App\Http\Controllers\Machinery;

use App\Http\Controllers\Controller;
use App\Models\MachineMaintenanceType;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MachineMaintenanceTypeController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = MachineMaintenanceType::where('tenant_id', $tenantId);

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $types = $query->orderBy('name')->get();
        return response()->json($types)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('machine_maintenance_types')->where('tenant_id', $tenantId)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $type = MachineMaintenanceType::create(array_merge(
            ['tenant_id' => $tenantId],
            $validated,
            ['is_active' => $validated['is_active'] ?? true]
        ));

        return response()->json($type, 201);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $type = MachineMaintenanceType::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('machine_maintenance_types')->where('tenant_id', $tenantId)->ignore($id)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $type->update($validated);
        return response()->json($type->fresh());
    }
}

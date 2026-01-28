<?php

namespace App\Http\Controllers\Machinery;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MachineController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = Machine::where('tenant_id', $tenantId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('machine_type')) {
            $query->where('machine_type', $request->machine_type);
        }
        if ($request->filled('ownership_type')) {
            $query->where('ownership_type', $request->ownership_type);
        }

        $machines = $query->orderBy('code')->get();
        return response()->json($machines)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('machines')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'machine_type' => ['required', 'string', 'max:255'],
            'ownership_type' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'meter_unit' => ['required', 'string', 'in:HOURS,KM'],
            'opening_meter' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $machine = Machine::create(array_merge(
            ['tenant_id' => $tenantId],
            $validated,
            ['opening_meter' => $validated['opening_meter'] ?? 0]
        ));

        return response()->json($machine, 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $machine = Machine::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        return response()->json($machine);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $machine = Machine::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('machines')->where('tenant_id', $tenantId)->ignore($id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'machine_type' => ['sometimes', 'string', 'max:255'],
            'ownership_type' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:255'],
            'meter_unit' => ['sometimes', 'string', 'in:HOURS,KM'],
            'opening_meter' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $machine->update($validated);
        return response()->json($machine->fresh());
    }
}

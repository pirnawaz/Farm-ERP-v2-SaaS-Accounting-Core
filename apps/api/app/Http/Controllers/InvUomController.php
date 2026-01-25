<?php

namespace App\Http\Controllers;

use App\Models\InvUom;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvUomController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $uoms = InvUom::where('tenant_id', $tenantId)->orderBy('code')->get();
        return response()->json($uoms);
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('inv_uoms')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $uom = InvUom::create(array_merge(['tenant_id' => $tenantId], $validated));
        return response()->json($uom, 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $uom = InvUom::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        return response()->json($uom);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $uom = InvUom::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('inv_uoms')->where('tenant_id', $tenantId)->ignore($id)],
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $uom->update($validated);
        return response()->json($uom->fresh());
    }
}

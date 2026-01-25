<?php

namespace App\Http\Controllers;

use App\Models\InvStore;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvStoreController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = InvStore::where('tenant_id', $tenantId);

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $stores = $query->orderBy('name')->get();
        return response()->json($stores);
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('inv_stores')->where('tenant_id', $tenantId)],
            'type' => ['required', 'string', 'in:MAIN,FIELD,OTHER'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $store = InvStore::create(array_merge(
            ['tenant_id' => $tenantId],
            $validated,
            ['is_active' => $validated['is_active'] ?? true]
        ));

        return response()->json($store, 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $store = InvStore::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        return response()->json($store);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $store = InvStore::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('inv_stores')->where('tenant_id', $tenantId)->ignore($id)],
            'type' => ['sometimes', 'string', 'in:MAIN,FIELD,OTHER'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $store->update($validated);
        return response()->json($store->fresh());
    }
}

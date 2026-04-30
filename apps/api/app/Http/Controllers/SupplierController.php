<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $items = TenantScoped::for(Supplier::query(), $tenantId)
            ->orderBy('name')
            ->get();

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'party_id' => ['nullable', 'uuid'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $supplier = Supplier::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'status' => $validated['status'] ?? 'ACTIVE',
            'created_by' => $request->header('X-User-Id'),
        ]));

        return response()->json($supplier, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $supplier = TenantScoped::for(Supplier::query(), $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json($supplier);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $supplier = TenantScoped::for(Supplier::query(), $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'party_id' => ['nullable', 'uuid'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $supplier->update($validated);

        return response()->json($supplier);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $supplier = TenantScoped::for(Supplier::query(), $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $supplier->delete();

        return response()->json(null, 204);
    }
}


<?php

namespace App\Http\Controllers;

use App\Models\InvItem;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvItemController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = InvItem::where('tenant_id', $tenantId)->with(['category', 'uom']);

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $items = $query->orderBy('name')->get();
        return response()->json($items)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('inv_items')->where('tenant_id', $tenantId)],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('inv_items')->where('tenant_id', $tenantId)->whereNotNull('sku')],
            'category_id' => ['nullable', 'uuid', 'exists:inv_item_categories,id'],
            'uom_id' => ['required', 'uuid', 'exists:inv_uoms,id'],
            'valuation_method' => ['nullable', 'string', 'in:WAC,FIFO', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        \App\Models\InvUom::where('id', $validated['uom_id'])->where('tenant_id', $tenantId)->firstOrFail();
        if (!empty($validated['category_id'])) {
            \App\Models\InvItemCategory::where('id', $validated['category_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        $item = InvItem::create(array_merge(
            ['tenant_id' => $tenantId],
            $validated,
            ['valuation_method' => $validated['valuation_method'] ?? 'WAC', 'is_active' => $validated['is_active'] ?? true]
        ));

        return response()->json($item->load(['category', 'uom']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $item = InvItem::where('id', $id)->where('tenant_id', $tenantId)->with(['category', 'uom'])->firstOrFail();
        return response()->json($item);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $item = InvItem::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('inv_items')->where('tenant_id', $tenantId)->ignore($id)],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('inv_items')->where('tenant_id', $tenantId)->whereNotNull('sku')->ignore($id)],
            'category_id' => ['nullable', 'uuid', 'exists:inv_item_categories,id'],
            'uom_id' => ['sometimes', 'uuid', 'exists:inv_uoms,id'],
            'valuation_method' => ['nullable', 'string', 'in:WAC,FIFO', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $item->update($validated);
        return response()->json($item->fresh(['category', 'uom']));
    }
}

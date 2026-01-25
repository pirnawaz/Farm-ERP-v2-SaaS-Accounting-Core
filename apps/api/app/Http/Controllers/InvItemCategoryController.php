<?php

namespace App\Http\Controllers;

use App\Models\InvItemCategory;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvItemCategoryController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $categories = InvItemCategory::where('tenant_id', $tenantId)->orderBy('name')->get();
        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('inv_item_categories')->where('tenant_id', $tenantId)],
        ]);

        $category = InvItemCategory::create(array_merge(['tenant_id' => $tenantId], $validated));
        return response()->json($category, 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $category = InvItemCategory::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        return response()->json($category);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $category = InvItemCategory::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('inv_item_categories')->where('tenant_id', $tenantId)->ignore($id)],
        ]);

        $category->update($validated);
        return response()->json($category->fresh());
    }
}

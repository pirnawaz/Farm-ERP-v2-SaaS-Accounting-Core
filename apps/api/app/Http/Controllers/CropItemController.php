<?php

namespace App\Http\Controllers;

use App\Models\TenantCropItem;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CropItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $items = TenantCropItem::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('cropCatalogItem')
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get();

        $data = $items->map(function (TenantCropItem $item) {
            $catalog = $item->cropCatalogItem;
            $displayName = $item->display_name !== null && $item->display_name !== ''
                ? $item->display_name
                : ($catalog ? $catalog->default_name : $item->custom_name ?? '');
            return [
                'id' => $item->id,
                'display_name' => $displayName,
                'source' => $catalog ? 'global' : 'custom',
                'catalog_code' => $catalog?->code,
                'category' => $catalog?->category,
            ];
        });

        return response()->json($data)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'custom_name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
        ]);

        $displayName = $request->filled('display_name')
            ? $request->display_name
            : $request->custom_name;

        $item = TenantCropItem::create([
            'tenant_id' => $tenantId,
            'crop_catalog_item_id' => null,
            'custom_name' => $request->custom_name,
            'display_name' => $displayName,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return response()->json([
            'id' => $item->id,
            'display_name' => $item->display_name ?? $item->custom_name,
            'source' => 'custom',
            'catalog_code' => null,
            'category' => null,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $item = TenantCropItem::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $data = $request->only(['display_name', 'is_active', 'sort_order']);
        if (array_key_exists('display_name', $data) && $data['display_name'] === '') {
            $data['display_name'] = null;
        }
        $item->update($data);

        $item->load('cropCatalogItem');
        $catalog = $item->cropCatalogItem;
        $displayName = $item->display_name !== null && $item->display_name !== ''
            ? $item->display_name
            : ($catalog ? $catalog->default_name : $item->custom_name ?? '');

        return response()->json([
            'id' => $item->id,
            'display_name' => $displayName,
            'source' => $catalog ? 'global' : 'custom',
            'catalog_code' => $catalog?->code,
            'category' => $catalog?->category,
        ]);
    }
}

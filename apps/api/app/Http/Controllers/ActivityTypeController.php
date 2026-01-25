<?php

namespace App\Http\Controllers;

use App\Models\CropActivityType;
use App\Http\Requests\StoreActivityTypeRequest;
use App\Http\Requests\UpdateActivityTypeRequest;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class ActivityTypeController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = CropActivityType::where('tenant_id', $tenantId);

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $types = $query->orderBy('name')->get();
        return response()->json($types);
    }

    public function store(StoreActivityTypeRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $type = CropActivityType::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json($type, 201);
    }

    public function update(UpdateActivityTypeRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $type = CropActivityType::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $data = $request->only(['name', 'is_active']);
        $data = array_filter($data, fn ($v) => $v !== null);
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }
        $type->update($data);

        return response()->json($type->fresh());
    }
}

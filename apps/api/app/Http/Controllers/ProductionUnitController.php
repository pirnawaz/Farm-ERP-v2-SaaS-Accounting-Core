<?php

namespace App\Http\Controllers;

use App\Models\ProductionUnit;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Production units are optional operational continuity for long-lived work (orchards, livestock, multi-year crops).
 *
 * SEASONAL type is legacy-only: rows may still exist in the database for historical tenants; the API no longer
 * allows creating new SEASONAL units or converting existing LONG_CYCLE units to SEASONAL. This avoids duplicate
 * “seasonal crop cycle” modeling — seasonal accounting scope remains Crop Cycle + Field Cycle.
 */
class ProductionUnitController extends Controller
{
    private function effectiveCategory(?string $current, array $validated): ?string
    {
        if (array_key_exists('category', $validated)) {
            $v = $validated['category'];
            if ($v === '' || $v === null) return null;
            return (string) $v;
        }
        return $current;
    }

    private function normalizeNullableCategory(Request $request): void
    {
        if ($request->has('category') && $request->input('category') === '') {
            $request->merge(['category' => null]);
        }
    }

    /**
     * Allow SEASONAL only when the row is already SEASONAL (legacy); block new SEASONAL and downgrades to SEASONAL.
     */
    private function assertAllowedProductionUnitType(?ProductionUnit $existing, array $validated): void
    {
        if (! array_key_exists('type', $validated)) {
            return;
        }
        if ($validated['type'] !== ProductionUnit::TYPE_SEASONAL) {
            return;
        }
        if ($existing === null) {
            abort(422, 'Creating SEASONAL production units is no longer supported. Use Crop Cycle and Field Cycle for seasonal work, or LONG_CYCLE for orchards, livestock, and other long-lived units.');
        }
        if ($existing->type !== ProductionUnit::TYPE_SEASONAL) {
            abort(422, 'Cannot change type to SEASONAL.');
        }
    }

    private function validateCategorySemantics(array $validated, ?string $currentCategory = null): void
    {
        $category = $this->effectiveCategory($currentCategory, $validated);

        $orchardKeys = ['orchard_crop', 'planting_year', 'area_acres', 'tree_count'];
        $livestockKeys = ['livestock_type', 'herd_start_count'];

        $hasAnyOrchardField = Arr::hasAny($validated, $orchardKeys);
        $hasAnyLivestockField = Arr::hasAny($validated, $livestockKeys);

        if ($hasAnyOrchardField && $category !== ProductionUnit::CATEGORY_ORCHARD) {
            abort(422, 'Orchard fields require category ORCHARD.');
        }
        if ($hasAnyLivestockField && $category !== ProductionUnit::CATEGORY_LIVESTOCK) {
            abort(422, 'Livestock fields require category LIVESTOCK.');
        }

        if ($category === ProductionUnit::CATEGORY_ORCHARD || $category === ProductionUnit::CATEGORY_LIVESTOCK) {
            if (array_key_exists('type', $validated) && $validated['type'] !== ProductionUnit::TYPE_LONG_CYCLE) {
                abort(422, 'Category ' . $category . ' requires type LONG_CYCLE.');
            }
        }
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $query = ProductionUnit::where('tenant_id', $tenantId)
            ->orderBy('start_date', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('orchard_crop')) {
            $query->where('orchard_crop', $request->input('orchard_crop'));
        }

        $items = $query->get();

        return response()->json($items)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $this->normalizeNullableCategory($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in([ProductionUnit::TYPE_LONG_CYCLE])],
            'start_date' => ['required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'status' => ['nullable', 'string', Rule::in([ProductionUnit::STATUS_ACTIVE, ProductionUnit::STATUS_CLOSED])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', Rule::in([ProductionUnit::CATEGORY_ORCHARD, ProductionUnit::CATEGORY_LIVESTOCK])],
            'orchard_crop' => ['nullable', 'string', 'max:128'],
            'planting_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'area_acres' => ['nullable', 'numeric', 'min:0'],
            'tree_count' => ['nullable', 'integer', 'min:0'],
            'livestock_type' => ['nullable', 'string', 'max:64'],
            'herd_start_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->validateCategorySemantics($validated, null);

        $data = [
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'start_date' => $validated['start_date'],
            'status' => $validated['status'] ?? ProductionUnit::STATUS_ACTIVE,
        ];
        $data['end_date'] = $validated['end_date'] ?? null;
        $data['notes'] = $validated['notes'] ?? null;
        $data['category'] = $validated['category'] ?? null;
        $data['orchard_crop'] = $validated['orchard_crop'] ?? null;
        $data['planting_year'] = isset($validated['planting_year']) ? (int) $validated['planting_year'] : null;
        $data['area_acres'] = isset($validated['area_acres']) ? $validated['area_acres'] : null;
        $data['tree_count'] = isset($validated['tree_count']) ? (int) $validated['tree_count'] : null;
        $data['livestock_type'] = $validated['livestock_type'] ?? null;
        $data['herd_start_count'] = isset($validated['herd_start_count']) ? (int) $validated['herd_start_count'] : null;

        $unit = ProductionUnit::create($data);

        return response()->json($unit, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $unit = ProductionUnit::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return response()->json($unit);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $this->normalizeNullableCategory($request);

        $unit = ProductionUnit::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', Rule::in([ProductionUnit::TYPE_SEASONAL, ProductionUnit::TYPE_LONG_CYCLE])],
            'start_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'required', 'string', Rule::in([ProductionUnit::STATUS_ACTIVE, ProductionUnit::STATUS_CLOSED])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', Rule::in([ProductionUnit::CATEGORY_ORCHARD, ProductionUnit::CATEGORY_LIVESTOCK])],
            'orchard_crop' => ['nullable', 'string', 'max:128'],
            'planting_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'area_acres' => ['nullable', 'numeric', 'min:0'],
            'tree_count' => ['nullable', 'integer', 'min:0'],
            'livestock_type' => ['nullable', 'string', 'max:64'],
            'herd_start_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->assertAllowedProductionUnitType($unit, $validated);
        $this->validateCategorySemantics($validated, $unit->category);

        $allowedKeys = ['name', 'type', 'start_date', 'end_date', 'status', 'notes', 'category', 'orchard_crop', 'planting_year', 'area_acres', 'tree_count', 'livestock_type', 'herd_start_count'];
        $data = array_intersect_key($validated, array_flip($allowedKeys));
        if (array_key_exists('end_date', $validated) && $validated['end_date'] === '') {
            $data['end_date'] = null;
        }
        if (array_key_exists('notes', $validated) && $validated['notes'] === '') {
            $data['notes'] = null;
        }
        if (array_key_exists('category', $validated) && $validated['category'] === '') {
            $data['category'] = null;
        }
        if (array_key_exists('orchard_crop', $validated) && $validated['orchard_crop'] === '') {
            $data['orchard_crop'] = null;
        }
        if (array_key_exists('planting_year', $validated) && $validated['planting_year'] === '') {
            $data['planting_year'] = null;
        }
        if (array_key_exists('area_acres', $validated) && $validated['area_acres'] === '') {
            $data['area_acres'] = null;
        }
        if (array_key_exists('tree_count', $validated) && $validated['tree_count'] === '') {
            $data['tree_count'] = null;
        }
        if (array_key_exists('livestock_type', $validated) && $validated['livestock_type'] === '') {
            $data['livestock_type'] = null;
        }
        if (array_key_exists('herd_start_count', $validated) && $validated['herd_start_count'] === '') {
            $data['herd_start_count'] = null;
        }
        if (isset($data['planting_year'])) {
            $data['planting_year'] = (int) $data['planting_year'];
        }
        if (isset($data['tree_count'])) {
            $data['tree_count'] = (int) $data['tree_count'];
        }
        if (isset($data['herd_start_count'])) {
            $data['herd_start_count'] = (int) $data['herd_start_count'];
        }

        $unit->update($data);

        return response()->json($unit);
    }
}

<?php

namespace App\Http\Controllers\Machinery;

use App\Http\Controllers\Controller;
use App\Models\MachineRateCard;
use App\Models\Machine;
use App\Models\CropActivityType;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class MachineRateCardController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = MachineRateCard::where('tenant_id', $tenantId);

        if ($request->filled('machine_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('applies_to_mode', MachineRateCard::APPLIES_TO_MACHINE)
                  ->where('machine_id', $request->machine_id);
            });
        }

        if ($request->filled('machine_type')) {
            $query->where(function ($q) use ($request) {
                $q->where('applies_to_mode', MachineRateCard::APPLIES_TO_MACHINE_TYPE)
                  ->where('machine_type', $request->machine_type);
            });
        }

        if ($request->filled('rate_unit')) {
            $query->where('rate_unit', $request->rate_unit);
        }

        if ($request->filled('date')) {
            $date = $request->date;
            $query->where(function ($q) use ($date) {
                $q->where('effective_from', '<=', $date)
                  ->where(function ($q2) use ($date) {
                      $q2->whereNull('effective_to')
                         ->orWhere('effective_to', '>=', $date);
                  });
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $rateCards = $query->with(['machine', 'activityType'])
            ->orderBy('effective_from', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($rateCards)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $this->validateRateCard($request, $tenantId);

        // Validate non-overlapping rate cards
        $this->validateNoOverlap($validated, $tenantId);

        $rateCard = MachineRateCard::create(array_merge(
            ['tenant_id' => $tenantId],
            $validated
        ));

        return response()->json($rateCard, 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $rateCard = MachineRateCard::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['machine', 'activityType'])
            ->firstOrFail();
        return response()->json($rateCard);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $rateCard = MachineRateCard::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $this->validateRateCard($request, $tenantId, $id);

        // Validate non-overlapping rate cards (excluding current one)
        $this->validateNoOverlap($validated, $tenantId, $id);

        $rateCard->update($validated);
        return response()->json($rateCard->fresh());
    }

    private function validateRateCard(Request $request, string $tenantId, ?string $excludeId = null): array
    {
        $rules = [
            'applies_to_mode' => ['required', 'string', Rule::in([MachineRateCard::APPLIES_TO_MACHINE, MachineRateCard::APPLIES_TO_MACHINE_TYPE])],
            'machine_id' => ['nullable', 'uuid', 'exists:machines,id'],
            'machine_type' => ['nullable', 'string', 'max:255'],
            'activity_type_id' => ['nullable', 'uuid', 'exists:crop_activity_types,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'rate_unit' => ['required', 'string', Rule::in([MachineRateCard::RATE_UNIT_HOUR, MachineRateCard::RATE_UNIT_KM, MachineRateCard::RATE_UNIT_JOB])],
            'pricing_model' => ['required', 'string', Rule::in([MachineRateCard::PRICING_MODEL_FIXED, MachineRateCard::PRICING_MODEL_COST_PLUS])],
            'base_rate' => ['required', 'numeric', 'min:0'],
            'cost_plus_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'includes_fuel' => ['nullable', 'boolean'],
            'includes_operator' => ['nullable', 'boolean'],
            'includes_maintenance' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];

        $validated = $request->validate($rules);

        // Enforce applies_to rules
        if ($validated['applies_to_mode'] === MachineRateCard::APPLIES_TO_MACHINE) {
            if (empty($validated['machine_id'])) {
                abort(422, 'machine_id is required when applies_to_mode is MACHINE');
            }
            $validated['machine_type'] = null;
            
            // Verify machine belongs to tenant
            Machine::where('id', $validated['machine_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        } else {
            if (empty($validated['machine_type'])) {
                abort(422, 'machine_type is required when applies_to_mode is MACHINE_TYPE');
            }
            $validated['machine_id'] = null;
        }

        // Ensure activity_type_id when provided belongs to tenant
        if (!empty($validated['activity_type_id'])) {
            CropActivityType::where('id', $validated['activity_type_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        } else {
            $validated['activity_type_id'] = null;
        }

        // Validate cost_plus_percent required for COST_PLUS pricing model
        if ($validated['pricing_model'] === MachineRateCard::PRICING_MODEL_COST_PLUS && empty($validated['cost_plus_percent'])) {
            abort(422, 'cost_plus_percent is required when pricing_model is COST_PLUS');
        }

        return $validated;
    }

    private function validateNoOverlap(array $validated, string $tenantId, ?string $excludeId = null): void
    {
        if (!($validated['is_active'] ?? true)) {
            // Inactive rate cards don't need overlap validation
            return;
        }

        $query = MachineRateCard::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('rate_unit', $validated['rate_unit']);

        // Match by target (machine_id or machine_type based on applies_to_mode)
        if ($validated['applies_to_mode'] === MachineRateCard::APPLIES_TO_MACHINE) {
            $query->where('applies_to_mode', MachineRateCard::APPLIES_TO_MACHINE)
                  ->where('machine_id', $validated['machine_id']);
        } else {
            $query->where('applies_to_mode', MachineRateCard::APPLIES_TO_MACHINE_TYPE)
                  ->where('machine_type', $validated['machine_type']);
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        // Check for date range overlap
        $effectiveFrom = $validated['effective_from'];
        $effectiveTo = $validated['effective_to'] ?? null;

        $overlapping = $query->where(function ($q) use ($effectiveFrom, $effectiveTo) {
            $q->where(function ($q2) use ($effectiveFrom, $effectiveTo) {
                // New range starts before existing range ends
                $q2->where('effective_from', '<=', $effectiveFrom)
                   ->where(function ($q3) use ($effectiveFrom) {
                       $q3->whereNull('effective_to')
                          ->orWhere('effective_to', '>=', $effectiveFrom);
                   });
            })->orWhere(function ($q2) use ($effectiveFrom, $effectiveTo) {
                // New range ends after existing range starts
                if ($effectiveTo) {
                    $q2->where('effective_from', '<=', $effectiveTo)
                       ->where(function ($q3) use ($effectiveTo) {
                           $q3->whereNull('effective_to')
                              ->orWhere('effective_to', '>=', $effectiveTo);
                       });
                } else {
                    // New range has no end date, check if any existing range starts after new range starts
                    $q2->where('effective_from', '>=', $effectiveFrom);
                }
            });
        })->exists();

        if ($overlapping) {
            abort(422, 'Active rate card with overlapping date range already exists for the same target and rate unit');
        }
    }
}

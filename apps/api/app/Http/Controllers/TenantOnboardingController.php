<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantOnboardingController extends Controller
{
    private const STEP_KEYS = [
        'farm_profile',
        'add_land_parcel',
        'create_crop_cycle',
        'create_first_project',
        'add_first_party',
        'post_first_transaction',
    ];

    /**
     * Get onboarding state for the current tenant.
     * GET /api/tenant/onboarding
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $onboarding = $this->getOnboardingState($tenant);
        return response()->json($onboarding);
    }

    /**
     * Update onboarding state (dismiss or mark steps complete).
     * PUT /api/tenant/onboarding
     */
    public function update(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $validated = $request->validate([
            'dismissed' => ['sometimes', 'boolean'],
            'steps' => ['sometimes', 'array'],
            'steps.*' => ['boolean'],
        ]);

        $settings = $tenant->settings ?? [];
        $onboarding = $settings['onboarding'] ?? ['dismissed' => false, 'steps' => []];

        if (array_key_exists('dismissed', $validated)) {
            $onboarding['dismissed'] = $validated['dismissed'];
        }
        if (isset($validated['steps'])) {
            foreach (self::STEP_KEYS as $key) {
                if (array_key_exists($key, $validated['steps'])) {
                    $onboarding['steps'][$key] = $validated['steps'][$key];
                }
            }
        }

        $settings['onboarding'] = $onboarding;
        $tenant->update(['settings' => $settings]);
        $tenant->refresh();

        return response()->json($this->getOnboardingState($tenant));
    }

    private function getOnboardingState($tenant): array
    {
        $settings = $tenant->settings ?? [];
        $onboarding = $settings['onboarding'] ?? ['dismissed' => false, 'steps' => []];
        $steps = [];
        foreach (self::STEP_KEYS as $key) {
            $steps[$key] = $onboarding['steps'][$key] ?? false;
        }
        return [
            'dismissed' => $onboarding['dismissed'] ?? false,
            'steps' => $steps,
        ];
    }
}

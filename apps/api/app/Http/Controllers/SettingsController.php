<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    /**
     * Get current tenant's localization settings
     * GET /api/settings/tenant
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant($request);
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        return response()->json([
            'currency_code' => $tenant->currency_code ?? 'GBP',
            'locale' => $tenant->locale ?? 'en-GB',
            'timezone' => $tenant->timezone ?? 'Europe/London',
        ]);
    }

    /**
     * Update tenant localization settings
     * PUT /api/settings/tenant
     */
    public function update(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant($request);
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Validate input
        $validated = $request->validate([
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'locale' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
            'timezone' => ['required', 'string', 'max:64'],
        ]);

        // Validate timezone using PHP DateTimeZone
        $validTimezones = \DateTimeZone::listIdentifiers();
        if (!in_array($validated['timezone'], $validTimezones)) {
            throw ValidationException::withMessages([
                'timezone' => ['The selected timezone is invalid.'],
            ]);
        }

        // Update tenant settings
        $tenant->update([
            'currency_code' => strtoupper($validated['currency_code']),
            'locale' => $validated['locale'],
            'timezone' => $validated['timezone'],
        ]);

        return response()->json([
            'currency_code' => $tenant->currency_code,
            'locale' => $tenant->locale,
            'timezone' => $tenant->timezone,
        ]);
    }
}

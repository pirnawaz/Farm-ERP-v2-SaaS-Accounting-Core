<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use App\Support\TenantLocalisation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

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
            'currency_code' => $tenant->currency_code ?? 'PKR',
            'locale' => $tenant->locale ?? 'en-PK',
            'timezone' => $tenant->timezone ?? 'Asia/Karachi',
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

        $data = $request->validate([
            'currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'locale' => ['required', 'string', 'max:32'],
            'timezone' => ['required', 'string', 'max:64'],
        ]);

        $currency = strtoupper($data['currency_code']);
        $localeNorm = TenantLocalisation::normalizeLocale($data['locale']);
        $timezone = trim($data['timezone']);

        $currentCurrency = strtoupper((string) ($tenant->currency_code ?? ''));
        $tenantLocaleNorm = TenantLocalisation::normalizeLocale((string) ($tenant->locale ?? ''));
        $currentTimezone = (string) ($tenant->timezone ?? '');

        $errors = [];

        if (!TenantLocalisation::isAllowedCurrency($currency) && $currency !== $currentCurrency) {
            $errors['currency_code'] = ['The selected currency is not supported.'];
        }

        if (!TenantLocalisation::isAllowedLocale($localeNorm) && $localeNorm !== $tenantLocaleNorm) {
            $errors['locale'] = ['The selected locale is not supported.'];
        }

        if (!TenantLocalisation::isAllowedTimezone($timezone) && $timezone !== $currentTimezone) {
            $errors['timezone'] = ['The selected timezone is not supported.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $tenant->update([
            'currency_code' => $currency,
            'locale' => $localeNorm,
            'timezone' => $timezone,
        ]);

        return response()->json([
            'currency_code' => $tenant->currency_code,
            'locale' => $tenant->locale,
            'timezone' => $tenant->timezone,
        ]);
    }
}

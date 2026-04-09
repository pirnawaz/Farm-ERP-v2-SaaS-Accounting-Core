<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\MultiCurrency\ExchangeRate;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExchangeRateController extends Controller
{
    /**
     * List stored rates (audit / lookup). Ordered by date desc, then pair.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $q = TenantScoped::for(ExchangeRate::query(), $tenantId)
            ->orderByDesc('rate_date')
            ->orderBy('base_currency_code')
            ->orderBy('quote_currency_code');

        if ($request->filled('from_date')) {
            $q->whereDate('rate_date', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $q->whereDate('rate_date', '<=', $request->input('to_date'));
        }
        if ($request->filled('base_currency_code')) {
            $q->where('base_currency_code', strtoupper($request->string('base_currency_code')));
        }
        if ($request->filled('quote_currency_code')) {
            $q->where('quote_currency_code', strtoupper($request->string('quote_currency_code')));
        }

        return response()->json($q->get());
    }

    /**
     * Store one rate row (unique per tenant + date + pair).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $data = $request->validate([
            'rate_date' => ['required', 'date', 'date_format:Y-m-d'],
            'base_currency_code' => ['required', 'string', 'size:3'],
            'quote_currency_code' => ['required', 'string', 'size:3'],
            'rate' => ['required', 'numeric', 'min:0.00000001'],
            'source' => ['nullable', 'string', 'max:128'],
        ]);

        $base = strtoupper($data['base_currency_code']);
        $quote = strtoupper($data['quote_currency_code']);
        if ($base === $quote) {
            throw ValidationException::withMessages([
                'quote_currency_code' => ['Base and quote currency must differ.'],
            ]);
        }

        $exists = ExchangeRate::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('rate_date', $data['rate_date'])
            ->where('base_currency_code', $base)
            ->where('quote_currency_code', $quote)
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'rate_date' => ['A rate for this date and currency pair already exists. Update or pick another date.'],
            ]);
        }

        $userId = $request->attributes->get('user_id');
        $createdBy = is_string($userId) && $userId !== '' ? $userId : null;

        try {
            $row = ExchangeRate::create([
                'tenant_id' => $tenantId,
                'rate_date' => $data['rate_date'],
                'base_currency_code' => $base,
                'quote_currency_code' => $quote,
                'rate' => $data['rate'],
                'source' => $data['source'] ?? 'manual',
                'created_by' => $createdBy,
            ]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate')) {
                throw ValidationException::withMessages([
                    'rate_date' => ['A rate for this date and currency pair already exists.'],
                ]);
            }
            throw $e;
        }

        return response()->json($row->fresh(), 201);
    }
}

<?php

namespace App\Http\Controllers\Machinery;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;

class MachineController extends Controller
{
    private const CODE_PREFIX = 'MCH-';
    private const CODE_PAD_LENGTH = 6;
    private const MAX_CODE_RETRIES = 3;

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = Machine::where('tenant_id', $tenantId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('is_active') && $request->is_active !== '') {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('machine_type')) {
            $query->where('machine_type', $request->machine_type);
        }
        if ($request->filled('ownership_type')) {
            $query->where('ownership_type', $request->ownership_type);
        }

        $machines = $query->orderBy('code')->get();
        return response()->json($machines)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:255', Rule::unique('machines')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'machine_type' => ['required', 'string', 'max:255'],
            'ownership_type' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'meter_unit' => ['required', 'string', 'in:HOURS,KM'],
            'opening_meter' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $userProvidedCode = isset($validated['code']) && trim((string) $validated['code']) !== ''
            ? trim($validated['code'])
            : null;

        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_CODE_RETRIES) {
            $code = $userProvidedCode ?? $this->generateCode($tenantId);
            try {
                $machine = DB::transaction(function () use ($tenantId, $code, $validated) {
                    return Machine::create([
                        'tenant_id' => $tenantId,
                        'code' => $code,
                        'name' => $validated['name'],
                        'machine_type' => $validated['machine_type'],
                        'ownership_type' => $validated['ownership_type'],
                        'status' => ($validated['is_active'] ?? true) ? 'Active' : 'Inactive',
                        'is_active' => $validated['is_active'] ?? true,
                        'meter_unit' => $validated['meter_unit'],
                        'opening_meter' => $validated['opening_meter'] ?? 0,
                        'notes' => $validated['notes'] ?? null,
                    ]);
                });
                return response()->json($machine, 201);
            } catch (QueryException $e) {
                $lastException = $e;
                // PostgreSQL unique_violation = 23505
                if (($e->getCode() === '23505' || ($e->errorInfo[0] ?? null) === '23505') && $userProvidedCode === null) {
                    $attempt++;
                    continue;
                }
                throw $e;
            }
        }

        return response()->json([
            'message' => 'Could not generate a unique machine code. Please try again.',
        ], 422);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $machine = Machine::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        return response()->json($machine);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $machine = Machine::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'machine_type' => ['sometimes', 'string', 'max:255'],
            'ownership_type' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'meter_unit' => ['sometimes', 'string', 'in:HOURS,KM'],
            'opening_meter' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $update = array_filter($validated, fn ($v) => $v !== null);
        if (array_key_exists('is_active', $validated)) {
            $update['status'] = $validated['is_active'] ? 'Active' : 'Inactive';
        }
        $machine->update($update);
        return response()->json($machine->fresh());
    }

    private function generateCode(string $tenantId): string
    {
        $last = Machine::where('tenant_id', $tenantId)
            ->where('code', 'like', self::CODE_PREFIX . '%')
            ->orderByRaw('LENGTH(code) DESC, code DESC')
            ->first();

        $next = 1;
        if ($last && preg_match('/^' . preg_quote(self::CODE_PREFIX, '/') . '(\d+)$/', $last->code, $m)) {
            $next = (int) $m[1] + 1;
        }

        return self::CODE_PREFIX . str_pad((string) $next, self::CODE_PAD_LENGTH, '0', STR_PAD_LEFT);
    }
}

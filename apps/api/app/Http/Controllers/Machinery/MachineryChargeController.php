<?php

namespace App\Http\Controllers\Machinery;

use App\Http\Controllers\Controller;
use App\Models\MachineryCharge;
use App\Models\MachineryChargeLine;
use App\Models\Project;
use App\Models\Party;
use App\Services\Machinery\MachineryChargeService;
use App\Services\Machinery\MachineryChargePostingService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MachineryChargeController extends Controller
{
    public function __construct(
        private MachineryChargeService $chargeService,
        private MachineryChargePostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = MachineryCharge::where('tenant_id', $tenantId)
            ->with(['project', 'cropCycle', 'landlordParty', 'postingGroup', 'reversalPostingGroup']);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from')) {
            $query->where('charge_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('charge_date', '<=', $request->to);
        }
        if ($request->filled('landlord_party_id')) {
            $query->where('landlord_party_id', $request->landlord_party_id);
        }

        $charges = $query->orderBy('charge_date', 'desc')->orderBy('created_at', 'desc')->get();
        return response()->json($charges)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $charge = MachineryCharge::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with([
                'lines.workLog.machine',
                'lines.rateCard',
                'project',
                'cropCycle',
                'landlordParty',
                'postingGroup',
                'reversalPostingGroup'
            ])
            ->firstOrFail();
        return response()->json($charge);
    }

    public function generate(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'landlord_party_id' => ['required', 'uuid', 'exists:parties,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'pool_scope' => ['nullable', 'string', Rule::in([MachineryCharge::POOL_SCOPE_SHARED, MachineryCharge::POOL_SCOPE_HARI_ONLY])],
            'charge_date' => ['nullable', 'date'],
        ]);

        // Verify project belongs to tenant
        Project::where('id', $validated['project_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Verify landlord party belongs to tenant
        Party::where('id', $validated['landlord_party_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $result = $this->chargeService->generateDraftChargeForProject(
            $tenantId,
            $validated['project_id'],
            $validated['landlord_party_id'],
            $validated['from'],
            $validated['to'],
            $validated['pool_scope'] ?? null,
            $validated['charge_date'] ?? null
        );

        // If array (two charges), return both
        if (is_array($result)) {
            return response()->json($result, 201);
        }

        // Single charge
        return response()->json($result, 201);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $charge = MachineryCharge::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', MachineryCharge::STATUS_DRAFT)
            ->with('lines')
            ->firstOrFail();

        $validated = $request->validate([
            'charge_date' => ['sometimes', 'date'],
            'landlord_party_id' => ['sometimes', 'uuid', 'exists:parties,id'],
            'lines' => ['sometimes', 'array'],
            'lines.*.id' => ['required_with:lines', 'uuid', 'exists:machinery_charge_lines,id'],
            'lines.*.rate' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($charge, $tenantId, $validated) {
            // Update charge header fields
            if (isset($validated['charge_date'])) {
                $charge->charge_date = $validated['charge_date'];
            }
            if (isset($validated['landlord_party_id'])) {
                // Verify party belongs to tenant
                Party::where('id', $validated['landlord_party_id'])
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();
                $charge->landlord_party_id = $validated['landlord_party_id'];
            }

            // Update lines if provided
            if (isset($validated['lines'])) {
                $totalAmount = 0;
                foreach ($validated['lines'] as $lineData) {
                    $line = MachineryChargeLine::where('id', $lineData['id'])
                        ->where('machinery_charge_id', $charge->id)
                        ->where('tenant_id', $tenantId)
                        ->firstOrFail();

                    $line->rate = (string) $lineData['rate'];
                    $line->amount = (string) $lineData['amount'];
                    $line->save();

                    $totalAmount += (float) $lineData['amount'];
                }
                $charge->total_amount = $totalAmount;
            }

            $charge->save();

            return response()->json($charge->fresh([
                'lines.workLog.machine',
                'lines.rateCard',
                'project',
                'cropCycle',
                'landlordParty'
            ]));
        });
    }

    public function post(Request $request, string $id)
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');

        $validated = $request->validate([
            'posting_date' => ['required', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $pg = $this->postingService->postCharge(
            $id,
            $tenantId,
            $validated['posting_date'],
            $idempotencyKey
        );

        $charge = MachineryCharge::where('id', $id)->where('tenant_id', $tenantId)
            ->with([
                'lines.workLog.machine',
                'lines.rateCard',
                'project',
                'cropCycle',
                'landlordParty',
                'postingGroup',
                'reversalPostingGroup'
            ])
            ->firstOrFail();

        return response()->json([
            'posting_group' => $pg,
            'charge' => $charge,
        ], 201);
    }

    public function reverse(Request $request, string $id)
    {
        $this->authorizeReversal($request);

        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'posting_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $pg = $this->postingService->reverseCharge(
            $id,
            $tenantId,
            $validated['posting_date'],
            $validated['reason'] ?? null
        );

        $charge = MachineryCharge::where('id', $id)->where('tenant_id', $tenantId)
            ->with([
                'lines.workLog.machine',
                'lines.rateCard',
                'project',
                'cropCycle',
                'landlordParty',
                'postingGroup',
                'reversalPostingGroup'
            ])
            ->firstOrFail();

        return response()->json([
            'posting_group' => $pg,
            'charge' => $charge,
        ], 201);
    }
}

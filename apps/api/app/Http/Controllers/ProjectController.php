<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\LandAllocation;
use App\Models\AgreementAllocation;
use App\Models\CropCycle;
use App\Models\Party;
use App\Http\Requests\FieldCycleSetupRequest;
use App\Domains\Operations\SetupFieldCycleAction;
use Carbon\Carbon;
use App\Services\ProjectSettlementRuleResolver;
use App\Services\TenantContext;
use App\Services\SystemPartyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function __construct(
        private ProjectSettlementRuleResolver $settlementRuleResolver
    ) {}
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $query = Project::where('tenant_id', $tenantId)
            ->with(['cropCycle', 'party', 'landAllocation', 'agreement', 'agreementAllocation']);

        if ($request->has('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }

        $projects = $query->orderBy('name')->get();
        
        return response()->json($projects)
            ->header('Cache-Control', 'no-store')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
            'land_allocation_id' => ['nullable', 'uuid', 'exists:land_allocations,id'],
            'agreement_id' => ['nullable', 'uuid', 'exists:agreements,id'],
            'agreement_allocation_id' => ['nullable', 'uuid', 'exists:agreement_allocations,id'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,CLOSED'],
        ]);

        // Verify party and crop cycle belong to tenant
        Party::where('id', $request->party_id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        CropCycle::where('id', $request->crop_cycle_id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->assertAgreementAllocationConsistency($tenantId, $request);

        $agreementId = $request->agreement_id;
        if ($request->filled('agreement_allocation_id') && empty($agreementId)) {
            $agreementId = AgreementAllocation::where('tenant_id', $tenantId)
                ->where('id', $request->agreement_allocation_id)
                ->value('agreement_id');
        }

        $this->assertSettlementResolvableWhenAgreementLinked($tenantId, $agreementId ? (string) $agreementId : null, null);

        $project = Project::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'party_id' => $request->party_id,
            'crop_cycle_id' => $request->crop_cycle_id,
            'land_allocation_id' => $request->land_allocation_id,
            'agreement_id' => $agreementId,
            'agreement_allocation_id' => $request->agreement_allocation_id,
            'status' => $request->status ?? 'ACTIVE',
        ]);

        return response()->json($project->load(['cropCycle', 'party', 'landAllocation', 'agreement', 'agreementAllocation']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['cropCycle', 'party', 'landAllocation', 'projectRule', 'agreement', 'agreementAllocation.landParcel', 'agreementAllocation.agreement'])
            ->firstOrFail();

        try {
            $settlementResolution = $this->settlementRuleResolver->resolveSettlementRule($project);
        } catch (\RuntimeException $e) {
            $settlementResolution = [
                'resolution_source' => 'unresolved',
                'message' => $e->getMessage(),
            ];
        }

        return response()->json(array_merge($project->toArray(), [
            'settlement_resolution' => $settlementResolution,
        ]));
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'party_id' => ['sometimes', 'required', 'uuid', 'exists:parties,id'],
            'crop_cycle_id' => ['sometimes', 'required', 'uuid', 'exists:crop_cycles,id'],
            'land_allocation_id' => ['sometimes', 'nullable', 'uuid', 'exists:land_allocations,id'],
            'agreement_id' => ['sometimes', 'nullable', 'uuid', 'exists:agreements,id'],
            'agreement_allocation_id' => ['sometimes', 'nullable', 'uuid', 'exists:agreement_allocations,id'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,CLOSED'],
        ]);

        $this->assertAgreementAllocationConsistency($tenantId, $request, $project);

        $updates = $request->only([
            'name', 'party_id', 'crop_cycle_id', 'land_allocation_id',
            'agreement_id', 'agreement_allocation_id', 'status',
        ]);
        if (array_key_exists('agreement_allocation_id', $updates) && $updates['agreement_allocation_id'] && empty($updates['agreement_id'] ?? $project->agreement_id)) {
            $updates['agreement_id'] = AgreementAllocation::where('tenant_id', $tenantId)
                ->where('id', $updates['agreement_allocation_id'])
                ->value('agreement_id');
        }

        $nextAgreementId = array_key_exists('agreement_id', $updates)
            ? $updates['agreement_id']
            : $project->agreement_id;
        if ($nextAgreementId !== null && $nextAgreementId !== '') {
            $this->assertSettlementResolvableWhenAgreementLinked($tenantId, (string) $nextAgreementId, $project->id);
        }

        $project->update($updates);

        return response()->json($project->load(['cropCycle', 'party', 'landAllocation', 'agreement', 'agreementAllocation']));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $project->delete();

        return response()->json(null, 204);
    }

    public function fromAllocation(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'land_allocation_id' => ['required', 'uuid', 'exists:land_allocations,id'],
        ]);

        $allocation = LandAllocation::where('id', $request->land_allocation_id)
            ->where('tenant_id', $tenantId)
            ->with(['cropCycle', 'landParcel', 'party'])
            ->firstOrFail();

        // Handle owner-operated allocations (party_id is null)
        $partyId = $allocation->party_id;
        $partyName = 'Owner-operated';
        
        if ($partyId === null) {
            // Use system landlord party for owner-operated allocations
            $partyService = new SystemPartyService();
            $landlordParty = $partyService->ensureSystemLandlordParty($tenantId);
            $partyId = $landlordParty->id;
        } else {
            $partyName = $allocation->party->name;
        }

        // Build project name
        $projectName = sprintf(
            '%s – %s – %s – %s acres',
            $allocation->cropCycle->name,
            $allocation->landParcel->name,
            $partyName,
            $allocation->allocated_acres
        );

        $project = Project::create([
            'tenant_id' => $tenantId,
            'name' => $projectName,
            'party_id' => $partyId,
            'crop_cycle_id' => $allocation->crop_cycle_id,
            'land_allocation_id' => $allocation->id,
            'status' => 'ACTIVE',
        ]);

        return response()->json($project->load(['cropCycle', 'party', 'landAllocation']), 201);
    }

    public function fromAgreementAllocation(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'agreement_allocation_id' => ['required', 'uuid', 'exists:agreement_allocations,id'],
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $alloc = AgreementAllocation::where('tenant_id', $tenantId)
            ->where('id', $request->agreement_allocation_id)
            ->with(['landParcel', 'agreement'])
            ->firstOrFail();

        if ($alloc->status !== AgreementAllocation::STATUS_ACTIVE) {
            return response()->json(['message' => 'Agreement allocation is not active.'], 422);
        }

        $cropCycle = CropCycle::where('tenant_id', $tenantId)->where('id', $request->crop_cycle_id)->firstOrFail();

        $cycleStart = Carbon::parse($cropCycle->start_date)->startOfDay();
        $cycleEnd = Carbon::parse($cropCycle->end_date)->endOfDay();
        $allocStart = Carbon::parse($alloc->starts_on)->startOfDay();
        $allocEnd = $alloc->ends_on ? Carbon::parse($alloc->ends_on)->endOfDay() : Carbon::parse('2099-12-31')->endOfDay();

        if ($cycleStart->gt($allocEnd) || $cycleEnd->lt($allocStart)) {
            return response()->json(['message' => 'Crop cycle does not overlap agreement allocation dates.'], 422);
        }

        Party::where('tenant_id', $tenantId)->where('id', $request->party_id)->firstOrFail();

        $this->assertSettlementResolvableWhenAgreementLinked($tenantId, (string) $alloc->agreement_id, null);

        $parcelName = $alloc->landParcel?->name ?? 'Parcel';
        $name = $request->name ?: sprintf('%s — %s', $cropCycle->name, $parcelName);

        $project = Project::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'party_id' => $request->party_id,
            'crop_cycle_id' => $request->crop_cycle_id,
            'agreement_id' => $alloc->agreement_id,
            'agreement_allocation_id' => $alloc->id,
            'status' => 'ACTIVE',
        ]);

        return response()->json($project->load(['cropCycle', 'party', 'agreement', 'agreementAllocation.landParcel']), 201);
    }

    private function assertAgreementAllocationConsistency(string $tenantId, Request $request, ?Project $existing = null): void
    {
        $allocationId = $request->input('agreement_allocation_id');
        if ($allocationId === null || $allocationId === '') {
            return;
        }

        $alloc = AgreementAllocation::where('tenant_id', $tenantId)->where('id', $allocationId)->firstOrFail();

        $agreementId = $request->input('agreement_id');
        if ($agreementId === null || $agreementId === '') {
            $agreementId = $existing?->agreement_id;
        }
        if ($agreementId !== null && (string) $agreementId !== (string) $alloc->agreement_id) {
            abort(422, 'agreement_id must match the selected agreement allocation\'s agreement.');
        }

        $cropCycleId = $request->input('crop_cycle_id', $existing?->crop_cycle_id);
        if ($cropCycleId) {
            $cropCycle = CropCycle::where('tenant_id', $tenantId)->where('id', $cropCycleId)->first();
            if ($cropCycle) {
                $cycleStart = Carbon::parse($cropCycle->start_date)->startOfDay();
                $cycleEnd = Carbon::parse($cropCycle->end_date)->endOfDay();
                $allocStart = Carbon::parse($alloc->starts_on)->startOfDay();
                $allocEnd = $alloc->ends_on ? Carbon::parse($alloc->ends_on)->endOfDay() : Carbon::parse('2099-12-31')->endOfDay();
                if ($cycleStart->gt($allocEnd) || $cycleEnd->lt($allocStart)) {
                    abort(422, 'Crop cycle does not overlap agreement allocation dates.');
                }
            }
        }

        if ($alloc->status !== AgreementAllocation::STATUS_ACTIVE) {
            abort(422, 'Agreement allocation is not active.');
        }
    }

    /**
     * When a project is linked to an agreement, settlement must resolve from agreement terms and/or existing project rules.
     * For new projects (no id yet), a temporary id is used so only agreement terms apply.
     */
    private function assertSettlementResolvableWhenAgreementLinked(string $tenantId, ?string $agreementId, ?string $projectId): void
    {
        if ($agreementId === null || $agreementId === '') {
            return;
        }

        $probe = new Project();
        $probe->tenant_id = $tenantId;
        $probe->agreement_id = $agreementId;
        $probe->id = $projectId ?? (string) Str::uuid();

        try {
            $this->settlementRuleResolver->resolveSettlementRule($probe);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function fieldCycleSetup(FieldCycleSetupRequest $request, SetupFieldCycleAction $action)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = $action->execute($tenantId, $request->validated());
        $project = $project->load([
            'cropCycle',
            'party',
            'landAllocation',
            'projectRule',
            'agreement',
            'agreementAllocation.landParcel',
            'agreementAllocation.agreement',
        ]);

        try {
            $settlementResolution = $this->settlementRuleResolver->resolveSettlementRule($project);
        } catch (\RuntimeException $e) {
            $settlementResolution = [
                'resolution_source' => 'unresolved',
                'message' => $e->getMessage(),
            ];
        }

        return response()->json(array_merge($project->toArray(), [
            'settlement_resolution' => $settlementResolution,
        ]), 201);
    }

    public function close(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($project->status === 'CLOSED') {
            return response()->json($project->load(['cropCycle', 'party', 'landAllocation']));
        }

        $project->update([
            'status' => 'CLOSED',
            'closed_at' => now(),
        ]);

        return response()->json($project->load(['cropCycle', 'party', 'landAllocation']));
    }

    public function reopen(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $hasPostedSettlement = $project->settlements()->where('status', 'POSTED')->exists();
        if ($hasPostedSettlement) {
            return response()->json([
                'message' => 'Cannot reopen project that has a posted settlement.',
            ], 422);
        }

        $project->update([
            'status' => 'ACTIVE',
            'closed_at' => null,
        ]);

        return response()->json($project->load(['cropCycle', 'party', 'landAllocation']));
    }
}

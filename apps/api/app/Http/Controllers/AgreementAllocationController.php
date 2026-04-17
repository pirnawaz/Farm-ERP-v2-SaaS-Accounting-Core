<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use App\Models\AgreementAllocation;
use App\Models\LandParcel;
use App\Services\AgreementAllocationCapacityService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgreementAllocationController extends Controller
{
    public function __construct(
        private AgreementAllocationCapacityService $capacityService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $q = AgreementAllocation::query()->where('tenant_id', $tenantId)->with([
            'agreement:id,agreement_type,party_id,status,effective_from,effective_to',
            'landParcel:id,name,total_acres',
            'legacyField:id,name,area',
        ]);

        if ($request->filled('land_parcel_id')) {
            $q->where('land_parcel_id', $request->string('land_parcel_id'));
        }
        if ($request->filled('agreement_id')) {
            $q->where('agreement_id', $request->string('agreement_id'));
        }

        return response()->json($q->orderBy('starts_on', 'desc')->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $data = $this->validatedPayload($request, $tenantId);

        $this->capacityService->assertWithinParcelCapacity(
            $tenantId,
            $data['land_parcel_id'],
            (string) $data['allocated_area'],
            $data['starts_on'],
            $data['ends_on'] ?? null,
            $data['status']
        );

        $allocation = AgreementAllocation::create(array_merge($data, ['tenant_id' => $tenantId]));

        return response()->json($allocation->load(['agreement', 'landParcel', 'legacyField']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $row = AgreementAllocation::where('tenant_id', $tenantId)->where('id', $id)->with([
            'agreement', 'landParcel', 'legacyField',
        ])->firstOrFail();

        return response()->json($row);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $existing = AgreementAllocation::where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();
        $data = $this->validatedPayload($request, $tenantId);

        $this->capacityService->assertWithinParcelCapacity(
            $tenantId,
            $data['land_parcel_id'],
            (string) $data['allocated_area'],
            $data['starts_on'],
            $data['ends_on'] ?? null,
            $data['status'],
            $existing->id
        );

        $existing->update($data);

        return response()->json($existing->fresh()->load(['agreement', 'landParcel', 'legacyField']));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, string $tenantId): array
    {
        $exists = fn (string $table, ?string $id = null) => Rule::exists($table, 'id')->where('tenant_id', $tenantId);

        $v = $request->validate([
            'agreement_id' => ['required', 'uuid', $exists('agreements')],
            'land_parcel_id' => ['required', 'uuid', $exists('land_parcels')],
            'allocated_area' => ['required', 'numeric', 'gt:0'],
            'area_uom' => ['nullable', 'string', 'max:32'],
            'starts_on' => ['required', 'date', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'status' => ['required', 'string', Rule::in([
                AgreementAllocation::STATUS_ACTIVE,
                AgreementAllocation::STATUS_ENDED,
                AgreementAllocation::STATUS_CANCELLED,
            ])],
            'label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'legacy_field_id' => ['nullable', 'uuid', $exists('field_blocks')],
        ]);

        Agreement::where('tenant_id', $tenantId)->where('id', $v['agreement_id'])->firstOrFail();
        LandParcel::where('tenant_id', $tenantId)->where('id', $v['land_parcel_id'])->firstOrFail();

        if (! empty($v['legacy_field_id'])) {
            \App\Models\FieldBlock::where('tenant_id', $tenantId)
                ->where('id', $v['legacy_field_id'])
                ->where('land_parcel_id', $v['land_parcel_id'])
                ->firstOrFail();
        }

        $v['area_uom'] = $v['area_uom'] ?? 'ACRE';

        return $v;
    }
}

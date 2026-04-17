<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use App\Models\Project;
use App\Services\AgreementSettlementTermsValidator;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AgreementController extends Controller
{
    public function __construct(
        private AgreementSettlementTermsValidator $settlementTermsValidator
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $q = Agreement::query()->where('tenant_id', $tenantId)->with([
            'project:id,name',
            'cropCycle:id,name',
            'party:id,name',
            'machine:id,code,name',
            'worker:id,name',
        ]);

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        if ($request->filled('agreement_type')) {
            $q->where('agreement_type', $request->string('agreement_type'));
        }

        return response()->json($q->orderBy('priority', 'desc')->orderBy('effective_from', 'desc')->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $data = $this->validatedPayload($request, $tenantId);
        $this->validateProjectScopedLandLeaseSettlementTerms($request, $data, null);
        $agreement = Agreement::create(array_merge($data, ['tenant_id' => $tenantId]));

        return response()->json($agreement->load(['project', 'cropCycle', 'party', 'machine', 'worker']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $a = Agreement::where('tenant_id', $tenantId)->where('id', $id)->with([
            'project', 'cropCycle', 'party', 'machine', 'worker', 'agreementAllocations.landParcel',
        ])->firstOrFail();

        return response()->json($a);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $a = Agreement::where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();
        $data = $this->validatedPayload($request, $tenantId);
        $this->validateProjectScopedLandLeaseSettlementTerms($request, $data, $a);
        $a->update($data);

        return response()->json($a->fresh()->load(['project', 'cropCycle', 'party', 'machine', 'worker']));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, string $tenantId): array
    {
        $exists = fn (string $table, ?string $id = null) => Rule::exists($table, 'id')->where('tenant_id', $tenantId);

        $v = Validator::make($request->all(), [
            'agreement_type' => ['required', 'string', Rule::in([
                Agreement::TYPE_MACHINE_USAGE,
                Agreement::TYPE_LABOUR,
                Agreement::TYPE_LAND_LEASE,
            ])],
            'project_id' => ['nullable', 'uuid', $exists('projects')],
            'crop_cycle_id' => ['nullable', 'uuid', $exists('crop_cycles')],
            'party_id' => ['nullable', 'uuid', $exists('parties')],
            'machine_id' => ['nullable', 'uuid', $exists('machines')],
            'worker_id' => ['nullable', 'uuid', $exists('lab_workers')],
            'terms' => ['nullable', 'array'],
            'effective_from' => ['required', 'date', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'status' => ['nullable', 'string', Rule::in([Agreement::STATUS_ACTIVE, Agreement::STATUS_INACTIVE])],
        ]);

        $data = $v->validate();

        if (($data['agreement_type'] ?? null) === Agreement::TYPE_MACHINE_USAGE && empty($data['machine_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'machine_id' => ['machine_id is required for machine usage agreements.'],
            ]);
        }
        if (($data['agreement_type'] ?? null) === Agreement::TYPE_LABOUR && empty($data['worker_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'worker_id' => ['worker_id is required for labour agreements.'],
            ]);
        }
        if (($data['agreement_type'] ?? null) === Agreement::TYPE_LAND_LEASE && empty($data['party_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'party_id' => ['party_id is required for land lease agreements.'],
            ]);
        }

        if (! empty($data['project_id']) && ! empty($data['crop_cycle_id'])) {
            $p = Project::where('tenant_id', $tenantId)->where('id', $data['project_id'])->first();
            if ($p && $p->crop_cycle_id !== $data['crop_cycle_id']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'crop_cycle_id' => ['Crop cycle must match the selected project.'],
                ]);
            }
        }

        $data['priority'] = (int) ($data['priority'] ?? 0);
        $data['status'] = $data['status'] ?? Agreement::STATUS_ACTIVE;

        return $data;
    }

    /**
     * When a land agreement is project-scoped and active, require canonical settlement terms.
     * Skips validation on partial updates that do not touch type, project, status, or terms (legacy rows keep working).
     */
    private function validateProjectScopedLandLeaseSettlementTerms(Request $request, array $data, ?Agreement $existing): void
    {
        if ($existing !== null && ! $this->agreementSettlementRelevantFieldsChanged($request, $data, $existing)) {
            return;
        }

        $merged = [
            'agreement_type' => $data['agreement_type'] ?? $existing?->agreement_type,
            'project_id' => array_key_exists('project_id', $data) ? $data['project_id'] : $existing?->project_id,
            'status' => $data['status'] ?? $existing?->status ?? Agreement::STATUS_ACTIVE,
            'terms' => array_key_exists('terms', $data) ? $data['terms'] : ($existing ? $existing->terms : null),
        ];

        if ($this->settlementTermsValidator->requiresSettlementTerms($merged)) {
            $this->settlementTermsValidator->assertParseableSettlementTerms(
                is_array($merged['terms'] ?? null) ? $merged['terms'] : null
            );
        }
    }

    /**
     * For updates, only enforce settlement validation when commercial-driving fields actually change,
     * so legacy agreements stay readable/updatable for unrelated fields without mass migration.
     */
    private function agreementSettlementRelevantFieldsChanged(Request $request, array $data, Agreement $existing): bool
    {
        if ($request->has('agreement_type') && ($data['agreement_type'] ?? null) !== $existing->agreement_type) {
            return true;
        }
        if ($request->has('status') && ($data['status'] ?? null) !== $existing->status) {
            return true;
        }
        if ($request->has('project_id')) {
            $next = array_key_exists('project_id', $data) ? $data['project_id'] : null;
            if ((string) ($next ?? '') !== (string) ($existing->project_id ?? '')) {
                return true;
            }
        }
        if ($request->has('terms')) {
            $incoming = $data['terms'] ?? null;
            if (json_encode($incoming) !== json_encode($existing->terms)) {
                return true;
            }
        }

        return false;
    }
}

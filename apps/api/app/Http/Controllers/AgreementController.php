<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use App\Models\Project;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AgreementController extends Controller
{
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
        $agreement = Agreement::create(array_merge($data, ['tenant_id' => $tenantId]));

        return response()->json($agreement->load(['project', 'cropCycle', 'party', 'machine', 'worker']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $a = Agreement::where('tenant_id', $tenantId)->where('id', $id)->with([
            'project', 'cropCycle', 'party', 'machine', 'worker',
        ])->firstOrFail();

        return response()->json($a);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $a = Agreement::where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();
        $data = $this->validatedPayload($request, $tenantId);
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
}

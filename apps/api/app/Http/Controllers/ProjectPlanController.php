<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectPlanRequest;
use App\Models\ProjectPlan;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProjectPlanController extends Controller
{
    /**
     * GET /api/plans/project
     *
     * Query: project_id, crop_cycle_id, status (optional filters; tenant-scoped).
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'project_id' => ['nullable', 'uuid', Rule::exists('projects', 'id')->where('tenant_id', $tenantId)],
            'crop_cycle_id' => ['nullable', 'uuid', Rule::exists('crop_cycles', 'id')->where('tenant_id', $tenantId)],
            'status' => ['nullable', 'string', Rule::in([ProjectPlan::STATUS_DRAFT, ProjectPlan::STATUS_ACTIVE])],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $q = ProjectPlan::query()
            ->where('tenant_id', $tenantId)
            ->with(['costs', 'yields', 'project:id,name', 'cropCycle:id,name'])
            ->orderByDesc('updated_at')
            ->orderBy('id');

        if ($request->filled('project_id')) {
            $q->where('project_id', $request->input('project_id'));
        }
        if ($request->filled('crop_cycle_id')) {
            $q->where('crop_cycle_id', $request->input('crop_cycle_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        return response()->json($q->get()->map(fn (ProjectPlan $p) => $this->serializePlan($p))->values());
    }

    /**
     * POST /api/plans/project
     */
    public function store(StoreProjectPlanRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validated = $request->validated();
        $status = $validated['status'] ?? ProjectPlan::STATUS_DRAFT;

        $plan = DB::transaction(function () use ($tenantId, $validated, $status) {
            $plan = ProjectPlan::create([
                'tenant_id' => $tenantId,
                'project_id' => $validated['project_id'],
                'crop_cycle_id' => $validated['crop_cycle_id'],
                'name' => $validated['name'],
                'status' => $status,
            ]);

            foreach ($validated['costs'] ?? [] as $row) {
                $plan->costs()->create([
                    'cost_type' => $row['cost_type'],
                    'expected_quantity' => $row['expected_quantity'] ?? null,
                    'expected_cost' => $row['expected_cost'] ?? null,
                ]);
            }
            foreach ($validated['yields'] ?? [] as $row) {
                $plan->yields()->create([
                    'expected_quantity' => $row['expected_quantity'] ?? null,
                    'expected_unit_value' => $row['expected_unit_value'] ?? null,
                ]);
            }

            return $plan->load(['costs', 'yields', 'project:id,name', 'cropCycle:id,name']);
        });

        return response()->json($this->serializePlan($plan), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlan(ProjectPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'status' => $plan->status,
            'project_id' => $plan->project_id,
            'crop_cycle_id' => $plan->crop_cycle_id,
            'project' => $plan->relationLoaded('project') && $plan->project
                ? ['id' => $plan->project->id, 'name' => $plan->project->name]
                : null,
            'crop_cycle' => $plan->relationLoaded('cropCycle') && $plan->cropCycle
                ? ['id' => $plan->cropCycle->id, 'name' => $plan->cropCycle->name]
                : null,
            'costs' => $plan->costs->map(fn ($c) => [
                'id' => $c->id,
                'cost_type' => $c->cost_type,
                'expected_quantity' => $c->expected_quantity !== null ? (string) $c->expected_quantity : null,
                'expected_cost' => $c->expected_cost !== null ? (string) $c->expected_cost : null,
            ])->values(),
            'yields' => $plan->yields->map(fn ($y) => [
                'id' => $y->id,
                'expected_quantity' => $y->expected_quantity !== null ? (string) $y->expected_quantity : null,
                'expected_unit_value' => $y->expected_unit_value !== null ? (string) $y->expected_unit_value : null,
            ])->values(),
            'created_at' => $plan->created_at?->toIso8601String(),
            'updated_at' => $plan->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Domains\Governance\SettlementPack\SettlementPackService;
use App\Models\Project;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SettlementPackController extends Controller
{
    public function __construct(
        private SettlementPackService $settlementPackService
    ) {}

    /**
     * POST /api/projects/{projectId}/settlement-pack
     * Generate a settlement pack (idempotent per project + register_version). Returns pack + summary.
     */
    public function generate(Request $request, string $projectId): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $project = Project::where('id', $projectId)->where('tenant_id', $tenantId)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'register_version' => 'nullable|string|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $registerVersion = $request->input('register_version', 'default');
        $userId = $request->user()?->id;

        $result = $this->settlementPackService->generateOrReturn(
            $projectId,
            $tenantId,
            $userId,
            $registerVersion
        );

        $pack = $result['pack'];
        $data = [
            'id' => $pack->id,
            'tenant_id' => $pack->tenant_id,
            'project_id' => $pack->project_id,
            'generated_by_user_id' => $pack->generated_by_user_id,
            'generated_at' => $pack->generated_at?->toIso8601String(),
            'status' => $pack->status,
            'summary_json' => $result['summary'],
            'register_version' => $pack->register_version,
            'register_row_count' => $result['register_row_count'],
        ];

        return response()->json($data, 201);
    }

    /**
     * GET /api/settlement-packs/{id}
     * Return pack + full transaction register rows for the project. Tenant-scoped.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $result = $this->settlementPackService->getWithRegister($id, $tenantId);
        $pack = $result['pack'];

        $data = [
            'id' => $pack->id,
            'tenant_id' => $pack->tenant_id,
            'project_id' => $pack->project_id,
            'project' => $pack->project ? [
                'id' => $pack->project->id,
                'name' => $pack->project->name,
            ] : null,
            'generated_by_user_id' => $pack->generated_by_user_id,
            'generated_at' => $pack->generated_at?->toIso8601String(),
            'status' => $pack->status,
            'summary_json' => $result['summary'],
            'register_version' => $pack->register_version,
            'register_rows' => $result['register_rows'],
        ];

        return response()->json($data);
    }
}

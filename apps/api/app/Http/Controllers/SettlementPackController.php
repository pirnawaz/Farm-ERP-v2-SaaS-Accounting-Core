<?php

namespace App\Http\Controllers;

use App\Domains\Governance\SettlementPack\SettlementPackExportService;
use App\Domains\Governance\SettlementPack\SettlementPackService;
use App\Models\Project;
use App\Models\SettlementPack;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SettlementPackController extends Controller
{
    public function __construct(
        private SettlementPackService $settlementPackService,
        private SettlementPackExportService $exportService
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
            'finalized_at' => $pack->finalized_at?->toIso8601String(),
            'finalized_by_user_id' => $pack->finalized_by_user_id,
            'summary_json' => $result['summary'],
            'register_version' => $pack->register_version,
            'register_row_count' => $result['register_row_count'],
            'approvals' => [],
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
        $pack->load('approvals');
        $data = $this->packToArray($pack, $result['summary']);
        $data['register_rows'] = $result['register_rows'];
        return response()->json($data);
    }

    /**
     * Build pack response with approvals when present.
     */
    private function packToArray(SettlementPack $pack, array $summary, ?array $registerRows = null): array
    {
        $approvals = $pack->relationLoaded('approvals')
            ? $pack->approvals
            : $pack->approvals()->orderBy('approver_role')->get();
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
            'finalized_at' => $pack->finalized_at?->toIso8601String(),
            'finalized_by_user_id' => $pack->finalized_by_user_id,
            'summary_json' => $summary,
            'register_version' => $pack->register_version,
            'approvals' => $approvals->map(fn ($a) => [
                'approver_user_id' => $a->approver_user_id,
                'approver_role' => $a->approver_role,
                'status' => $a->status,
                'approved_at' => $a->approved_at?->toIso8601String(),
                'rejected_at' => $a->rejected_at?->toIso8601String(),
            ])->values()->all(),
        ];
        if ($registerRows !== null) {
            $data['register_rows'] = $registerRows;
        }
        return $data;
    }

    /**
     * POST /api/settlement-packs/{id}/finalize
     * Finalize pack (DRAFT → FINAL) and close the project. Tenant-scoped.
     */
    public function finalize(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $userId = $request->user()?->id;

        try {
            $result = $this->settlementPackService->finalize($id, $tenantId, $userId);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $pack = $result['pack'];
        $pack->load('approvals');
        $data = $this->packToArray($pack, $pack->summary_json ?? []);
        return response()->json($data);
    }

    /**
     * POST /api/settlement-packs/{id}/submit-for-approval
     * DRAFT → PENDING_APPROVAL; creates approval rows for required roles.
     */
    public function submitForApproval(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }
        $userId = $request->user()?->id;
        try {
            $result = $this->settlementPackService->submitForApproval($id, $tenantId, $userId);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
        $pack = $result['pack'];
        $data = $this->packToArray($pack, $pack->summary_json ?? []);
        $data['approvals'] = $result['approvals'];
        return response()->json($data);
    }

    /**
     * POST /api/settlement-packs/{id}/approve
     * Record approval; if all required approved, pack → FINAL and project → CLOSED.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }
        $userId = $request->user()?->id ?? $request->input('approver_user_id');
        if (!$userId) {
            return response()->json(['error' => 'Authenticated user or approver_user_id required to approve.'], 400);
        }
        $comment = $request->input('comment');
        try {
            $result = $this->settlementPackService->approve($id, $tenantId, $userId, $comment);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
        $pack = $result['pack'];
        $data = $this->packToArray($pack, $pack->summary_json ?? []);
        $data['approvals'] = $result['approvals'];
        return response()->json(array_merge($data, [
            'id' => $pack->id,
            'status' => $pack->status,
            'finalized_at' => $pack->finalized_at?->toIso8601String(),
        ]));
    }

    /**
     * POST /api/settlement-packs/{id}/reject
     * Record rejection; pack remains PENDING_APPROVAL.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }
        $userId = $request->user()?->id ?? $request->input('approver_user_id');
        if (!$userId) {
            return response()->json(['error' => 'Authenticated user or approver_user_id required to reject.'], 400);
        }
        $comment = $request->input('comment');
        try {
            $result = $this->settlementPackService->reject($id, $tenantId, $userId, $comment);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
        $pack = $result['pack'];
        $data = $this->packToArray($pack, $pack->summary_json ?? []);
        $data['approvals'] = $result['approvals'];
        return response()->json($data);
    }

    /**
     * POST /api/settlement-packs/{id}/export/pdf
     * Generate a versioned PDF bundle from the pack snapshot (FINAL packs only).
     */
    public function exportPdf(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $userId = $request->user()?->id;

        try {
            $doc = $this->exportService->generatePdfBundle($tenantId, $id, $userId);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        return response()->json([
            'pack_id' => $doc->settlement_pack_id,
            'version' => $doc->version,
            'sha256_hex' => $doc->sha256_hex,
            'generated_at' => $doc->generated_at?->toIso8601String(),
        ], 201);
    }

    /**
     * GET /api/settlement-packs/{id}/documents
     * List exported document versions for the pack.
     */
    public function listDocuments(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $pack = SettlementPack::where('id', $id)->where('tenant_id', $tenantId)->first();
        if (!$pack) {
            return response()->json(['error' => 'Settlement pack not found.'], 404);
        }

        $list = $this->exportService->listDocuments($tenantId, $id);
        return response()->json(['documents' => $list]);
    }

    /**
     * GET /api/settlement-packs/{id}/documents/{version}
     * Stream the PDF or return metadata. Accept: application/json for metadata only.
     */
    public function getDocument(Request $request, string $id, int $version): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $pack = SettlementPack::where('id', $id)->where('tenant_id', $tenantId)->first();
        if (!$pack) {
            return response()->json(['error' => 'Settlement pack not found.'], 404);
        }

        $doc = $this->exportService->getDocument($tenantId, $id, $version);

        if ($request->wantsJson() || $request->header('Accept') === 'application/json') {
            return response()->json([
                'version' => $doc->version,
                'sha256_hex' => $doc->sha256_hex,
                'file_size_bytes' => $doc->file_size_bytes,
                'generated_at' => $doc->generated_at?->toIso8601String(),
                'content_type' => $doc->content_type,
            ]);
        }

        $path = $doc->storage_key;
        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return response()->streamDownload(
            function () use ($path) {
                $stream = Storage::disk('local')->readStream($path);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            sprintf('settlement-pack-%s-v%d.pdf', substr($id, 0, 8), $doc->version),
            ['Content-Type' => $doc->content_type],
            'inline'
        );
    }
}

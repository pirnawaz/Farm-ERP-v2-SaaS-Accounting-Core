<?php

namespace App\Http\Controllers;

use App\Domains\Governance\SettlementPack\SettlementPackExportService;
use App\Domains\Governance\SettlementPack\SettlementPackService;
use App\Models\Project;
use App\Models\SettlementPack;
use App\Models\SettlementPackDocument;
use App\Services\TenantContext;
use App\Support\TenantScoped;
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
     * GET /api/settlement-packs
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $status = $request->query('status');
        $status = is_string($status) ? $status : null;
        $rows = $this->settlementPackService->listForTenant($tenantId, $status);

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/settlement-packs
     * Create (or return existing) pack for project_id + reference_no — same semantics as POST /projects/{id}/settlement-pack.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $validator = Validator::make($request->all(), [
            'project_id' => 'required|uuid',
            'reference_no' => 'nullable|string|max:64',
            'register_version' => 'nullable|string|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $projectId = $request->input('project_id');
        TenantScoped::for(Project::query(), $tenantId)->findOrFail($projectId);

        $referenceNo = $request->input('reference_no')
            ?? $request->input('register_version', 'default');
        $userId = $request->user()?->id;

        $result = $this->settlementPackService->generateOrReturn(
            $projectId,
            $tenantId,
            $userId,
            $referenceNo
        );

        $pack = $result['pack'];
        $data = [
            'id' => $pack->id,
            'tenant_id' => $pack->tenant_id,
            'project_id' => $pack->project_id,
            'crop_cycle_id' => $pack->crop_cycle_id,
            'reference_no' => $pack->reference_no,
            'prepared_by_user_id' => $pack->prepared_by_user_id,
            'prepared_at' => $pack->prepared_at?->toIso8601String(),
            'generated_by_user_id' => $pack->prepared_by_user_id,
            'generated_at' => $pack->prepared_at?->toIso8601String(),
            'status' => $pack->status,
            'finalized_at' => $pack->finalized_at?->toIso8601String(),
            'finalized_by_user_id' => $pack->finalized_by_user_id,
            'as_of_date' => $pack->as_of_date?->format('Y-m-d'),
            'notes' => $pack->notes,
            'summary_json' => $result['summary'],
            'register_version' => $pack->reference_no,
            'register_row_count' => $result['register_row_count'],
            'is_read_only' => $pack->isReadOnly(),
            'approvals' => [],
        ];

        return response()->json($data, 201);
    }

    /**
     * POST /api/projects/{projectId}/settlement-pack
     * Generate a settlement pack (idempotent per project + reference_no). Returns pack + summary.
     */
    public function generate(Request $request, string $projectId): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $project = TenantScoped::for(Project::query(), $tenantId)->findOrFail($projectId);

        $validator = Validator::make($request->all(), [
            'reference_no' => 'nullable|string|max:64',
            'register_version' => 'nullable|string|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $referenceNo = $request->input('reference_no')
            ?? $request->input('register_version', 'default');
        $userId = $request->user()?->id;

        $result = $this->settlementPackService->generateOrReturn(
            $projectId,
            $tenantId,
            $userId,
            $referenceNo
        );

        $pack = $result['pack'];
        $data = [
            'id' => $pack->id,
            'tenant_id' => $pack->tenant_id,
            'project_id' => $pack->project_id,
            'crop_cycle_id' => $pack->crop_cycle_id,
            'reference_no' => $pack->reference_no,
            'prepared_by_user_id' => $pack->prepared_by_user_id,
            'prepared_at' => $pack->prepared_at?->toIso8601String(),
            'generated_by_user_id' => $pack->prepared_by_user_id,
            'generated_at' => $pack->prepared_at?->toIso8601String(),
            'status' => $pack->status,
            'finalized_at' => $pack->finalized_at?->toIso8601String(),
            'finalized_by_user_id' => $pack->finalized_by_user_id,
            'as_of_date' => $pack->as_of_date?->format('Y-m-d'),
            'notes' => $pack->notes,
            'summary_json' => $result['summary'],
            'register_version' => $pack->reference_no,
            'register_row_count' => $result['register_row_count'],
            'is_read_only' => $pack->isReadOnly(),
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
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $result = $this->settlementPackService->getWithRegister($id, $tenantId);
        $pack = $result['pack'];
        $pack->load(['approvals', 'versions']);
        $data = $this->packToArray($pack, $result['summary']);
        $data['register_rows'] = $result['register_rows'];
        $data['versions'] = $pack->versions->sortByDesc('version_no')->values()->map(function ($v) {
            $snap = is_array($v->snapshot_json) ? $v->snapshot_json : [];

            return [
                'version_no' => $v->version_no,
                'generated_at' => $v->generated_at?->toIso8601String(),
                'generated_by_user_id' => $v->generated_by_user_id,
                'content_hash' => $snap['content_hash'] ?? null,
                'has_pdf' => $v->pdf_path !== null,
            ];
        })->all();

        return response()->json($data);
    }

    /**
     * GET /api/settlement-packs/{id}/register
     */
    public function register(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $payload = $this->settlementPackService->getRegisterPayload($id, $tenantId);

        return response()->json($payload);
    }

    /**
     * POST /api/settlement-packs/{id}/generate-version
     */
    public function generateVersion(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $userId = $request->user()?->id
            ?? $request->attributes->get('user_id')
            ?? $request->header('X-User-Id');

        try {
            $out = $this->settlementPackService->generateNextSnapshotVersion($id, $tenantId, $userId);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        return response()->json([
            'settlement_pack_id' => $out['pack']->id,
            'version_no' => $out['version_no'],
            'summary_json' => $out['summary'],
        ], 201);
    }

    /**
     * GET /api/settlement-packs/{id}/pdf
     * Stream latest exported PDF (from settlement_pack_documents), if any.
     */
    public function downloadPdf(Request $request, string $id): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)->find($id);
        if (! $pack) {
            return response()->json(['error' => 'Settlement pack not found.'], 404);
        }

        $doc = SettlementPackDocument::where('tenant_id', $tenantId)
            ->where('settlement_pack_id', $id)
            ->orderByDesc('version')
            ->first();

        if (! $doc) {
            return response()->json([
                'error' => 'No PDF has been generated yet. Use POST /api/settlement-packs/{id}/export/pdf first.',
            ], 404);
        }

        $path = $doc->storage_key;
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'PDF file not found on disk.'], 404);
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
            'crop_cycle_id' => $pack->crop_cycle_id,
            'reference_no' => $pack->reference_no,
            'project' => $pack->project ? [
                'id' => $pack->project->id,
                'name' => $pack->project->name,
            ] : null,
            'prepared_by_user_id' => $pack->prepared_by_user_id,
            'prepared_at' => $pack->prepared_at?->toIso8601String(),
            'generated_by_user_id' => $pack->prepared_by_user_id,
            'generated_at' => $pack->prepared_at?->toIso8601String(),
            'status' => $pack->status,
            'finalized_at' => $pack->finalized_at?->toIso8601String(),
            'finalized_by_user_id' => $pack->finalized_by_user_id,
            'as_of_date' => $pack->as_of_date?->format('Y-m-d'),
            'notes' => $pack->notes,
            'summary_json' => $summary,
            'register_version' => $pack->reference_no,
            'is_read_only' => $pack->isReadOnly(),
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
     * Finalize pack (DRAFT → FINALIZED). Requires snapshot version; no ledger or project changes. Tenant-scoped.
     */
    public function finalize(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $userId = $request->user()?->id
            ?? $request->attributes->get('user_id')
            ?? $request->header('X-User-Id');
        if (! $userId) {
            return response()->json(['error' => 'Actor user required to finalize (session, auth attributes, or X-User-Id).'], 400);
        }

        try {
            $result = $this->settlementPackService->finalize($id, $tenantId, (string) $userId);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $pack = $result['pack'];
        $pack->load('approvals');
        $data = $this->packToArray($pack, $pack->snapshotJson());

        return response()->json($data);
    }

    /**
     * POST /api/settlement-packs/{id}/submit-for-approval
     * Creates approval rows for required roles; pack remains DRAFT until all approvals are recorded.
     */
    public function submitForApproval(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }
        $userId = $request->user()?->id;
        try {
            $result = $this->settlementPackService->submitForApproval($id, $tenantId, $userId);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
        $pack = $result['pack'];
        $data = $this->packToArray($pack, $pack->snapshotJson());
        $data['approvals'] = $result['approvals'];

        return response()->json($data);
    }

    /**
     * POST /api/settlement-packs/{id}/approve
     * Record approval; if all required approved, pack → FINALIZED and project → CLOSED.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }
        $userId = $request->user()?->id ?? $request->input('approver_user_id');
        if (! $userId) {
            return response()->json(['error' => 'Authenticated user or approver_user_id required to approve.'], 400);
        }
        $comment = $request->input('comment');
        try {
            $result = $this->settlementPackService->approve($id, $tenantId, $userId, $comment);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
        $pack = $result['pack'];
        $data = $this->packToArray($pack, $pack->snapshotJson());
        $data['approvals'] = $result['approvals'];

        return response()->json(array_merge($data, [
            'id' => $pack->id,
            'status' => $pack->status,
            'finalized_at' => $pack->finalized_at?->toIso8601String(),
        ]));
    }

    /**
     * POST /api/settlement-packs/{id}/reject
     * Record rejection; pack remains DRAFT.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }
        $userId = $request->user()?->id ?? $request->input('approver_user_id');
        if (! $userId) {
            return response()->json(['error' => 'Authenticated user or approver_user_id required to reject.'], 400);
        }
        $comment = $request->input('comment');
        try {
            $result = $this->settlementPackService->reject($id, $tenantId, $userId, $comment);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
        $pack = $result['pack'];
        $data = $this->packToArray($pack, $pack->snapshotJson());
        $data['approvals'] = $result['approvals'];

        return response()->json($data);
    }

    /**
     * POST /api/settlement-packs/{id}/export/pdf
     * Generate a versioned PDF bundle from the pack snapshot (FINALIZED packs only).
     */
    public function exportPdf(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
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
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)->find($id);
        if (! $pack) {
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
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)->find($id);
        if (! $pack) {
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
        if (! Storage::disk('local')->exists($path)) {
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

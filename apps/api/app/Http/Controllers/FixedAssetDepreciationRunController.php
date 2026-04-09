<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\FixedAssets\FixedAssetDepreciationPostingService;
use App\Domains\Accounting\FixedAssets\FixedAssetDepreciationRun;
use App\Domains\Accounting\FixedAssets\FixedAssetDepreciationRunGeneratorService;
use App\Http\Requests\PostFixedAssetDepreciationRunRequest;
use App\Http\Requests\StoreFixedAssetDepreciationRunRequest;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetDepreciationRunController extends Controller
{
    public function __construct(
        private FixedAssetDepreciationRunGeneratorService $generatorService,
        private FixedAssetDepreciationPostingService $depreciationPostingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = TenantScoped::for(FixedAssetDepreciationRun::query(), $tenantId)
            ->withCount('lines')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->get());
    }

    public function store(StoreFixedAssetDepreciationRunRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $run = $this->generatorService->generate(
            $tenantId,
            $request->input('period_start'),
            $request->input('period_end')
        );

        return response()->json($run, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $run = TenantScoped::for(FixedAssetDepreciationRun::query(), $tenantId)
            ->with(['lines.fixedAsset', 'postingGroup'])
            ->findOrFail($id);

        return response()->json($run);
    }

    public function post(PostFixedAssetDepreciationRunRequest $request, string $id): JsonResponse
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $userId = $request->attributes->get('user_id');

        $postingGroup = $this->depreciationPostingService->post(
            $id,
            $tenantId,
            $request->input('posting_date'),
            $request->input('idempotency_key'),
            $userId ?: null
        );

        return response()->json($postingGroup, 201);
    }
}

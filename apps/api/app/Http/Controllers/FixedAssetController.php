<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\FixedAssets\FixedAsset;
use App\Domains\Accounting\FixedAssets\FixedAssetActivationPostingService;
use App\Http\Requests\ActivateFixedAssetRequest;
use App\Http\Requests\StoreFixedAssetRequest;
use App\Models\Project;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetController extends Controller
{
    public function __construct(
        private FixedAssetActivationPostingService $activationPostingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = TenantScoped::for(FixedAsset::query(), $tenantId)
            ->with(['project', 'activationPostingGroup', 'books'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->get());
    }

    public function store(StoreFixedAssetRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $userId = $request->attributes->get('user_id');

        if ($request->filled('project_id')) {
            TenantScoped::for(Project::query(), $tenantId)->findOrFail($request->input('project_id'));
        }

        $asset = FixedAsset::create([
            'tenant_id' => $tenantId,
            'project_id' => $request->input('project_id'),
            'asset_code' => $request->input('asset_code'),
            'name' => $request->input('name'),
            'category' => $request->input('category'),
            'acquisition_date' => $request->input('acquisition_date'),
            'in_service_date' => $request->input('in_service_date'),
            'status' => FixedAsset::STATUS_DRAFT,
            'currency_code' => strtoupper($request->input('currency_code')),
            'acquisition_cost' => $request->input('acquisition_cost'),
            'residual_value' => $request->input('residual_value', 0),
            'useful_life_months' => $request->input('useful_life_months'),
            'depreciation_method' => $request->input('depreciation_method'),
            'notes' => $request->input('notes'),
            'created_by' => $userId ?: null,
        ]);

        return response()->json($asset->load(['project']), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $asset = TenantScoped::for(FixedAsset::query(), $tenantId)
            ->with(['project', 'activationPostingGroup', 'books', 'createdBy', 'activatedByUser', 'disposals.postingGroup'])
            ->findOrFail($id);

        return response()->json($asset);
    }

    public function activate(ActivateFixedAssetRequest $request, string $id): JsonResponse
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $userId = $request->attributes->get('user_id');

        $postingGroup = $this->activationPostingService->activate(
            $id,
            $tenantId,
            $request->input('posting_date'),
            $request->input('source_account'),
            $request->input('idempotency_key'),
            $userId ?: null
        );

        return response()->json($postingGroup, 201);
    }
}

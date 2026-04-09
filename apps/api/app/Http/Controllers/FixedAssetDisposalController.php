<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\FixedAssets\FixedAsset;
use App\Domains\Accounting\FixedAssets\FixedAssetDisposal;
use App\Domains\Accounting\FixedAssets\FixedAssetDisposalPostingService;
use App\Http\Requests\PostFixedAssetDisposalRequest;
use App\Http\Requests\StoreFixedAssetDisposalRequest;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FixedAssetDisposalController extends Controller
{
    public function __construct(
        private FixedAssetDisposalPostingService $disposalPostingService
    ) {}

    public function store(StoreFixedAssetDisposalRequest $request, string $fixed_asset_id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $asset = TenantScoped::for(FixedAsset::query(), $tenantId)->findOrFail($fixed_asset_id);

        if ($asset->status !== FixedAsset::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'fixed_asset_id' => ['Only ACTIVE assets can have a disposal draft created.'],
            ]);
        }

        if (FixedAssetDisposal::query()
            ->where('tenant_id', $tenantId)
            ->where('fixed_asset_id', $asset->id)
            ->where('status', FixedAssetDisposal::STATUS_DRAFT)
            ->exists()) {
            throw ValidationException::withMessages([
                'fixed_asset_id' => ['A draft disposal already exists for this asset. Post it before creating another.'],
            ]);
        }

        if (FixedAssetDisposal::query()
            ->where('tenant_id', $tenantId)
            ->where('fixed_asset_id', $asset->id)
            ->where('status', FixedAssetDisposal::STATUS_POSTED)
            ->exists()) {
            throw ValidationException::withMessages([
                'fixed_asset_id' => ['This asset has already been disposed.'],
            ]);
        }

        $disposal = FixedAssetDisposal::create([
            'tenant_id' => $tenantId,
            'fixed_asset_id' => $asset->id,
            'disposal_date' => $request->input('disposal_date'),
            'proceeds_amount' => $request->input('proceeds_amount'),
            'proceeds_account' => $request->input('proceeds_account'),
            'status' => FixedAssetDisposal::STATUS_DRAFT,
            'notes' => $request->input('notes'),
        ]);

        return response()->json($disposal->load('fixedAsset'), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $disposal = TenantScoped::for(FixedAssetDisposal::query(), $tenantId)
            ->with(['fixedAsset', 'postingGroup'])
            ->findOrFail($id);

        return response()->json($disposal);
    }

    public function post(PostFixedAssetDisposalRequest $request, string $id): JsonResponse
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $userId = $request->attributes->get('user_id');

        $postingGroup = $this->disposalPostingService->post(
            $id,
            $tenantId,
            $request->input('posting_date'),
            $request->input('idempotency_key'),
            $userId ?: null
        );

        return response()->json($postingGroup, 201);
    }
}

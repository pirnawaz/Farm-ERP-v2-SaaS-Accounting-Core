<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\MultiCurrency\FxRevaluationPostingService;
use App\Domains\Accounting\MultiCurrency\FxRevaluationRun;
use App\Domains\Accounting\MultiCurrency\FxRevaluationRunGeneratorService;
use App\Http\Requests\PostFxRevaluationRunRequest;
use App\Http\Requests\StoreFxRevaluationRunRequest;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FxRevaluationRunController extends Controller
{
    public function __construct(
        private FxRevaluationRunGeneratorService $generatorService,
        private FxRevaluationPostingService $postingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = TenantScoped::for(FxRevaluationRun::query(), $tenantId)
            ->withCount('lines')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->get());
    }

    public function store(StoreFxRevaluationRunRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $run = $this->generatorService->generate($tenantId, $request->input('as_of_date'));

        return response()->json($run, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $run = TenantScoped::for(FxRevaluationRun::query(), $tenantId)
            ->with(['lines', 'postingGroup'])
            ->findOrFail($id);

        return response()->json($run);
    }

    public function refresh(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $run = $this->generatorService->refreshDraftLines($id, $tenantId);

        return response()->json($run);
    }

    public function post(PostFxRevaluationRunRequest $request, string $id): JsonResponse
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $userId = $request->attributes->get('user_id');

        $postingGroup = $this->postingService->post(
            $id,
            $tenantId,
            $request->input('posting_date'),
            $request->input('idempotency_key'),
            $userId ?: null
        );

        return response()->json($postingGroup, 201);
    }
}

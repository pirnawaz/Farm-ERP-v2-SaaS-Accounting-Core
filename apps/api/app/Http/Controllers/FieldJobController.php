<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddFieldJobInputRequest;
use App\Http\Requests\AddFieldJobLabourRequest;
use App\Http\Requests\AddFieldJobMachineRequest;
use App\Http\Requests\StoreFieldJobRequest;
use App\Http\Requests\UpdateFieldJobInputRequest;
use App\Http\Requests\UpdateFieldJobLabourRequest;
use App\Http\Requests\UpdateFieldJobMachineRequest;
use App\Http\Requests\PostFieldJobRequest;
use App\Http\Requests\ReverseFieldJobRequest;
use App\Http\Requests\UpdateFieldJobRequest;
use App\Services\FieldJobPostingService;
use App\Services\FieldJobService;
use App\Services\OperationalTraceabilityService;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class FieldJobController extends Controller
{
    public function __construct(
        private FieldJobService $fieldJobService,
        private FieldJobPostingService $fieldJobPostingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->index($request, $tenantId)
        );
    }

    public function store(StoreFieldJobRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $job = $this->fieldJobService->create(
            $tenantId,
            $request->validated(),
            $request->header('X-User-Id')
        );

        return response()->json($job->load($this->fieldJobService->documentWith()), 201);
    }

    public function show(Request $request, string $id, OperationalTraceabilityService $traceability)
    {
        $tenantId = TenantContext::getTenantId($request);
        $job = $this->fieldJobService->show($id, $tenantId);

        return response()->json(array_merge($job->toArray(), [
            'traceability' => $traceability->summarizeForFieldJob($job),
        ]));
    }

    public function update(UpdateFieldJobRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->updateDraft($id, $tenantId, $request->validated())
        );
    }

    public function storeInput(AddFieldJobInputRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->addInput($id, $tenantId, $request->validated()),
            201
        );
    }

    public function updateInput(UpdateFieldJobInputRequest $request, string $id, string $lineId)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->updateInput($id, $lineId, $tenantId, $request->validated())
        );
    }

    public function destroyInput(Request $request, string $id, string $lineId)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->deleteInput($id, $lineId, $tenantId)
        );
    }

    public function storeLabour(AddFieldJobLabourRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->addLabour($id, $tenantId, $request->validated()),
            201
        );
    }

    public function updateLabour(UpdateFieldJobLabourRequest $request, string $id, string $lineId)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->updateLabour($id, $lineId, $tenantId, $request->validated())
        );
    }

    public function destroyLabour(Request $request, string $id, string $lineId)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->deleteLabour($id, $lineId, $tenantId)
        );
    }

    public function storeMachine(AddFieldJobMachineRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->addMachine($id, $tenantId, $request->validated()),
            201
        );
    }

    public function updateMachine(UpdateFieldJobMachineRequest $request, string $id, string $lineId)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->updateMachine($id, $lineId, $tenantId, $request->validated())
        );
    }

    public function destroyMachine(Request $request, string $id, string $lineId)
    {
        $tenantId = TenantContext::getTenantId($request);

        return response()->json(
            $this->fieldJobService->deleteMachine($id, $lineId, $tenantId)
        );
    }

    public function post(PostFieldJobRequest $request, string $id)
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->fieldJobPostingService->postFieldJob(
            $id,
            $tenantId,
            $request->posting_date,
            $request->idempotency_key
        );

        return response()->json($pg, 201);
    }

    public function reverse(ReverseFieldJobRequest $request, string $id)
    {
        $this->authorizeReversal($request);

        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->fieldJobPostingService->reverseFieldJob(
            $id,
            $tenantId,
            $request->posting_date,
            $request->reason ?? ''
        );

        return response()->json($pg, 201);
    }
}

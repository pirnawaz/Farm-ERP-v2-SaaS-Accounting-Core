<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseAccountingPeriodRequest;
use App\Http\Requests\ReopenAccountingPeriodRequest;
use App\Http\Requests\StoreAccountingPeriodRequest;
use App\Services\AccountingPeriodService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountingPeriodController extends Controller
{
    public function __construct(
        private AccountingPeriodService $periodService
    ) {}

    /**
     * GET /api/accounting-periods?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $from = $request->input('from');
        $to = $request->input('to');
        $periods = $this->periodService->listPeriods($tenantId, $from, $to);
        return response()->json($periods);
    }

    /**
     * POST /api/accounting-periods
     */
    public function store(StoreAccountingPeriodRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $actorId = $request->user()?->id;
        try {
            $period = $this->periodService->createPeriod(
                $tenantId,
                $request->input('period_start'),
                $request->input('period_end'),
                $request->input('name'),
                $actorId
            );
            return response()->json($period->load('events'), 201);
        } catch (\Throwable $e) {
            $code = $e instanceof HttpException ? $e->getStatusCode() : (($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 422);
            return response()->json(['message' => $e->getMessage()], $code);
        }
    }

    /**
     * POST /api/accounting-periods/{id}/close
     */
    public function close(CloseAccountingPeriodRequest $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $closedBy = $request->user()?->id;
        try {
            $period = $this->periodService->closePeriod($id, $tenantId, $request->input('notes'), $closedBy);
            return response()->json($period);
        } catch (\Throwable $e) {
            $code = $e instanceof HttpException ? $e->getStatusCode() : (($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 422);
            return response()->json(['message' => $e->getMessage()], $code);
        }
    }

    /**
     * POST /api/accounting-periods/{id}/reopen
     */
    public function reopen(ReopenAccountingPeriodRequest $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $reopenedBy = $request->user()?->id;
        try {
            $period = $this->periodService->reopenPeriod($id, $tenantId, $request->input('notes'), $reopenedBy);
            return response()->json($period);
        } catch (\Throwable $e) {
            $code = $e instanceof HttpException ? $e->getStatusCode() : (($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 422);
            return response()->json(['message' => $e->getMessage()], $code);
        }
    }

    /**
     * GET /api/accounting-periods/{id}/events
     */
    public function events(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $events = $this->periodService->listEvents($id, $tenantId);
        return response()->json($events);
    }
}

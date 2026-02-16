<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use App\Services\BankReconciliationService;
use App\Services\BankStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BankReconciliationController extends Controller
{
    public function __construct(
        private BankReconciliationService $bankRecService,
        private BankStatementService $bankStatementService
    ) {}

    /**
     * POST /api/bank-reconciliations
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'account_code' => ['required', 'string', 'in:BANK,CASH'],
            'statement_date' => ['required', 'date', 'date_format:Y-m-d'],
            'statement_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $rec = $this->bankRecService->create(
                $tenantId,
                $request->input('account_code'),
                $request->input('statement_date'),
                (string) $request->input('statement_balance'),
                $request->input('notes'),
                $request->user()?->id
            );
            return response()->json($rec->load('account:id,code,name'), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/bank-reconciliations?account_code=BANK&limit=50
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $accountCode = $request->input('account_code');
        $limit = (int) $request->input('limit', 50);
        $limit = min(max($limit, 1), 100);

        $list = $this->bankRecService->list($tenantId, $accountCode, $limit);
        return response()->json($list);
    }

    /**
     * GET /api/bank-reconciliations/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        try {
            $report = $this->bankRecService->getReport($tenantId, $id);
            return response()->json($report);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Reconciliation not found.'], 404);
        }
    }

    /**
     * POST /api/bank-reconciliations/{id}/clear
     */
    public function clear(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'ledger_entry_ids' => ['required', 'array'],
            'ledger_entry_ids.*' => ['uuid', 'exists:ledger_entries,id'],
            'cleared_date' => ['nullable', 'date', 'date_format:Y-m-d'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $ledgerEntryIds = array_values($request->input('ledger_entry_ids'));
        if (empty($ledgerEntryIds)) {
            return response()->json(['message' => 'ledger_entry_ids cannot be empty.'], 422);
        }

        try {
            $result = $this->bankRecService->clear(
                $tenantId,
                $id,
                $ledgerEntryIds,
                $request->input('cleared_date'),
                $request->user()?->id
            );
            return response()->json($result, 201);
        } catch (\InvalidArgumentException $e) {
            $code = str_contains($e->getMessage(), 'after statement_date') || str_contains($e->getMessage(), 'reversed')
                ? 409
                : 422;
            return response()->json(['message' => $e->getMessage()], $code);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Reconciliation not found.'], 404);
        }
    }

    /**
     * POST /api/bank-reconciliations/{id}/unclear
     */
    public function unclear(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'ledger_entry_ids' => ['required', 'array'],
            'ledger_entry_ids.*' => ['uuid', 'exists:ledger_entries,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $ledgerEntryIds = array_values($request->input('ledger_entry_ids'));
        if (empty($ledgerEntryIds)) {
            return response()->json(['message' => 'ledger_entry_ids cannot be empty.'], 422);
        }

        try {
            $result = $this->bankRecService->unclear(
                $tenantId,
                $id,
                $ledgerEntryIds,
                $request->input('reason'),
                $request->user()?->id
            );
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Reconciliation not found.'], 404);
        }
    }

    /**
     * POST /api/bank-reconciliations/{id}/finalize
     */
    public function finalize(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        try {
            $rec = $this->bankRecService->finalize($tenantId, $id, $request->user()?->id);
            return response()->json($rec->load('account:id,code,name'), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Reconciliation not found.'], 404);
        }
    }

    /**
     * POST /api/bank-reconciliations/{id}/statement-lines
     */
    public function addStatementLine(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'line_date' => ['required', 'date', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric'],
            'description' => ['nullable', 'string', 'max:65535'],
            'reference' => ['nullable', 'string', 'max:65535'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $line = $this->bankStatementService->addStatementLine(
                $tenantId,
                $id,
                $request->input('line_date'),
                (string) $request->input('amount'),
                $request->input('description'),
                $request->input('reference'),
                $request->user()?->id
            );
            return response()->json($line, 201);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() === 409 ? 409 : 422;
            return response()->json(['message' => $e->getMessage()], $code);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Reconciliation not found.'], 404);
        }
    }

    /**
     * GET /api/bank-reconciliations/{id}/statement-lines
     */
    public function listStatementLines(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $includeVoided = filter_var($request->input('include_voided', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $lines = $this->bankStatementService->listStatementLines($tenantId, $id, $includeVoided);
            return response()->json($lines);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Reconciliation not found.'], 404);
        }
    }

    /**
     * POST /api/bank-reconciliations/{id}/statement-lines/{lineId}/void
     */
    public function voidStatementLine(Request $request, string $id, string $lineId): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $line = $this->bankStatementService->voidStatementLine(
                $tenantId,
                $id,
                $lineId,
                $request->input('reason'),
                $request->user()?->id
            );
            return response()->json($line, 200);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() === 409 ? 409 : 422;
            return response()->json(['message' => $e->getMessage()], $code);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Reconciliation or line not found.'], 404);
        }
    }

    /**
     * POST /api/bank-reconciliations/{id}/statement-lines/{lineId}/match
     */
    public function matchStatementLine(Request $request, string $id, string $lineId): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'ledger_entry_id' => ['required', 'uuid', 'exists:ledger_entries,id'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $match = $this->bankStatementService->matchStatementLine(
                $tenantId,
                $id,
                $lineId,
                $request->input('ledger_entry_id'),
                $request->user()?->id
            );
            return response()->json($match, 201);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() === 409 ? 409 : 422;
            return response()->json(['message' => $e->getMessage()], $code);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Reconciliation or line not found.'], 404);
        }
    }

    /**
     * POST /api/bank-reconciliations/{id}/statement-lines/{lineId}/unmatch
     */
    public function unmatchStatementLine(Request $request, string $id, string $lineId): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $voided = $this->bankStatementService->unmatchStatementLine(
                $tenantId,
                $id,
                $lineId,
                $request->input('reason'),
                $request->user()?->id
            );
            return response()->json(['voided' => $voided], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Reconciliation or line not found.'], 404);
        }
    }
}

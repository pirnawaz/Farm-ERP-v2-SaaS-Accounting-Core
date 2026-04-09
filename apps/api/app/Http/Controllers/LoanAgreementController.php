<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Loans\LoanAgreement;
use App\Domains\Accounting\Loans\LoanAgreementStatementService;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanAgreementController extends Controller
{
    public function __construct(
        private LoanAgreementStatementService $statementService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $rows = TenantScoped::for(LoanAgreement::query(), $tenantId)
            ->with([
                'project:id,name',
                'lenderParty:id,name',
            ])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(fn (LoanAgreement $a) => $this->agreementSummary($a));

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $agreement = TenantScoped::for(LoanAgreement::query(), $tenantId)
            ->with([
                'project:id,name',
                'lenderParty:id,name',
            ])
            ->findOrFail($id);

        return response()->json($this->agreementDetail($agreement));
    }

    public function statement(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $from = $request->query('from');
        $to = $request->query('to');
        $from = is_string($from) && $from !== '' ? $from : null;
        $to = is_string($to) && $to !== '' ? $to : null;

        if ($from) {
            try {
                \Carbon\Carbon::parse($from);
            } catch (\Throwable) {
                return response()->json(['errors' => ['from' => ['Invalid date']]], 422);
            }
        }
        if ($to) {
            try {
                \Carbon\Carbon::parse($to);
            } catch (\Throwable) {
                return response()->json(['errors' => ['to' => ['Invalid date']]], 422);
            }
        }

        $payload = $this->statementService->build($id, $tenantId, $from, $to);

        return response()->json($payload);
    }

    /** @return array<string, mixed> */
    private function agreementSummary(LoanAgreement $a): array
    {
        return [
            'id' => $a->id,
            'reference_no' => $a->reference_no,
            'status' => $a->status,
            'principal_amount' => $a->principal_amount !== null ? (string) $a->principal_amount : null,
            'currency_code' => $a->currency_code,
            'start_date' => $a->start_date?->format('Y-m-d'),
            'maturity_date' => $a->maturity_date?->format('Y-m-d'),
            'project' => $a->project ? [
                'id' => $a->project->id,
                'name' => $a->project->name,
            ] : null,
            'lender_party' => $a->lenderParty ? [
                'id' => $a->lenderParty->id,
                'name' => $a->lenderParty->name,
            ] : null,
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function agreementDetail(LoanAgreement $a): array
    {
        return [
            'id' => $a->id,
            'tenant_id' => $a->tenant_id,
            'project_id' => $a->project_id,
            'lender_party_id' => $a->lender_party_id,
            'reference_no' => $a->reference_no,
            'principal_amount' => $a->principal_amount !== null ? (string) $a->principal_amount : null,
            'currency_code' => $a->currency_code,
            'interest_rate_annual' => $a->interest_rate_annual !== null ? (string) $a->interest_rate_annual : null,
            'start_date' => $a->start_date?->format('Y-m-d'),
            'maturity_date' => $a->maturity_date?->format('Y-m-d'),
            'status' => $a->status,
            'notes' => $a->notes,
            'project' => $a->project ? [
                'id' => $a->project->id,
                'name' => $a->project->name,
            ] : null,
            'lender_party' => $a->lenderParty ? [
                'id' => $a->lenderParty->id,
                'name' => $a->lenderParty->name,
            ] : null,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Http\Requests\StorePartyRequest;
use App\Http\Requests\UpdatePartyRequest;
use App\Services\TenantContext;
use App\Services\SystemPartyService;
use App\Services\PaymentService;
use App\Services\PartyStatementService;
use App\Services\PartyFinancialSourceService;
use App\Models\AllocationRow;
use App\Models\Payment;
use Illuminate\Http\Request;

class PartyController extends Controller
{
    public function __construct(
        private SystemPartyService $partyService,
        private PaymentService $paymentService,
        private PartyStatementService $statementService,
        private PartyFinancialSourceService $financialSourceService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $parties = Party::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return response()->json($parties)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function store(StorePartyRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $party = Party::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'party_types' => $request->party_types,
        ]);

        return response()->json($party, 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $party = Party::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return response()->json($party);
    }

    public function update(UpdatePartyRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $party = Party::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Prevent modifying system landlord party
        if ($this->partyService->isSystemLandlord($party)) {
            return response()->json(['error' => 'Cannot modify system landlord party'], 403);
        }

        $party->update($request->validated());

        return response()->json($party);
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $party = Party::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Prevent deleting system landlord party
        if ($this->partyService->isSystemLandlord($party)) {
            return response()->json(['error' => 'Cannot delete system landlord party'], 403);
        }

        $party->delete();

        return response()->json(null, 204);
    }

    public function balances(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $party = Party::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $asOfDate = $request->input('as_of');

        // Get balance summary (uses shared service internally)
        $balanceSummary = $this->paymentService->getPartyPayableBalance($id, $tenantId, $asOfDate);

        // Get allocations for display (need project info, so query directly but use same filters as shared service)
        $allocationQuery = AllocationRow::where('allocation_rows.tenant_id', $tenantId)
            ->where('allocation_rows.party_id', $id)
            ->whereIn('allocation_rows.allocation_type', ['POOL_SHARE', 'KAMDARI'])
            ->join('posting_groups', 'allocation_rows.posting_group_id', '=', 'posting_groups.id')
            ->where('posting_groups.source_type', 'SETTLEMENT')
            ->join('projects', 'allocation_rows.project_id', '=', 'projects.id')
            ->select(
                'posting_groups.posting_date',
                'allocation_rows.amount',
                'allocation_rows.allocation_type',
                'allocation_rows.project_id',
                'projects.name as project_name'
            );

        if ($asOfDate) {
            $allocationQuery->where('posting_groups.posting_date', '<=', $asOfDate);
        }

        $allocations = $allocationQuery->orderBy('posting_groups.posting_date', 'desc')
            ->get()
            ->map(function ($row) {
                return [
                    'posting_date' => $row->posting_date,
                    'amount' => number_format((float) $row->amount, 2, '.', ''),
                    'allocation_type' => $row->allocation_type,
                    'project_id' => $row->project_id,
                    'project_name' => $row->project_name,
                ];
            });

        // Get payments for display (from shared service)
        $paymentData = $this->financialSourceService->getPostedPaymentsTotals(
            $id,
            $tenantId,
            null, // from: all time
            $asOfDate // to: as of date
        );

        $payments = $paymentData['payments']
            ->where('direction', 'OUT')
            ->sortByDesc('payment_date')
            ->values()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'payment_date' => $payment->payment_date->format('Y-m-d'),
                    'amount' => number_format((float) $payment->amount, 2, '.', ''),
                    'direction' => $payment->direction,
                    'status' => $payment->status,
                ];
            });

        // Get advance balances
        $advanceData = $this->financialSourceService->getPostedAdvancesTotals(
            $id,
            $tenantId,
            null, // from: all time
            $asOfDate // to: as of date
        );

        $advanceBalanceDisbursed = $advanceData['out'];
        $advanceBalanceRepaid = $advanceData['in'];
        $advanceBalanceOutstanding = max(0, $advanceBalanceDisbursed - $advanceBalanceRepaid);

        // Get receivable balances (for buyers)
        $receivableData = $this->financialSourceService->getPostedReceivableTotals(
            $id,
            $tenantId,
            null, // from: all time
            $asOfDate // to: as of date
        );

        $receivableBalance = $receivableData['total'];
        $receivableSalesTotal = $receivableData['sales_total'];
        $receivablePaymentsInTotal = $receivableData['payments_in_total'];

        return response()->json([
            'party' => $party,
            'allocated_payable_total' => $balanceSummary['allocated_total'],
            'paid_total' => $balanceSummary['paid_total'],
            'outstanding_total' => $balanceSummary['outstanding_total'],
            'advance_balance_disbursed' => number_format($advanceBalanceDisbursed, 2, '.', ''),
            'advance_balance_repaid' => number_format($advanceBalanceRepaid, 2, '.', ''),
            'advance_balance_outstanding' => number_format($advanceBalanceOutstanding, 2, '.', ''),
            'receivable_balance' => number_format($receivableBalance, 2, '.', ''),
            'receivable_sales_total' => number_format($receivableSalesTotal, 2, '.', ''),
            'receivable_payments_in_total' => number_format($receivablePaymentsInTotal, 2, '.', ''),
            'allocations' => $allocations,
            'payments' => $payments,
        ]);
    }

    public function statement(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $party = Party::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $from = $request->input('from');
        $to = $request->input('to');
        $groupBy = $request->input('group_by', 'cycle');

        if (!in_array($groupBy, ['cycle', 'project'])) {
            return response()->json(['error' => 'group_by must be "cycle" or "project"'], 422);
        }

        $statement = $this->statementService->getStatement(
            $id,
            $tenantId,
            $from,
            $to,
            $groupBy
        );

        return response()->json($statement);
    }

    public function openSales(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $party = Party::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $asOfDate = $request->input('as_of');

        $arService = app(\App\Services\SaleARService::class);
        $openSales = $arService->getBuyerOpenSales($id, $tenantId, $asOfDate);

        return response()->json($openSales);
    }
}

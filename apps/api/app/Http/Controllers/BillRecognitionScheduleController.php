<?php

namespace App\Http\Controllers;

use App\Models\BillRecognitionSchedule;
use App\Models\BillRecognitionScheduleLine;
use App\Services\BillRecognitionService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BillRecognitionScheduleController extends Controller
{
    public function __construct(
        private BillRecognitionService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $q = BillRecognitionSchedule::query()
            ->where('tenant_id', $tenantId)
            ->with(['supplierInvoice:id,reference_no,total_amount,status', 'lines']);

        if ($request->filled('supplier_invoice_id')) {
            $q->where('supplier_invoice_id', $request->input('supplier_invoice_id'));
        }

        return response()->json($q->orderByDesc('created_at')->limit(100)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'supplier_invoice_id' => 'required|uuid',
            'treatment' => 'required|in:PREPAID,ACCRUAL',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'total_amount' => 'required|numeric|min:0.01',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $schedule = $this->service->createSchedule(
            $tenantId,
            (string) $request->input('supplier_invoice_id'),
            (string) $request->input('treatment'),
            (string) $request->input('start_date'),
            (string) $request->input('end_date'),
            (float) $request->input('total_amount')
        );

        return response()->json($schedule, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $schedule = BillRecognitionSchedule::query()
            ->where('tenant_id', $tenantId)
            ->with(['supplierInvoice', 'lines', 'deferralPostingGroup'])
            ->findOrFail($id);

        $remaining = $schedule->lines()
            ->where('status', BillRecognitionScheduleLine::STATUS_PENDING)
            ->sum('amount');

        return response()->json([
            'schedule' => $schedule,
            'remaining_to_recognize' => (string) round((float) $remaining, 2),
        ]);
    }

    public function postDeferral(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $schedule = BillRecognitionSchedule::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $v = Validator::make($request->all(), [
            'posting_date' => 'required|date',
            'idempotency_key' => 'nullable|string|max:128',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $pg = $this->service->postDeferral(
            $schedule,
            (string) $request->input('posting_date'),
            $request->input('idempotency_key')
        );

        return response()->json($pg, 201);
    }
}

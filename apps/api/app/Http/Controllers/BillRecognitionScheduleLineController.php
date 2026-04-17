<?php

namespace App\Http\Controllers;

use App\Models\BillRecognitionScheduleLine;
use App\Services\BillRecognitionService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BillRecognitionScheduleLineController extends Controller
{
    public function __construct(
        private BillRecognitionService $service
    ) {}

    public function postRecognition(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $line = BillRecognitionScheduleLine::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $v = Validator::make($request->all(), [
            'posting_date' => 'required|date',
            'idempotency_key' => 'nullable|string|max:128',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $pg = $this->service->postRecognitionLine(
            $line,
            (string) $request->input('posting_date'),
            $request->input('idempotency_key')
        );

        return response()->json($pg, 201);
    }
}

<?php

namespace App\Http\Controllers;

use App\Domains\Reporting\SettlementPackPhase1\SettlementPackPhase1RegisterQuery;
use App\Domains\Reporting\SettlementPackPhase1\SettlementPackPhase1Service;
use App\Services\Document\SettlementPackPhase1PdfRenderer;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettlementPackPhase1ReportController extends Controller
{
    public function __construct(
        private SettlementPackPhase1Service $service,
        private SettlementPackPhase1RegisterQuery $registerQuery,
        private SettlementPackPhase1PdfRenderer $pdfRenderer,
    ) {}

    public function project(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'project_id' => 'required|uuid',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
            'include_register' => 'nullable|string|in:none,allocation,ledger,both',
            'allocation_page' => 'nullable|integer|min:1',
            'allocation_per_page' => 'nullable|integer|min:1|max:1000',
            'ledger_page' => 'nullable|integer|min:1',
            'ledger_per_page' => 'nullable|integer|min:1|max:1000',
            'register_order' => 'nullable|string|in:date_asc,date_desc',
            'bucket' => 'nullable|string|in:total,month',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $opts = $this->opts($request);
        $payload = $this->service->buildProject(
            $tenantId,
            (string) $request->query('project_id'),
            (string) $request->query('from'),
            (string) $request->query('to'),
            $opts
        );

        return response()->json($payload);
    }

    public function cropCycle(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'crop_cycle_id' => 'required|uuid',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
            'include_register' => 'nullable|string|in:none,allocation,ledger,both',
            'allocation_page' => 'nullable|integer|min:1',
            'allocation_per_page' => 'nullable|integer|min:1|max:1000',
            'ledger_page' => 'nullable|integer|min:1',
            'ledger_per_page' => 'nullable|integer|min:1|max:1000',
            'register_order' => 'nullable|string|in:date_asc,date_desc',
            'bucket' => 'nullable|string|in:total,month',
            'include_projects_breakdown' => 'nullable|boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $opts = $this->opts($request);
        $opts['include_projects_breakdown'] = $request->boolean('include_projects_breakdown', true);

        $payload = $this->service->buildCropCycle(
            $tenantId,
            (string) $request->query('crop_cycle_id'),
            (string) $request->query('from'),
            (string) $request->query('to'),
            $opts
        );

        return response()->json($payload);
    }

    public function exportProjectSummaryCsv(Request $request): StreamedResponse|JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'project_id' => 'required|uuid',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $payload = $this->service->buildProject($tenantId, (string) $request->query('project_id'), (string) $request->query('from'), (string) $request->query('to'), [
            'include_register' => 'none',
            'allocation_page' => 1,
            'allocation_per_page' => 1,
            'ledger_page' => 1,
            'ledger_per_page' => 1,
            'register_order' => 'date_asc',
            'bucket' => 'total',
        ]);

        $filename = 'settlement-pack-project-summary.csv';

        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['measure', 'value']);
            $t = (array) ($payload['totals'] ?? []);
            $hp = (array) ($t['harvest_production'] ?? []);
            $rev = (array) ($t['ledger_revenue'] ?? []);
            $cost = (array) ($t['costs'] ?? []);
            $adv = (array) ($t['advances'] ?? []);
            $net = (array) ($t['net'] ?? []);

            $pairs = [
                'harvest_production_qty' => $hp['qty'] ?? null,
                'harvest_production_value' => $hp['value'] ?? null,
                'ledger_revenue_sales' => $rev['sales'] ?? '0.00',
                'ledger_revenue_machinery_income' => $rev['machinery_income'] ?? '0.00',
                'ledger_revenue_in_kind_income' => $rev['in_kind_income'] ?? '0.00',
                'ledger_revenue_total' => $rev['total'] ?? '0.00',
                'cost_inputs' => $cost['inputs'] ?? '0.00',
                'cost_labour' => $cost['labour'] ?? '0.00',
                'cost_machinery' => $cost['machinery'] ?? '0.00',
                'cost_other' => $cost['other'] ?? '0.00',
                'cost_credit_premium' => $cost['credit_premium'] ?? '0.00',
                'cost_total' => $cost['total'] ?? '0.00',
                'advances' => $adv['advances'] ?? null,
                'recoveries' => $adv['recoveries'] ?? null,
                'advance_net' => $adv['net'] ?? null,
                'net_ledger_result' => $net['net_ledger_result'] ?? '0.00',
                'net_harvest_production_result' => $net['net_harvest_production_result'] ?? null,
            ];
            foreach ($pairs as $k => $v) {
                fputcsv($out, [$k, $v === null ? '' : (string) $v]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportCropCycleSummaryCsv(Request $request): StreamedResponse|JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'crop_cycle_id' => 'required|uuid',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $payload = $this->service->buildCropCycle($tenantId, (string) $request->query('crop_cycle_id'), (string) $request->query('from'), (string) $request->query('to'), [
            'include_register' => 'none',
            'allocation_page' => 1,
            'allocation_per_page' => 1,
            'ledger_page' => 1,
            'ledger_per_page' => 1,
            'register_order' => 'date_asc',
            'bucket' => 'total',
            'include_projects_breakdown' => false,
        ]);

        $filename = 'settlement-pack-crop-cycle-summary.csv';

        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['measure', 'value']);
            $t = (array) ($payload['totals'] ?? []);
            $hp = (array) ($t['harvest_production'] ?? []);
            $rev = (array) ($t['ledger_revenue'] ?? []);
            $cost = (array) ($t['costs'] ?? []);
            $adv = (array) ($t['advances'] ?? []);
            $net = (array) ($t['net'] ?? []);

            $pairs = [
                'harvest_production_qty' => $hp['qty'] ?? null,
                'harvest_production_value' => $hp['value'] ?? null,
                'ledger_revenue_sales' => $rev['sales'] ?? '0.00',
                'ledger_revenue_machinery_income' => $rev['machinery_income'] ?? '0.00',
                'ledger_revenue_in_kind_income' => $rev['in_kind_income'] ?? '0.00',
                'ledger_revenue_total' => $rev['total'] ?? '0.00',
                'cost_inputs' => $cost['inputs'] ?? '0.00',
                'cost_labour' => $cost['labour'] ?? '0.00',
                'cost_machinery' => $cost['machinery'] ?? '0.00',
                'cost_other' => $cost['other'] ?? '0.00',
                'cost_credit_premium' => $cost['credit_premium'] ?? '0.00',
                'cost_total' => $cost['total'] ?? '0.00',
                'advances' => $adv['advances'] ?? null,
                'recoveries' => $adv['recoveries'] ?? null,
                'advance_net' => $adv['net'] ?? null,
                'net_ledger_result' => $net['net_ledger_result'] ?? '0.00',
                'net_harvest_production_result' => $net['net_harvest_production_result'] ?? null,
            ];
            foreach ($pairs as $k => $v) {
                fputcsv($out, [$k, $v === null ? '' : (string) $v]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportProjectAllocationRegisterCsv(Request $request): StreamedResponse|JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'project_id' => 'required|uuid',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $projectId = (string) $request->query('project_id');
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');
        $filename = 'settlement-pack-project-allocation-register.csv';

        return response()->streamDownload(function () use ($tenantId, $projectId, $from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['posting_date', 'posting_group_id', 'source_type', 'source_id', 'crop_cycle_id', 'project_id', 'allocation_row_id', 'allocation_type', 'allocation_scope', 'party_id', 'amount']);

            $page = 1;
            $per = 1000;
            while (true) {
                $res = $this->registerQuery->allocationRegisterForProject($tenantId, $projectId, $from, $to, 'date_asc', $page, $per);
                $rows = $res['rows'];
                if ($rows === []) {
                    break;
                }
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['posting_date'] ?? '',
                        $r['posting_group_id'] ?? '',
                        $r['source_type'] ?? '',
                        $r['source_id'] ?? '',
                        $r['crop_cycle_id'] ?? '',
                        $r['project_id'] ?? '',
                        $r['allocation_row_id'] ?? '',
                        $r['allocation_type'] ?? '',
                        $r['allocation_scope'] ?? '',
                        $r['party_id'] ?? '',
                        $r['amount'] ?? '',
                    ]);
                }
                $page++;
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportProjectLedgerAuditRegisterCsv(Request $request): StreamedResponse|JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'project_id' => 'required|uuid',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $projectId = (string) $request->query('project_id');
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');
        $filename = 'settlement-pack-project-ledger-audit-register.csv';

        return response()->streamDownload(function () use ($tenantId, $projectId, $from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'posting_date', 'posting_group_id', 'source_type', 'source_id', 'crop_cycle_id', 'project_id',
                'allocation_row_id', 'allocation_type', 'allocation_scope', 'party_id',
                'ledger_entry_id', 'account_code', 'account_name', 'account_type', 'debit_amount', 'credit_amount',
            ]);

            $page = 1;
            $per = 1000;
            while (true) {
                $res = $this->registerQuery->ledgerAuditRegisterForProject($tenantId, $projectId, $from, $to, 'date_asc', $page, $per);
                $rows = $res['rows'];
                if ($rows === []) {
                    break;
                }
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['posting_date'] ?? '',
                        $r['posting_group_id'] ?? '',
                        $r['source_type'] ?? '',
                        $r['source_id'] ?? '',
                        $r['crop_cycle_id'] ?? '',
                        $r['project_id'] ?? '',
                        $r['allocation_row_id'] ?? '',
                        $r['allocation_type'] ?? '',
                        $r['allocation_scope'] ?? '',
                        $r['party_id'] ?? '',
                        $r['ledger_entry_id'] ?? '',
                        $r['account_code'] ?? '',
                        $r['account_name'] ?? '',
                        $r['account_type'] ?? '',
                        $r['debit_amount'] ?? '',
                        $r['credit_amount'] ?? '',
                    ]);
                }
                $page++;
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportCropCycleAllocationRegisterCsv(Request $request): StreamedResponse|JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'crop_cycle_id' => 'required|uuid',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $payload = $this->service->buildCropCycle($tenantId, (string) $request->query('crop_cycle_id'), (string) $request->query('from'), (string) $request->query('to'), [
            'include_register' => 'none',
            'allocation_page' => 1,
            'allocation_per_page' => 1,
            'ledger_page' => 1,
            'ledger_per_page' => 1,
            'register_order' => 'date_asc',
            'bucket' => 'total',
            'include_projects_breakdown' => false,
        ]);

        $cropCycleId = (string) $request->query('crop_cycle_id');
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');
        $projectIds = (array) ($payload['scope']['project_ids'] ?? []);
        $filename = 'settlement-pack-crop-cycle-allocation-register.csv';

        return response()->streamDownload(function () use ($tenantId, $cropCycleId, $projectIds, $from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['posting_date', 'posting_group_id', 'source_type', 'source_id', 'crop_cycle_id', 'project_id', 'allocation_row_id', 'allocation_type', 'allocation_scope', 'party_id', 'amount']);

            $page = 1;
            $per = 1000;
            while (true) {
                $res = $this->registerQuery->allocationRegisterForCropCycle($tenantId, $cropCycleId, $projectIds, $from, $to, 'date_asc', $page, $per);
                $rows = $res['rows'];
                if ($rows === []) {
                    break;
                }
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['posting_date'] ?? '',
                        $r['posting_group_id'] ?? '',
                        $r['source_type'] ?? '',
                        $r['source_id'] ?? '',
                        $r['crop_cycle_id'] ?? '',
                        $r['project_id'] ?? '',
                        $r['allocation_row_id'] ?? '',
                        $r['allocation_type'] ?? '',
                        $r['allocation_scope'] ?? '',
                        $r['party_id'] ?? '',
                        $r['amount'] ?? '',
                    ]);
                }
                $page++;
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportCropCycleLedgerAuditRegisterCsv(Request $request): StreamedResponse|JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'crop_cycle_id' => 'required|uuid',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $payload = $this->service->buildCropCycle($tenantId, (string) $request->query('crop_cycle_id'), (string) $request->query('from'), (string) $request->query('to'), [
            'include_register' => 'none',
            'allocation_page' => 1,
            'allocation_per_page' => 1,
            'ledger_page' => 1,
            'ledger_per_page' => 1,
            'register_order' => 'date_asc',
            'bucket' => 'total',
            'include_projects_breakdown' => false,
        ]);

        $cropCycleId = (string) $request->query('crop_cycle_id');
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');
        $projectIds = (array) ($payload['scope']['project_ids'] ?? []);
        $filename = 'settlement-pack-crop-cycle-ledger-audit-register.csv';

        return response()->streamDownload(function () use ($tenantId, $cropCycleId, $projectIds, $from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'posting_date', 'posting_group_id', 'source_type', 'source_id', 'crop_cycle_id', 'project_id',
                'allocation_row_id', 'allocation_type', 'allocation_scope', 'party_id',
                'ledger_entry_id', 'account_code', 'account_name', 'account_type', 'debit_amount', 'credit_amount',
            ]);

            $page = 1;
            $per = 1000;
            while (true) {
                $res = $this->registerQuery->ledgerAuditRegisterForCropCycle($tenantId, $cropCycleId, $projectIds, $from, $to, 'date_asc', $page, $per);
                $rows = $res['rows'];
                if ($rows === []) {
                    break;
                }
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['posting_date'] ?? '',
                        $r['posting_group_id'] ?? '',
                        $r['source_type'] ?? '',
                        $r['source_id'] ?? '',
                        $r['crop_cycle_id'] ?? '',
                        $r['project_id'] ?? '',
                        $r['allocation_row_id'] ?? '',
                        $r['allocation_type'] ?? '',
                        $r['allocation_scope'] ?? '',
                        $r['party_id'] ?? '',
                        $r['ledger_entry_id'] ?? '',
                        $r['account_code'] ?? '',
                        $r['account_name'] ?? '',
                        $r['account_type'] ?? '',
                        $r['debit_amount'] ?? '',
                        $r['credit_amount'] ?? '',
                    ]);
                }
                $page++;
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportProjectPdf(Request $request): \Symfony\Component\HttpFoundation\Response|JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'project_id' => 'required|uuid',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $payload = $this->service->buildProject($tenantId, (string) $request->query('project_id'), (string) $request->query('from'), (string) $request->query('to'), [
            'include_register' => 'both',
            'allocation_page' => 1,
            'allocation_per_page' => 200,
            'ledger_page' => 1,
            'ledger_per_page' => 200,
            'register_order' => 'date_asc',
            'bucket' => 'total',
        ]);

        $pdf = $this->pdfRenderer->render($payload, 'Settlement Pack (Phase 1) — Project');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="settlement-pack-project.pdf"',
        ]);
    }

    public function exportCropCyclePdf(Request $request): \Symfony\Component\HttpFoundation\Response|JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $v = Validator::make($request->all(), [
            'crop_cycle_id' => 'required|uuid',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $payload = $this->service->buildCropCycle($tenantId, (string) $request->query('crop_cycle_id'), (string) $request->query('from'), (string) $request->query('to'), [
            'include_register' => 'both',
            'allocation_page' => 1,
            'allocation_per_page' => 200,
            'ledger_page' => 1,
            'ledger_per_page' => 200,
            'register_order' => 'date_asc',
            'bucket' => 'total',
            'include_projects_breakdown' => false,
        ]);

        $pdf = $this->pdfRenderer->render($payload, 'Settlement Pack (Phase 1) — Crop cycle');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="settlement-pack-crop-cycle.pdf"',
        ]);
    }

    private function opts(Request $request): array
    {
        $include = (string) ($request->query('include_register') ?: 'allocation');
        $order = (string) ($request->query('register_order') ?: 'date_asc');
        $bucket = (string) ($request->query('bucket') ?: 'total');

        $allocPage = (int) ($request->query('allocation_page') ?: 1);
        $allocPer = $this->registerQuery->clampPerPage($request->query('allocation_per_page') !== null ? (int) $request->query('allocation_per_page') : null);

        $ledgerPage = (int) ($request->query('ledger_page') ?: 1);
        $ledgerPer = $this->registerQuery->clampPerPage($request->query('ledger_per_page') !== null ? (int) $request->query('ledger_per_page') : null);

        return [
            'include_register' => $include,
            'allocation_page' => max(1, $allocPage),
            'allocation_per_page' => $allocPer,
            'ledger_page' => max(1, $ledgerPage),
            'ledger_per_page' => $ledgerPer,
            'register_order' => $order,
            'bucket' => $bucket,
        ];
    }
}


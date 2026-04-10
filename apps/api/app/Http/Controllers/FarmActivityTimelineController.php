<?php

namespace App\Http\Controllers;

use App\Models\FieldJob;
use App\Models\Harvest;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only merged timeline: field jobs, harvests, sales (tenant-scoped, module-aware).
 */
class FarmActivityTimelineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $tenant = Tenant::query()->find($tenantId);
        $from = $request->query('from');
        $to = $request->query('to');
        $limit = min(500, max(1, (int) $request->query('limit', 200)));

        $items = [];

        if ($tenant?->isModuleEnabled('crop_ops')) {
            $fjQuery = FieldJob::query()
                ->where('tenant_id', $tenantId)
                ->with([
                    'project:id,name',
                    'cropCycle:id,name',
                ]);
            if (is_string($from) && $from !== '') {
                $fjQuery->where('job_date', '>=', $from);
            }
            if (is_string($to) && $to !== '') {
                $fjQuery->where('job_date', '<=', $to);
            }
            foreach ($fjQuery->orderBy('job_date', 'desc')->orderBy('id', 'desc')->get() as $fj) {
                $ref = $fj->doc_no ? trim((string) $fj->doc_no) : '';
                $proj = $fj->project?->name;
                $cycle = $fj->cropCycle?->name;
                $summaryParts = array_filter([$proj, $cycle]);
                $summary = count($summaryParts) > 0 ? implode(' · ', $summaryParts) : 'Field work, inputs, labour & machinery';

                $items[] = [
                    'kind' => 'field_job',
                    'id' => $fj->id,
                    'activity_date' => $fj->job_date instanceof Carbon
                        ? $fj->job_date->format('Y-m-d')
                        : (string) $fj->job_date,
                    'title' => 'Field job',
                    'reference' => $ref !== '' ? $ref : null,
                    'summary' => $summary,
                    'status' => $fj->status,
                ];
            }

            $hQuery = Harvest::query()
                ->where('tenant_id', $tenantId)
                ->with([
                    'project:id,name',
                    'cropCycle:id,name',
                ]);
            if (is_string($from) && $from !== '') {
                $hQuery->where('harvest_date', '>=', $from);
            }
            if (is_string($to) && $to !== '') {
                $hQuery->where('harvest_date', '<=', $to);
            }
            foreach ($hQuery->orderBy('harvest_date', 'desc')->orderBy('id', 'desc')->get() as $h) {
                $ref = $h->harvest_no ? trim((string) $h->harvest_no) : '';
                $proj = $h->project?->name;
                $cycle = $h->cropCycle?->name;
                $summaryParts = array_filter([$proj, $cycle]);
                $summary = count($summaryParts) > 0 ? implode(' · ', $summaryParts) : 'Harvest output & sharing';

                $items[] = [
                    'kind' => 'harvest',
                    'id' => $h->id,
                    'activity_date' => $h->harvest_date instanceof Carbon
                        ? $h->harvest_date->format('Y-m-d')
                        : (string) $h->harvest_date,
                    'title' => 'Harvest',
                    'reference' => $ref !== '' ? $ref : null,
                    'summary' => $summary,
                    'status' => $h->status,
                ];
            }
        }

        if ($tenant?->isModuleEnabled('ar_sales')) {
            $sQuery = Sale::query()
                ->where('tenant_id', $tenantId)
                ->with(['buyerParty:id,name', 'project:id,name']);
            if (is_string($from) && $from !== '') {
                $sQuery->whereRaw('COALESCE(sale_date, posting_date) >= ?', [$from]);
            }
            if (is_string($to) && $to !== '') {
                $sQuery->whereRaw('COALESCE(sale_date, posting_date) <= ?', [$to]);
            }
            foreach ($sQuery->orderByRaw('COALESCE(sale_date, posting_date) DESC')->orderBy('id', 'desc')->get() as $s) {
                $eff = $s->sale_date ?? $s->posting_date;
                $dateStr = $eff instanceof Carbon ? $eff->format('Y-m-d') : (string) $eff;
                $ref = $s->sale_no ? trim((string) $s->sale_no) : '';
                $buyer = $s->buyerParty?->name;
                $proj = $s->project?->name;
                $kindLabel = $s->sale_kind === Sale::SALE_KIND_CREDIT_NOTE ? 'Credit note' : 'Sale';
                $summaryParts = array_filter([$buyer, $proj]);
                $summary = count($summaryParts) > 0 ? implode(' · ', $summaryParts) : ($kindLabel.' document');

                $items[] = [
                    'kind' => 'sale',
                    'id' => $s->id,
                    'activity_date' => $dateStr,
                    'title' => $kindLabel,
                    'reference' => $ref !== '' ? $ref : null,
                    'summary' => $summary,
                    'status' => $s->status,
                ];
            }
        }

        usort($items, function (array $a, array $b): int {
            $d = strcmp($b['activity_date'], $a['activity_date']);
            if ($d !== 0) {
                return $d;
            }
            $k = strcmp($a['kind'], $b['kind']);

            return $k !== 0 ? $k : strcmp($b['id'], $a['id']);
        });

        $items = array_slice($items, 0, $limit);

        return response()->json([
            'items' => array_values($items),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}

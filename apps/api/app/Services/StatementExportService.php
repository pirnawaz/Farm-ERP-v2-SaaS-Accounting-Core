<?php

namespace App\Services;

use App\Models\Party;
use App\Models\Project;
use App\Services\Document\StatementExportPdfRenderer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 7 — bounded exports (PDF/CSV) from existing read models only.
 */
class StatementExportService
{
    public function __construct(
        private ProjectResponsibilityReadService $projectResponsibilityReadService,
        private SettlementService $settlementService,
        private StatementExportPdfRenderer $pdfRenderer,
    ) {}

    public function exportProjectResponsibility(Request $request, string $tenantId): Response
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'format' => 'required|in:pdf,csv',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'project_id' => 'required|uuid|exists:projects,id',
            'crop_cycle_id' => 'nullable|uuid',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $project = Project::where('id', $request->input('project_id'))
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $report = $this->projectResponsibilityReadService->summarizeForProjectPeriod(
            $tenantId,
            $project->id,
            (string) $request->input('from'),
            (string) $request->input('to'),
            $request->filled('crop_cycle_id') ? (string) $request->input('crop_cycle_id') : null,
        );

        $format = (string) $request->input('format');
        $generated = Carbon::now()->format('Y-m-d H:i:s T');

        if ($format === 'csv') {
            $csv = $this->buildResponsibilityCsv($project->name, $generated, $report);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="project-responsibility-'.substr($project->id, 0, 8).'.csv"',
            ]);
        }

        $pdf = $this->pdfRenderer->renderResponsibility([
            'meta' => [
                'project_name' => $project->name,
                'generated_at' => $generated,
                'from' => $report['from'] ?? $request->input('from'),
                'to' => $report['to'] ?? $request->input('to'),
            ],
            'report' => $report,
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="project-responsibility-'.substr($project->id, 0, 8).'.pdf"',
        ]);
    }

    public function exportProjectPartyEconomics(Request $request, string $tenantId): Response
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'format' => 'required|in:pdf,csv',
            'project_id' => 'required|uuid|exists:projects,id',
            'party_id' => 'required|uuid|exists:parties,id',
            'up_to_date' => 'required|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $project = Project::where('id', $request->input('project_id'))
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        Party::where('id', $request->input('party_id'))
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $upTo = Carbon::parse((string) $request->input('up_to_date'))->format('Y-m-d');
        $preview = $this->settlementService->previewSettlement($project->id, $tenantId, $upTo);
        $payload = $this->projectResponsibilityReadService->partyEconomicsReadModel(
            $tenantId,
            $project->id,
            (string) $request->input('party_id'),
            $upTo,
            $preview,
        );

        $party = Party::find($request->input('party_id'));
        $format = (string) $request->input('format');
        $generated = Carbon::now()->format('Y-m-d H:i:s T');

        if ($format === 'csv') {
            $csv = $this->buildPartyEconomicsCsv($project->name, $party?->name ?? '', $generated, $payload);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="party-economics-'.substr($project->id, 0, 8).'.csv"',
            ]);
        }

        $pdf = $this->pdfRenderer->renderPartyEconomics([
            'meta' => [
                'project_name' => $project->name,
                'party_name' => $party?->name ?? $payload['party_id'],
                'generated_at' => $generated,
                'up_to_date' => $upTo,
            ],
            'is_project_hari_party' => (bool) ($payload['is_project_hari_party'] ?? false),
            'payload' => $payload,
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="party-economics-'.substr($project->id, 0, 8).'.pdf"',
        ]);
    }

    public function exportProjectSettlementReview(Request $request, string $tenantId): Response
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'format' => 'required|in:pdf,csv',
            'project_id' => 'required|uuid|exists:projects,id',
            'up_to_date' => 'required|date',
            'responsibility_from' => 'nullable|date',
            'responsibility_to' => 'nullable|date|after_or_equal:responsibility_from',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $project = Project::where('id', $request->input('project_id'))
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $upTo = Carbon::parse((string) $request->input('up_to_date'))->format('Y-m-d');
        $preview = $this->settlementService->previewSettlement($project->id, $tenantId, $upTo);
        $upToStr = $upTo;
        $preview['party_economics_explanation'] = $this->projectResponsibilityReadService->buildForSettlementPreview(
            $project->id,
            $tenantId,
            $upToStr,
        );

        $respFrom = $request->input('responsibility_from');
        $respTo = $request->input('responsibility_to');
        if ($respFrom === null || $respFrom === '') {
            $respFrom = Carbon::parse($upToStr)->copy()->startOfYear()->format('Y-m-d');
        } else {
            $respFrom = Carbon::parse((string) $respFrom)->format('Y-m-d');
        }
        if ($respTo === null || $respTo === '') {
            $respTo = $upToStr;
        } else {
            $respTo = Carbon::parse((string) $respTo)->format('Y-m-d');
        }

        $responsibility = $this->projectResponsibilityReadService->summarizeForProjectPeriod(
            $tenantId,
            $project->id,
            $respFrom,
            $respTo,
            null,
        );

        $hariPartyId = $project->party_id;
        $partyEcon = $this->projectResponsibilityReadService->partyEconomicsReadModel(
            $tenantId,
            $project->id,
            $hariPartyId,
            $upToStr,
            $preview,
        );

        $format = (string) $request->input('format');
        $generated = Carbon::now()->format('Y-m-d H:i:s T');

        if ($format === 'csv') {
            $csv = $this->buildSettlementReviewCsv(
                $project->name,
                $generated,
                $upToStr,
                $respFrom,
                $respTo,
                $preview,
                $responsibility,
                $partyEcon,
            );

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="settlement-review-'.substr($project->id, 0, 8).'.csv"',
            ]);
        }

        $pdf = $this->pdfRenderer->renderSettlementReviewPack([
            'meta' => [
                'project_name' => $project->name,
                'generated_at' => $generated,
                'up_to_date' => $upToStr,
                'responsibility_from' => $respFrom,
                'responsibility_to' => $respTo,
            ],
            'preview' => $preview,
            'responsibility' => $responsibility,
            'party_economics' => $partyEcon,
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="settlement-review-'.substr($project->id, 0, 8).'.pdf"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function buildResponsibilityCsv(string $projectName, string $generated, array $report): string
    {
        $lines = [];
        $lines[] = 'section,key,value';
        $lines[] = 'meta,project_name,'.$this->csvEscape($projectName);
        $lines[] = 'meta,generated_at,'.$this->csvEscape($generated);
        $lines[] = 'meta,from,'.$this->csvEscape((string) ($report['from'] ?? ''));
        $lines[] = 'meta,to,'.$this->csvEscape((string) ($report['to'] ?? ''));
        $lines[] = 'meta,posting_groups_count,'.(string) ((int) ($report['posting_groups_count'] ?? 0));
        $buckets = $report['buckets'] ?? [];
        if (is_array($buckets) && ! array_is_list($buckets)) {
            foreach ($buckets as $k => $v) {
                $lines[] = 'bucket,'.$this->csvEscape((string) $k).','.$this->csvEscape((string) $v);
            }
        }
        foreach ($report['by_effective_responsibility'] ?? [] as $scope => $amt) {
            $lines[] = 'by_effective_responsibility,'.$this->csvEscape((string) $scope).','.$this->csvEscape((string) $amt);
        }
        foreach ($report['top_allocation_types'] ?? [] as $row) {
            $t = (string) ($row['type'] ?? '');
            $a = (string) ($row['amount'] ?? '');
            $lines[] = 'top_allocation_type,'.$this->csvEscape($t).','.$this->csvEscape($a);
        }
        $terms = $report['settlement_terms'] ?? [];
        foreach ($terms as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $lines[] = 'settlement_terms,'.$this->csvEscape((string) $k).','.$this->csvEscape((string) $v);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildPartyEconomicsCsv(string $projectName, string $partyName, string $generated, array $payload): string
    {
        $lines = [];
        $lines[] = 'section,key,value';
        $lines[] = 'meta,project_name,'.$this->csvEscape($projectName);
        $lines[] = 'meta,party_name,'.$this->csvEscape($partyName);
        $lines[] = 'meta,generated_at,'.$this->csvEscape($generated);
        $lines[] = 'meta,up_to_date,'.$this->csvEscape((string) ($payload['up_to_date'] ?? ''));
        $lines[] = 'meta,is_project_hari_party,'.((($payload['is_project_hari_party'] ?? false) ? '1' : '0'));
        $hari = $payload['hari_settlement_preview'] ?? [];
        foreach ($hari as $k => $v) {
            $lines[] = 'hari_settlement_preview,'.$this->csvEscape((string) $k).','.$this->csvEscape((string) $v);
        }
        $expl = $payload['party_economics_explanation'] ?? [];
        foreach ($expl['summary_lines'] ?? [] as $label => $amt) {
            $lines[] = 'summary_line,'.$this->csvEscape((string) $label).','.$this->csvEscape((string) $amt);
        }
        $rec = $expl['recoverability'] ?? [];
        foreach ($rec as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $lines[] = 'recoverability,'.$this->csvEscape((string) $k).','.$this->csvEscape((string) $v);
            }
        }
        $terms = $payload['settlement_terms'] ?? [];
        foreach ($terms as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $lines[] = 'settlement_terms,'.$this->csvEscape((string) $k).','.$this->csvEscape((string) $v);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $responsibility
     * @param  array<string, mixed>  $partyEcon
     */
    private function buildSettlementReviewCsv(
        string $projectName,
        string $generated,
        string $upTo,
        string $respFrom,
        string $respTo,
        array $preview,
        array $responsibility,
        array $partyEcon,
    ): string {
        $lines = [];
        $lines[] = 'section,key,value';
        $lines[] = 'meta,project_name,'.$this->csvEscape($projectName);
        $lines[] = 'meta,generated_at,'.$this->csvEscape($generated);
        $lines[] = 'meta,preview_up_to_date,'.$this->csvEscape($upTo);
        $lines[] = 'meta,responsibility_from,'.$this->csvEscape($respFrom);
        $lines[] = 'meta,responsibility_to,'.$this->csvEscape($respTo);
        foreach (['settlement_rule_source', 'settlement_agreement_id', 'settlement_project_rule_id', 'pool_profit', 'hari_net', 'landlord_gross', 'kamdari_amount'] as $k) {
            if (array_key_exists($k, $preview)) {
                $lines[] = 'preview,'.$k.','.$this->csvEscape((string) $preview[$k]);
            }
        }
        $buckets = $responsibility['buckets'] ?? [];
        if (is_array($buckets) && ! array_is_list($buckets)) {
            foreach ($buckets as $k => $v) {
                $lines[] = 'responsibility_bucket,'.$this->csvEscape((string) $k).','.$this->csvEscape((string) $v);
            }
        }
        $hari = $partyEcon['hari_settlement_preview'] ?? [];
        foreach ($hari as $k => $v) {
            $lines[] = 'party_economics_hari,'.$this->csvEscape((string) $k).','.$this->csvEscape((string) $v);
        }

        return implode("\n", $lines)."\n";
    }

    private function csvEscape(string $v): string
    {
        if (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n")) {
            return '"'.str_replace('"', '""', $v).'"';
        }

        return $v;
    }
}

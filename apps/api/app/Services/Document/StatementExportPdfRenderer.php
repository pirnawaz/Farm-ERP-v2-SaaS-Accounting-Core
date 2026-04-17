<?php

namespace App\Services\Document;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Phase 7 — read-only PDF presentation for responsibility / party economics / settlement review exports.
 * Renders pre-assembled view arrays only; no financial calculations.
 */
class StatementExportPdfRenderer
{
    private const COLOR_HEADING = '#2D3A3A';

    private const COLOR_TOTAL = '#1F6F5C';

    private const COLOR_BG = '#E6ECEA';

    public function renderResponsibility(array $view): string
    {
        $style = $this->inlineStyles();
        $meta = $view['meta'] ?? [];
        $projectName = e((string) ($meta['project_name'] ?? '—'));
        $generated = e((string) ($meta['generated_at'] ?? ''));
        $from = e((string) ($meta['from'] ?? ''));
        $to = e((string) ($meta['to'] ?? ''));
        $report = $view['report'] ?? [];
        $buckets = $report['buckets'] ?? [];
        $isListBuckets = is_array($buckets) && array_is_list($buckets);

        $headline = $this->headlineTable($isListBuckets ? [] : (array) $buckets);
        $bucketsSection = $this->effectiveResponsibilityTable($report['by_effective_responsibility'] ?? []);
        $topTypes = $this->topTypesTable($report['top_allocation_types'] ?? []);
        $terms = $this->settlementTermsHtml($report['settlement_terms'] ?? []);
        $notes = $this->responsibilityNotesHtml($report, $isListBuckets);

        $pgCount = (int) ($report['posting_groups_count'] ?? 0);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>{$style}</style>
</head>
<body>
<h1>Project responsibility report</h1>
<p class="muted">Who bears what — period view (same basis as Field Cycle P&amp;L posting groups).</p>
<table class="meta">
<tr><td><strong>Project</strong></td><td>{$projectName}</td></tr>
<tr><td><strong>Period</strong></td><td>{$from} to {$to}</td></tr>
<tr><td><strong>Generated</strong></td><td>{$generated}</td></tr>
<tr><td><strong>Posting groups in range</strong></td><td>{$pgCount}</td></tr>
</table>
<h2>Headline totals</h2>
{$headline}
{$notes}
<h2>Settlement basis</h2>
{$terms}
<h2>Effective responsibility buckets</h2>
{$bucketsSection}
<h2>Top allocation types</h2>
{$topTypes}
<div class="footer">Terrava — read-only export. Figures are from posted allocations; not recalculated in this document.</div>
</body>
</html>
HTML;

        return Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();
    }

    public function renderPartyEconomics(array $view): string
    {
        $style = $this->inlineStyles();
        $meta = $view['meta'] ?? [];
        $projectName = e((string) ($meta['project_name'] ?? '—'));
        $partyName = e((string) ($meta['party_name'] ?? '—'));
        $generated = e((string) ($meta['generated_at'] ?? ''));
        $upTo = e((string) ($meta['up_to_date'] ?? ''));
        $isHari = (bool) ($view['is_project_hari_party'] ?? false);
        $hariNote = $isHari ? '' : '<p class="warn">This party is not the project Hari. The detailed settlement slice below applies only when the selected party is the project Hari.</p>';
        $payload = $view['payload'] ?? [];
        $hari = $payload['hari_settlement_preview'] ?? null;
        $explanation = $payload['party_economics_explanation'] ?? [];
        $terms = $this->settlementTermsHtml($payload['settlement_terms'] ?? []);

        $hariHtml = $hari ? $this->hariPreviewTable($hari) : '<p class="muted">No Hari settlement slice in this response.</p>';
        $recover = $this->recoverabilityHtml($explanation['recoverability'] ?? null);
        $summaryLines = $this->summaryLinesTable($explanation['summary_lines'] ?? []);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>{$style}</style>
</head>
<body>
<h1>Hari statement / party economics</h1>
<p class="muted">Up-to-date settlement-style view for the date shown (server settlement preview basis).</p>
<table class="meta">
<tr><td><strong>Project</strong></td><td>{$projectName}</td></tr>
<tr><td><strong>Party</strong></td><td>{$partyName}</td></tr>
<tr><td><strong>Up to date</strong></td><td>{$upTo}</td></tr>
<tr><td><strong>Generated</strong></td><td>{$generated}</td></tr>
</table>
{$hariNote}
<h2>Settlement basis</h2>
{$terms}
<h2>Hari settlement figures (from preview)</h2>
{$hariHtml}
<h2>Summary lines</h2>
{$summaryLines}
<h2>Who bears what — recoverability</h2>
{$recover}
<div class="footer">Terrava — read-only export. Numeric fields are taken from the API read model, not recomputed here.</div>
</body>
</html>
HTML;

        return Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();
    }

    public function renderSettlementReviewPack(array $view): string
    {
        $style = $this->inlineStyles();
        $meta = $view['meta'] ?? [];
        $projectName = e((string) ($meta['project_name'] ?? '—'));
        $generated = e((string) ($meta['generated_at'] ?? ''));
        $upTo = e((string) ($meta['up_to_date'] ?? ''));
        $respFrom = e((string) ($meta['responsibility_from'] ?? ''));
        $respTo = e((string) ($meta['responsibility_to'] ?? ''));
        $preview = $view['preview'] ?? [];
        $partyExpl = $preview['party_economics_explanation'] ?? [];
        $responsibility = $view['responsibility'] ?? [];
        $partyEcon = $view['party_economics'] ?? [];

        $previewHtml = $this->previewSummaryTable($preview);
        $recover = $this->recoverabilityHtml($partyExpl['recoverability'] ?? null);
        $summaryLines = $this->summaryLinesTable($partyExpl['summary_lines'] ?? []);
        $buckets = $responsibility['buckets'] ?? [];
        $isListBuckets = is_array($buckets) && array_is_list($buckets);
        $headline = $this->headlineTable($isListBuckets ? [] : (array) $buckets);
        $respNotes = $this->responsibilityNotesHtml($responsibility, $isListBuckets);
        $termsPrev = $this->previewRuleSourceHtml($preview);
        $termsResp = $this->settlementTermsHtml($responsibility['settlement_terms'] ?? []);
        $hariHtml = isset($partyEcon['hari_settlement_preview']) ? $this->hariPreviewTable($partyEcon['hari_settlement_preview']) : '<p class="muted">No Hari slice (party economics payload).</p>';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>{$style}</style>
</head>
<body>
<h1>Settlement review pack</h1>
<p class="muted">Combined read-only summary: settlement preview (as of {$upTo}), period responsibility ({$respFrom}–{$respTo}), and party economics for the project Hari.</p>
<table class="meta">
<tr><td><strong>Project</strong></td><td>{$projectName}</td></tr>
<tr><td><strong>Preview as of</strong></td><td>{$upTo}</td></tr>
<tr><td><strong>Responsibility period</strong></td><td>{$respFrom} to {$respTo}</td></tr>
<tr><td><strong>Generated</strong></td><td>{$generated}</td></tr>
</table>
<h2>1) Settlement preview summary</h2>
{$termsPrev}
{$previewHtml}
<h2>2) Who bears what (preview explanation)</h2>
{$summaryLines}
{$recover}
<h2>3) Project responsibility (period)</h2>
{$headline}
{$respNotes}
{$termsResp}
<h2>4) Party economics — Hari</h2>
{$hariHtml}
<div class="footer">Terrava — Settlement review pack. Not a governance finalized settlement pack. Figures are from existing read models only.</div>
</body>
</html>
HTML;

        return Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();
    }

    private function inlineStyles(): string
    {
        return '
            body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: '.self::COLOR_HEADING.'; margin: 20px; }
            h1 { font-size: 16pt; color: '.self::COLOR_HEADING.'; margin-bottom: 6px; }
            h2 { font-size: 11pt; color: '.self::COLOR_HEADING.'; margin-top: 14px; margin-bottom: 6px; border-bottom: 1px solid '.self::COLOR_BG.'; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            table.meta { width: auto; margin-bottom: 12px; }
            th, td { padding: 5px 7px; text-align: left; vertical-align: top; }
            th { background: '.self::COLOR_BG.'; font-weight: bold; }
            tr:nth-child(even) { background: #f8f9f8; }
            .amount { font-variant-numeric: tabular-nums; text-align: right; }
            .total-row { font-weight: bold; color: '.self::COLOR_TOTAL.'; }
            .footer { margin-top: 18px; font-size: 8pt; color: #666; }
            .muted { color: #555; font-size: 8.5pt; }
            .warn { background: #fff8e6; border: 1px solid #e6d08a; padding: 8px; font-size: 8.5pt; }
        ';
    }

    private function headlineTable(array $buckets): string
    {
        if ($buckets === []) {
            return '<p class="muted">No bucket totals (no postings in period or empty scope).</p>';
        }
        $rows = '';
        $map = [
            'settlement_shared_pool_costs' => 'Shared costs (pool)',
            'hari_only_costs' => 'Hari-only costs',
            'landlord_only_costs' => 'Landlord-only costs',
            'shared_scope_non_pool_share_positive' => 'Other shared-scope (positive)',
            'legacy_unscoped_amount' => 'Legacy unscoped allocations',
        ];
        foreach ($map as $key => $label) {
            if (! array_key_exists($key, $buckets)) {
                continue;
            }
            $val = e((string) $buckets[$key]);
            $rows .= '<tr><td>'.e($label).'</td><td class="amount">'.$val.'</td></tr>';
        }

        return '<table><thead><tr><th>Measure</th><th class="amount">Amount</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    private function effectiveResponsibilityTable(array $by): string
    {
        if ($by === []) {
            return '<p class="muted">No rows.</p>';
        }
        $body = '';
        foreach ($by as $scope => $amt) {
            $body .= '<tr><td>'.e((string) $scope).'</td><td class="amount">'.e((string) $amt).'</td></tr>';
        }

        return '<table><thead><tr><th>Scope</th><th class="amount">Amount</th></tr></thead><tbody>'.$body.'</tbody></table>';
    }

    private function topTypesTable(array $rows): string
    {
        if ($rows === []) {
            return '<p class="muted">None returned.</p>';
        }
        $body = '';
        foreach ($rows as $r) {
            $body .= '<tr><td>'.e((string) ($r['type'] ?? '')).'</td><td class="amount">'.e((string) ($r['amount'] ?? '')).'</td></tr>';
        }

        return '<table><thead><tr><th>Allocation type</th><th class="amount">Amount</th></tr></thead><tbody>'.$body.'</tbody></table>';
    }

    private function settlementTermsHtml(array $terms): string
    {
        if ($terms === []) {
            return '<p class="muted">—</p>';
        }
        if (! empty($terms['resolution_error'])) {
            return '<p class="warn">'.e((string) $terms['resolution_error']).'</p>';
        }
        $src = (string) ($terms['resolution_source'] ?? '');
        $srcLabel = $src === 'agreement' ? 'Agreement (primary)' : ($src === 'project_rule' ? 'Legacy project rules (fallback)' : $src);
        $split = e((string) ($terms['profit_split_landlord_pct'] ?? '—')).' / '.e((string) ($terms['profit_split_hari_pct'] ?? '—'));

        return '<table class="meta"><tr><td><strong>Resolution</strong></td><td>'.e($srcLabel).'</td></tr>'
            .'<tr><td><strong>Profit split (landlord / Hari) %</strong></td><td>'.$split.'</td></tr>'
            .'<tr><td><strong>Kamdari %</strong></td><td>'.e((string) ($terms['kamdari_pct'] ?? '—')).'</td></tr></table>';
    }

    private function previewRuleSourceHtml(array $preview): string
    {
        $src = (string) ($preview['settlement_rule_source'] ?? '');
        if ($src === '') {
            return '<p class="muted">Rule source not included in preview payload.</p>';
        }
        $label = $src === 'agreement' ? 'Agreement (primary)' : ($src === 'project_rule' ? 'Legacy project rules (fallback)' : $src);
        $agr = e((string) ($preview['settlement_agreement_id'] ?? ''));
        $rule = e((string) ($preview['settlement_project_rule_id'] ?? ''));

        return '<table class="meta"><tr><td><strong>Settlement rule source</strong></td><td>'.e($label).'</td></tr>'
            .'<tr><td><strong>Agreement id</strong></td><td>'.$agr.'</td></tr>'
            .'<tr><td><strong>Project rule id</strong></td><td>'.$rule.'</td></tr></table>';
    }

    private function previewSummaryTable(array $preview): string
    {
        $keys = [
            'total_revenue' => 'Total revenue',
            'total_expenses' => 'Total expenses',
            'pool_revenue' => 'Pool revenue',
            'pool_profit' => 'Pool profit',
            'kamdari_amount' => 'Kamdari',
            'landlord_gross' => 'Landlord gross',
            'hari_gross' => 'Hari gross',
            'hari_only_deductions' => 'Hari-only deductions',
            'hari_net' => 'Hari net',
            'hari_position' => 'Hari position',
        ];
        $body = '';
        foreach ($keys as $k => $label) {
            if (! array_key_exists($k, $preview)) {
                continue;
            }
            $body .= '<tr><td>'.e($label).'</td><td class="amount">'.e((string) $preview[$k]).'</td></tr>';
        }

        return '<table><thead><tr><th>Line</th><th class="amount">Value</th></tr></thead><tbody>'.$body.'</tbody></table>';
    }

    private function hariPreviewTable(array $hari): string
    {
        $body = '';
        foreach (['hari_gross' => 'Gross share', 'hari_only_deductions' => 'Hari-only deductions', 'hari_net' => 'Net', 'hari_position' => 'Position', 'kamdari_amount' => 'Kamdari', 'landlord_gross' => 'Landlord gross'] as $k => $label) {
            if (! array_key_exists($k, $hari)) {
                continue;
            }
            $body .= '<tr><td>'.e($label).'</td><td class="amount">'.e((string) $hari[$k]).'</td></tr>';
        }

        return '<table><thead><tr><th>Line</th><th class="amount">Value</th></tr></thead><tbody>'.$body.'</tbody></table>';
    }

    private function summaryLinesTable(?array $lines): string
    {
        if ($lines === null || $lines === []) {
            return '<p class="muted">None.</p>';
        }
        $body = '';
        foreach ($lines as $label => $amt) {
            $body .= '<tr><td>'.e((string) $label).'</td><td class="amount">'.e((string) $amt).'</td></tr>';
        }

        return '<table><thead><tr><th>Line</th><th class="amount">Amount</th></tr></thead><tbody>'.$body.'</tbody></table>';
    }

    private function recoverabilityHtml(?array $rec): string
    {
        if ($rec === null || $rec === []) {
            return '<p class="muted">None.</p>';
        }
        $body = '';
        foreach ([
            'included_in_shared_pool_for_settlement' => 'In shared pool (settlement base)',
            'hari_borne_after_split' => 'Hari-only (after split)',
            'owner_borne_not_in_pool' => 'Landlord / owner-only',
            'shared_scope_other_amounts' => 'Other shared-scope',
        ] as $k => $label) {
            if (! array_key_exists($k, $rec)) {
                continue;
            }
            $body .= '<tr><td>'.e($label).'</td><td class="amount">'.e((string) $rec[$k]).'</td></tr>';
        }
        $note = isset($rec['shared_scope_other_note']) && $rec['shared_scope_other_note']
            ? '<p class="muted">'.e((string) $rec['shared_scope_other_note']).'</p>' : '';

        return '<table><thead><tr><th>Item</th><th class="amount">Amount</th></tr></thead><tbody>'.$body.'</tbody></table>'.$note;
    }

    private function responsibilityNotesHtml(array $report, bool $isEmptyBucketsList): string
    {
        if ($isEmptyBucketsList) {
            return '<p class="warn">No posting groups matched this period — headline totals are empty by design.</p>';
        }
        $legacy = (float) ($report['buckets']['legacy_unscoped_amount'] ?? 0);
        $other = (float) ($report['buckets']['shared_scope_non_pool_share_positive'] ?? 0);
        $parts = [];
        if ($other > 0.005) {
            $parts[] = 'Other shared-scope amounts may appear in the ledger; settlement pool rules still match the live engine.';
        }
        if ($legacy > 0.005) {
            $parts[] = 'Legacy unscoped allocations present — review source postings if material.';
        }
        if ($parts === []) {
            return '';
        }

        return '<p class="muted">'.e(implode(' ', $parts)).'</p>';
    }
}

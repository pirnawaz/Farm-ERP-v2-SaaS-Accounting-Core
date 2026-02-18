<?php

namespace App\Services\Document;

use App\Models\SettlementPack;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a Settlement Pack snapshot (summary_json) to a single PDF.
 * Terrava brand: Deep Earth #2D3A3A, Terrava Green #1F6F5C, Sand #C9A24D, Stone Grey #E6ECEA.
 * Uses pack.summary_json only; no recalculation.
 */
class SettlementPackPdfRenderer
{
    private const COLOR_HEADING = '#2D3A3A';
    private const COLOR_TOTAL = '#1F6F5C';
    private const COLOR_ACCENT = '#C9A24D';
    private const COLOR_BG = '#E6ECEA';

    public function render(SettlementPack $pack, int $documentVersion, string $sha256Short = ''): string
    {
        $pack->load(['project.cropCycle']);
        $project = $pack->project;
        $cropCycle = $project?->cropCycle;
        $summary = $pack->summary_json ?? [];
        $registerRows = $summary['register_rows'] ?? [];
        $fs = $summary['financial_statements'] ?? [];
        $tb = $fs['trial_balance'] ?? [];
        $pl = $fs['profit_loss'] ?? [];
        $bs = $fs['balance_sheet'] ?? [];

        $asOf = $tb['as_of'] ?? $bs['meta']['as_of'] ?? $pack->generated_at?->format('Y-m-d') ?? '';
        $from = $pl['meta']['from'] ?? '';

        $html = $this->buildHtml([
            'pack' => $pack,
            'project' => $project,
            'cropCycle' => $cropCycle,
            'summary' => $summary,
            'registerRows' => $registerRows,
            'trialBalance' => $tb,
            'profitLoss' => $pl,
            'balanceSheet' => $bs,
            'documentVersion' => $documentVersion,
            'sha256Short' => $sha256Short,
            'asOf' => $asOf,
            'from' => $from,
        ]);

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->output();
    }

    private function buildHtml(array $data): string
    {
        $pack = $data['pack'];
        $project = $data['project'];
        $cropCycle = $data['cropCycle'];
        $documentVersion = $data['documentVersion'];
        $sha256Short = $data['sha256Short'];
        $style = $this->inlineStyles();

        $cover = $this->coverSection($pack, $project, $cropCycle, $data['asOf'], $documentVersion, $style);
        $register = $this->registerSection($data['registerRows'], $data['summary'], $style);
        $tbSection = $this->trialBalanceSection($data['trialBalance'], $style);
        $plSection = $this->profitLossSection($data['profitLoss'], $style);
        $bsSection = $this->balanceSheetSection($data['balanceSheet'], $style);
        $footer = $this->footerHtml($pack->id, $documentVersion, $sha256Short, $style);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>{$style}</style>
</head>
<body>
{$cover}
{$register}
{$tbSection}
{$plSection}
{$bsSection}
<div class="footer">{$footer}</div>
</body>
</html>
HTML;
    }

    private function inlineStyles(): string
    {
        return '
            body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: ' . self::COLOR_HEADING . '; margin: 20px; }
            h1 { font-size: 18pt; color: ' . self::COLOR_HEADING . '; margin-bottom: 4px; }
            h2 { font-size: 12pt; color: ' . self::COLOR_HEADING . '; margin-top: 16px; margin-bottom: 8px; border-bottom: 1px solid ' . self::COLOR_BG . '; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            th, td { padding: 6px 8px; text-align: left; }
            th { background: ' . self::COLOR_BG . '; font-weight: bold; }
            tr:nth-child(even) { background: #f8f9f8; }
            .total-row { font-weight: bold; color: ' . self::COLOR_TOTAL . '; }
            .amount { font-variant-numeric: tabular-nums; text-align: right; }
            .footer { margin-top: 24px; font-size: 8pt; color: #666; }
            .cover-meta { margin-top: 8px; font-size: 10pt; }
        ';
    }

    private function coverSection($pack, $project, $cropCycle, string $asOf, int $version, string $style): string
    {
        $projectName = $project ? e($project->name) : '—';
        $cycleName = $cropCycle ? e($cropCycle->name) : '—';
        $finalized = $pack->finalized_at ? $pack->finalized_at->format('Y-m-d H:i') : '—';
        return <<<HTML
<div style="margin-bottom: 24px;">
<h1>Settlement Pack</h1>
<div class="cover-meta">
<table style="width: auto;">
<tr><td><strong>Project</strong></td><td>{$projectName}</td></tr>
<tr><td><strong>Crop Cycle</strong></td><td>{$cycleName}</td></tr>
<tr><td><strong>As-of</strong></td><td>{$asOf}</td></tr>
<tr><td><strong>Pack status</strong></td><td>{$pack->status}</td></tr>
<tr><td><strong>Document version</strong></td><td>{$version}</td></tr>
<tr><td><strong>Generated</strong></td><td>{$pack->generated_at?->format('Y-m-d H:i')}</td></tr>
<tr><td><strong>Finalized</strong></td><td>{$finalized}</td></tr>
</table>
</div>
</div>
HTML;
    }

    private function registerSection(array $rows, array $summary, string $style): string
    {
        $totalAmount = $summary['total_amount'] ?? '0';
        $rowCount = count($rows) ?: (int) ($summary['row_count'] ?? 0);
        $header = '<tr><th>Posting date</th><th>Source type</th><th>Source ID</th><th>Allocation type</th><th class="amount">Amount</th></tr>';
        $body = '';
        foreach ($rows as $r) {
            $date = $r['posting_date'] ?? '';
            if (is_object($date) && method_exists($date, 'format')) {
                $date = $date->format('Y-m-d');
            }
            $srcId = $r['source_id'] ?? '';
            $body .= '<tr><td>' . e((string) $date) . '</td><td>' . e($r['source_type'] ?? '') . '</td><td>' . e(strlen($srcId) > 8 ? substr($srcId, 0, 8) . '…' : $srcId) . '</td><td>' . e($r['allocation_type'] ?? '') . '</td><td class="amount">' . e((string) ($r['amount'] ?? '')) . '</td></tr>';
        }
        $body .= '<tr class="total-row"><td colspan="4">Total</td><td class="amount">' . e((string) $totalAmount) . '</td></tr>';
        return '<h2>A) Transaction Register</h2><p>Row count: ' . $rowCount . '</p><table><thead>' . $header . '</thead><tbody>' . $body . '</tbody></table>';
    }

    private function trialBalanceSection(array $tb, string $style): string
    {
        $rows = $tb['rows'] ?? [];
        $totals = $tb['totals'] ?? [];
        $balanced = ($tb['balanced'] ?? false) ? 'Yes' : 'No';
        $header = '<tr><th>Account code</th><th>Account name</th><th class="amount">Debit</th><th class="amount">Credit</th></tr>';
        $body = '';
        foreach ($rows as $r) {
            $body .= '<tr><td>' . e($r['account_code'] ?? '') . '</td><td>' . e($r['account_name'] ?? '') . '</td><td class="amount">' . e($r['total_debit'] ?? '') . '</td><td class="amount">' . e($r['total_credit'] ?? '') . '</td></tr>';
        }
        $body .= '<tr class="total-row"><td colspan="2">Total</td><td class="amount">' . e($totals['total_debit'] ?? '') . '</td><td class="amount">' . e($totals['total_credit'] ?? '') . '</td></tr>';
        return '<h2>B) Trial Balance</h2><table><thead>' . $header . '</thead><tbody>' . $body . '</tbody></table><p><strong>Balanced:</strong> ' . $balanced . '</p>';
    }

    private function profitLossSection(array $pl, string $style): string
    {
        $income = $pl['rows']['income'] ?? [];
        $expenses = $pl['rows']['expenses'] ?? [];
        $totals = $pl['totals'] ?? [];
        $header = '<tr><th>Code</th><th>Name</th><th class="amount">Amount</th></tr>';
        $body = '';
        foreach ($income as $r) {
            $body .= '<tr><td>' . e($r['account_code'] ?? '') . '</td><td>' . e($r['account_name'] ?? '') . '</td><td class="amount">' . e((string)($r['amount'] ?? '')) . '</td></tr>';
        }
        $body .= '<tr class="total-row"><td colspan="2">Income total</td><td class="amount">' . e((string)($totals['income_total'] ?? '')) . '</td></tr>';
        foreach ($expenses as $r) {
            $body .= '<tr><td>' . e($r['account_code'] ?? '') . '</td><td>' . e($r['account_name'] ?? '') . '</td><td class="amount">' . e((string)($r['amount'] ?? '')) . '</td></tr>';
        }
        $body .= '<tr class="total-row"><td colspan="2">Expense total</td><td class="amount">' . e((string)($totals['expense_total'] ?? '')) . '</td></tr>';
        $body .= '<tr class="total-row"><td colspan="2">Net profit</td><td class="amount">' . e((string)($totals['net_profit'] ?? '')) . '</td></tr>';
        return '<h2>C) Profit &amp; Loss</h2><table><thead>' . $header . '</thead><tbody>' . $body . '</tbody></table>';
    }

    private function balanceSheetSection(array $bs, string $style): string
    {
        $sections = $bs['sections'] ?? [];
        $totals = $bs['totals'] ?? [];
        $balanced = ($totals['balanced'] ?? false) ? 'Yes' : 'No';
        $body = '';
        foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $key => $label) {
            $lines = $sections[$key] ?? [];
            $body .= '<tr><td colspan="3" style="font-weight:bold;">' . $label . '</td></tr>';
            foreach ($lines as $r) {
                $name = $r['account_name'] ?? $r['account_code'] ?? '';
                if ($name === '') {
                    $name = $r['name'] ?? '—';
                }
                $body .= '<tr><td></td><td>' . e($name) . '</td><td class="amount">' . e((string)($r['net'] ?? $r['amount'] ?? '')) . '</td></tr>';
            }
        }
        $body .= '<tr class="total-row"><td colspan="2">Assets total</td><td class="amount">' . e((string)($totals['assets_total'] ?? '')) . '</td></tr>';
        $body .= '<tr class="total-row"><td colspan="2">Liabilities + Equity total</td><td class="amount">' . e((string)($totals['liabilities_plus_equity_total'] ?? '')) . '</td></tr>';
        $body .= '<tr class="total-row"><td colspan="2">Balanced</td><td>' . $balanced . '</td></tr>';
        return '<h2>D) Balance Sheet</h2><table><thead><tr><th></th><th>Account</th><th class="amount">Net</th></tr></thead><tbody>' . $body . '</tbody></table>';
    }

    private function footerHtml(string $packId, int $version, string $sha256Short, string $style): string
    {
        return 'Terrava Settlement Pack | Pack ID: ' . e(substr($packId, 0, 8)) . '… | Version ' . $version . ' | SHA-256: ' . e($sha256Short);
    }
}

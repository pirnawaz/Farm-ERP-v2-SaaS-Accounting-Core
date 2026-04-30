<?php

namespace App\Services\Document;

use Barryvdh\DomPDF\Facade\Pdf;

class SettlementPackPhase1PdfRenderer
{
    private const COLOR_HEADING = '#2D3A3A';
    private const COLOR_TOTAL = '#1F6F5C';
    private const COLOR_BG = '#E6ECEA';

    /**
     * @param  array<string,mixed>  $payload SettlementPackPhase1Response-like array
     */
    public function render(array $payload, string $title): string
    {
        $style = $this->inlineStyles();
        $html = $this->buildHtml($payload, $title, $style);

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
        ';
    }

    private function buildHtml(array $payload, string $title, string $style): string
    {
        $scope = (array) ($payload['scope'] ?? []);
        $period = (array) ($payload['period'] ?? []);
        $totals = (array) ($payload['totals'] ?? []);
        $meta = (array) ($payload['_meta'] ?? []);
        $exports = (array) ($payload['exports'] ?? []);

        $from = e((string) ($period['from'] ?? ''));
        $to = e((string) ($period['to'] ?? ''));
        $currency = e((string) ($payload['currency_code'] ?? ''));
        $generated = e((string) ($meta['generated_at_utc'] ?? ''));

        $scopeRows = '';
        foreach (['kind', 'project_id', 'crop_cycle_id'] as $k) {
            if (! array_key_exists($k, $scope)) {
                continue;
            }
            $scopeRows .= '<tr><td><strong>'.e($k).'</strong></td><td>'.e((string) ($scope[$k] ?? '')).'</td></tr>';
        }

        $summary = $this->summaryTable($totals, $currency);
        $registerAlloc = $this->registerTable(($payload['register']['allocation_rows']['rows'] ?? []), 200, true);
        $registerLedger = $this->registerTable(($payload['register']['ledger_lines']['rows'] ?? []), 200, false);

        $csvSummary = e((string) ($exports['csv']['summary_url'] ?? ''));

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>{$style}</style>
</head>
<body>
<h1>{$title}</h1>
<p class="muted">Read-only settlement pack (Phase 1). Period {$from} to {$to}. Currency {$currency}. Generated {$generated}.</p>
<table class="meta">
{$scopeRows}
<tr><td><strong>from</strong></td><td>{$from}</td></tr>
<tr><td><strong>to</strong></td><td>{$to}</td></tr>
<tr><td><strong>Summary CSV</strong></td><td>{$csvSummary}</td></tr>
</table>
<h2>1) Executive summary</h2>
{$summary}
<h2>2) Allocation register (first 200)</h2>
{$registerAlloc}
<h2>3) Ledger audit register (first 200)</h2>
{$registerLedger}
<div class="footer">Terrava — Settlement Pack Phase 1 (read-only). Use CSV exports for full registers.</div>
</body>
</html>
HTML;
    }

    private function summaryTable(array $totals, string $currency): string
    {
        $hp = (array) ($totals['harvest_production'] ?? []);
        $rev = (array) ($totals['ledger_revenue'] ?? []);
        $cost = (array) ($totals['costs'] ?? []);
        $adv = (array) ($totals['advances'] ?? []);
        $net = (array) ($totals['net'] ?? []);

        $rows = '';
        $pairs = [
            'Harvest production qty' => ($hp['qty'] ?? null),
            'Harvest production value' => ($hp['value'] ?? null),
            'Ledger revenue (total)' => ($rev['total'] ?? '0.00'),
            'Cost inputs' => ($cost['inputs'] ?? '0.00'),
            'Cost labour' => ($cost['labour'] ?? '0.00'),
            'Cost machinery' => ($cost['machinery'] ?? '0.00'),
            'Cost credit premium' => ($cost['credit_premium'] ?? '0.00'),
            'Cost other' => ($cost['other'] ?? '0.00'),
            'Total cost' => ($cost['total'] ?? '0.00'),
            'Advances' => ($adv['advances'] ?? null),
            'Recoveries' => ($adv['recoveries'] ?? null),
            'Net (ledger)' => ($net['net_ledger_result'] ?? '0.00'),
            'Net (harvest production)' => ($net['net_harvest_production_result'] ?? null),
        ];
        foreach ($pairs as $label => $val) {
            $v = $val === null ? '—' : e((string) $val);
            $rows .= '<tr><td>'.e($label).'</td><td class="amount">'.$v.'</td></tr>';
        }

        return '<table><thead><tr><th>Measure</th><th class="amount">Value</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function registerTable(array $rows, int $limit, bool $isAllocation): string
    {
        if ($rows === []) {
            return '<p class="muted">No rows in this period.</p>';
        }
        $rows = array_slice($rows, 0, $limit);
        $body = '';

        if ($isAllocation) {
            foreach ($rows as $r) {
                $body .= '<tr>'
                    .'<td>'.e((string) ($r['posting_date'] ?? '')).'</td>'
                    .'<td>'.e((string) ($r['source_type'] ?? '')).'</td>'
                    .'<td>'.e((string) ($r['allocation_type'] ?? '')).'</td>'
                    .'<td class="amount">'.e((string) ($r['amount'] ?? '')).'</td>'
                    .'</tr>';
            }
            return '<table><thead><tr><th>Date</th><th>Source</th><th>Allocation type</th><th class="amount">Amount</th></tr></thead><tbody>'.$body.'</tbody></table>';
        }

        foreach ($rows as $r) {
            $body .= '<tr>'
                .'<td>'.e((string) ($r['posting_date'] ?? '')).'</td>'
                .'<td>'.e((string) ($r['source_type'] ?? '')).'</td>'
                .'<td>'.e((string) ($r['account_code'] ?? '')).'</td>'
                .'<td class="amount">'.e((string) ($r['debit_amount'] ?? '')).'</td>'
                .'<td class="amount">'.e((string) ($r['credit_amount'] ?? '')).'</td>'
                .'</tr>';
        }

        return '<table><thead><tr><th>Date</th><th>Source</th><th>Account</th><th class="amount">Debit</th><th class="amount">Credit</th></tr></thead><tbody>'.$body.'</tbody></table>';
    }
}


<?php

namespace App\Domains\Governance\SettlementPack;

/**
 * Builds a deterministic, auditable settlement pack snapshot from posted accounting data only.
 * Pair with {@see SettlementPackRegisterQuery} for register lines; snapshot is immutable once persisted.
 */
class SettlementPackBuilder
{
    public function __construct(
        private SettlementPackRegisterQuery $registerQuery
    ) {}

    /**
     * @return array{
     *   schema_version: int,
     *   tenant_id: string,
     *   project_id: string,
     *   as_of_date: string,
     *   built_at_utc: string,
     *   content_hash: string,
     *   metrics: array{
     *     total_inflow: string,
     *     total_outflow: string,
     *     advances: string,
     *     recoveries: string,
     *     net_balance: string
     *   },
     *   by_allocation_type: array<string, string>,
     *   total_amount: string,
     *   row_count: int,
     *   register_rows: list,
     *   register_lines: list
     * }
     */
    public function build(string $tenantId, string $projectId, string $asOfDate): array
    {
        $asOfDate = $this->normalizeDate($asOfDate);

        $registerRows = $this->registerQuery->allocationRegisterRows($tenantId, $projectId, $asOfDate);
        $registerLines = $this->registerQuery->registerLines($tenantId, $projectId, $asOfDate);
        $ledgerLines = $this->registerQuery->distinctLedgerEntriesForPostingGroups($tenantId, $projectId, $asOfDate);
        $byType = $this->registerQuery->sumByAllocationType($tenantId, $projectId, $asOfDate);

        $totalAmount = '0.00';
        foreach ($registerRows as $row) {
            $totalAmount = bcadd($totalAmount, $row['amount'], 2);
        }

        $inflow = '0.00';
        $outflow = '0.00';
        foreach ($ledgerLines as $le) {
            if ($le['account_type'] === 'INCOME') {
                $net = bcsub($le['credit_amount'], $le['debit_amount'], 2);
                $inflow = bcadd($inflow, $net, 2);
            }
            if ($le['account_type'] === 'EXPENSE') {
                $net = bcsub($le['debit_amount'], $le['credit_amount'], 2);
                $outflow = bcadd($outflow, $net, 2);
            }
        }

        $advances = $byType['ADVANCE'] ?? '0.00';
        $recoveries = $byType['ADVANCE_OFFSET'] ?? '0.00';
        $netBalance = bcsub($inflow, $outflow, 2);

        $payload = [
            'schema_version' => 1,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'as_of_date' => $asOfDate,
            'metrics' => [
                'total_inflow' => $inflow,
                'total_outflow' => $outflow,
                'advances' => $advances,
                'recoveries' => $recoveries,
                'net_balance' => $netBalance,
            ],
            'by_allocation_type' => $byType,
            'total_amount' => $totalAmount,
            'row_count' => count($registerRows),
            'register_rows' => $registerRows,
            'register_lines' => $registerLines,
        ];

        $builtAt = gmdate('c');
        $contentHash = hash('sha256', $this->canonicalJson($payload));

        $payload['built_at_utc'] = $builtAt;
        $payload['content_hash'] = $contentHash;

        return $payload;
    }

    private function normalizeDate(string $asOfDate): string
    {
        return \Carbon\Carbon::parse($asOfDate)->format('Y-m-d');
    }

    private function canonicalJson(array $data): string
    {
        $this->ksortRecursive($data);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
        ksort($array);
    }
}

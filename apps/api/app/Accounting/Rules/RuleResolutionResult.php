<?php

namespace App\Accounting\Rules;

class RuleResolutionResult
{
    public function __construct(
        public readonly string $ruleVersion,
        public readonly string $ruleHash,
        public readonly array $ruleSnapshot,
        public readonly array $allocationRows,
        public readonly array $ledgerEntries,
    ) {}

    public function toArray(): array
    {
        return [
            'rule_version' => $this->ruleVersion,
            'rule_hash' => $this->ruleHash,
            'rule_snapshot' => $this->ruleSnapshot,
            'allocation_rows' => $this->allocationRows,
            'ledger_entries' => $this->ledgerEntries,
        ];
    }
}

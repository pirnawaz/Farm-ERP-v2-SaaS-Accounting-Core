<?php

namespace App\Services;

use App\Domains\Accounting\MultiCurrency\PostingFxService;
use App\Models\AllocationRow;
use App\Models\CostCenter;
use App\Models\LedgerEntry;
use App\Models\OverheadAllocationHeader;
use App\Models\OverheadAllocationLine;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\Accounting\PostValidationService;
use App\Support\TenantScoped;
use App\Services\LedgerWriteGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft overhead allocation CRUD + post: reclassifies posted CC overhead into project P&amp;L via explicit posting.
 */
class OverheadAllocationService
{
    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $operationalPostingGuard,
        private PostingDateGuard $postingDateGuard,
        private PostingIdempotencyService $postingIdempotency,
        private PostingFxService $postingFx
    ) {}

    /**
     * @param  array<int, array{project_id: string, percent?: float|null, basis_value?: float|null}>  $lines
     */
    public function createDraft(
        string $tenantId,
        string $costCenterId,
        string $sourcePostingGroupId,
        string $allocationDateYmd,
        string $method,
        float $totalAmount,
        array $lines,
        ?string $notes = null
    ): OverheadAllocationHeader {
        return DB::transaction(function () use ($tenantId, $costCenterId, $sourcePostingGroupId, $allocationDateYmd, $method, $totalAmount, $lines, $notes) {
            $this->assertSourceValid($tenantId, $costCenterId, $sourcePostingGroupId);
            $available = $this->availableAmount($tenantId, $sourcePostingGroupId, null);
            if ($totalAmount <= 0 || $totalAmount > $available + 0.02) {
                throw ValidationException::withMessages([
                    'total_amount' => ['Amount must be positive and cannot exceed available posted overhead for this bill ('.round($available, 2).').'],
                ]);
            }

            $computedLines = $this->computeLineAmounts($method, $totalAmount, $lines);
            $this->validateProjects($tenantId, $computedLines);

            $header = OverheadAllocationHeader::create([
                'tenant_id' => $tenantId,
                'cost_center_id' => $costCenterId,
                'source_posting_group_id' => $sourcePostingGroupId,
                'allocation_date' => $allocationDateYmd,
                'method' => $method,
                'notes' => $notes,
                'status' => OverheadAllocationHeader::STATUS_DRAFT,
            ]);

            foreach ($computedLines as $row) {
                OverheadAllocationLine::create([
                    'tenant_id' => $tenantId,
                    'overhead_allocation_header_id' => $header->id,
                    'project_id' => $row['project_id'],
                    'amount' => $row['amount'],
                    'percent' => $row['percent'] ?? null,
                    'basis_value' => $row['basis_value'] ?? null,
                ]);
            }

            return $header->load('lines');
        });
    }

    /**
     * @param  array<int, array{project_id: string, percent?: float|null, basis_value?: float|null}>|null  $lines
     */
    public function updateDraft(
        OverheadAllocationHeader $header,
        ?string $allocationDateYmd,
        ?string $method,
        ?float $totalAmount,
        ?array $lines,
        ?string $notes
    ): OverheadAllocationHeader {
        if ($header->status !== OverheadAllocationHeader::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => ['Only draft allocations can be updated.']]);
        }

        return DB::transaction(function () use ($header, $allocationDateYmd, $method, $totalAmount, $lines, $notes) {
            if ($allocationDateYmd !== null) {
                $header->allocation_date = $allocationDateYmd;
            }
            if ($method !== null) {
                $header->method = $method;
            }
            if ($notes !== null) {
                $header->notes = $notes;
            }
            $header->save();

            $tenantId = $header->tenant_id;
            $total = $totalAmount ?? (float) $header->lines()->sum('amount');
            $methodUse = $method ?? $header->method;
            $lineInput = $lines ?? $header->lines->map(fn ($l) => [
                'project_id' => $l->project_id,
                'percent' => $l->percent !== null ? (float) $l->percent : null,
                'basis_value' => $l->basis_value !== null ? (float) $l->basis_value : null,
            ])->all();

            if ($lines !== null || $totalAmount !== null || $method !== null) {
                $available = $this->availableAmount($tenantId, $header->source_posting_group_id, $header->id);
                if ($total <= 0 || $total > $available + 0.02) {
                    throw ValidationException::withMessages([
                        'total_amount' => ['Amount must be positive and cannot exceed available posted overhead ('.round($available, 2).').'],
                    ]);
                }
                $computedLines = $this->computeLineAmounts($methodUse, $total, $lineInput);
                $this->validateProjects($tenantId, $computedLines);
                $header->lines()->delete();
                foreach ($computedLines as $row) {
                    OverheadAllocationLine::create([
                        'tenant_id' => $tenantId,
                        'overhead_allocation_header_id' => $header->id,
                        'project_id' => $row['project_id'],
                        'amount' => $row['amount'],
                        'percent' => $row['percent'] ?? null,
                        'basis_value' => $row['basis_value'] ?? null,
                    ]);
                }
            }

            return $header->fresh(['lines']);
        });
    }

    public function deleteDraft(OverheadAllocationHeader $header): void
    {
        if ($header->status !== OverheadAllocationHeader::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => ['Only draft allocations can be deleted.']]);
        }
        DB::transaction(function () use ($header) {
            $header->lines()->delete();
            $header->delete();
        });
    }

    public function post(OverheadAllocationHeader $header, ?string $idempotencyKey = null): PostingGroup
    {
        if ($header->status !== OverheadAllocationHeader::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => ['Only draft allocations can be posted.']]);
        }
        $header->load('lines');
        if ($header->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => ['Add at least one target project line.']]);
        }

        $tenantId = $header->tenant_id;
        $this->assertSourceValid($tenantId, $header->cost_center_id, $header->source_posting_group_id);

        $total = round((float) $header->lines->sum('amount'), 2);
        $available = $this->availableAmount($tenantId, $header->source_posting_group_id, $header->id);
        if ($total <= 0 || $total > $available + 0.02) {
            throw ValidationException::withMessages([
                'total_amount' => ['Total allocated cannot exceed available posted overhead ('.round($available, 2).').'],
            ]);
        }

        return LedgerWriteGuard::scoped(self::class, function () use ($header, $tenantId, $total, $idempotencyKey) {
            return DB::transaction(function () use ($header, $tenantId, $total, $idempotencyKey) {
                $resolved = $this->postingIdempotency->resolveOrCreate(
                    $tenantId,
                    $idempotencyKey,
                    'OVERHEAD_ALLOCATION',
                    $header->id
                );
                if ($resolved['posting_group'] !== null) {
                    $pg = $resolved['posting_group'];
                    if ($header->posting_group_id !== $pg->id) {
                        $header->update(['posting_group_id' => $pg->id, 'status' => OverheadAllocationHeader::STATUS_POSTED]);
                    }

                    return $pg->load(['ledgerEntries.account', 'allocationRows']);
                }
                $effectiveKey = $resolved['effective_key'];

                $postingDateObj = Carbon::parse($header->allocation_date)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateObj));

                foreach ($header->lines as $line) {
                    $this->operationalPostingGuard->ensureCropCycleOpenForProject($line->project_id, $tenantId);
                }

                $tenant = Tenant::query()->where('id', $tenantId)->firstOrFail();
                $currencyCode = strtoupper((string) ($tenant->currency_code ?? 'GBP'));
                $fx = $this->postingFx->forPosting($tenantId, $postingDateObj, $currencyCode);

                $expenseAccount = $this->accountService->getByCode($tenantId, 'INPUTS_EXPENSE');
                $clearingAccount = $this->accountService->getByCode($tenantId, 'EXPENSE_RECLASS_CLEARING');

                $sourcePartyId = AllocationRow::query()
                    ->where('tenant_id', $tenantId)
                    ->where('posting_group_id', $header->source_posting_group_id)
                    ->whereNotNull('cost_center_id')
                    ->value('party_id');

                $cc = TenantScoped::for(CostCenter::query(), $tenantId)->findOrFail($header->cost_center_id);

                foreach ($header->lines as $line) {
                    $amt = round((float) $line->amount, 2);
                    if ($amt <= 0) {
                        continue;
                    }
                    $proj = TenantScoped::for(Project::query(), $tenantId)->findOrFail($line->project_id);
                    $cycleId = $proj->crop_cycle_id;

                    $lineKey = $this->postingIdempotency->effectiveKey(null, 'OVERHEAD_ALLOCATION', $line->id);
                    $linePg = PostingGroup::create([
                        'tenant_id' => $tenantId,
                        'crop_cycle_id' => $cycleId,
                        'source_type' => 'OVERHEAD_ALLOCATION',
                        'source_id' => $line->id,
                        'posting_date' => $postingDateObj,
                        'idempotency_key' => $lineKey,
                        'currency_code' => $fx->transactionCurrencyCode,
                        'base_currency_code' => $fx->baseCurrencyCode,
                        'fx_rate' => $fx->fxRate,
                    ]);

                    $ledgerLines = [
                        ['account_id' => $expenseAccount->id, 'debit_amount' => $amt, 'credit_amount' => 0],
                        ['account_id' => $clearingAccount->id, 'debit_amount' => 0, 'credit_amount' => $amt],
                    ];
                    $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

                    foreach ($ledgerLines as $row) {
                        $dr = (float) $row['debit_amount'];
                        $cr = (float) $row['credit_amount'];
                        LedgerEntry::create([
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $linePg->id,
                            'account_id' => $row['account_id'],
                            'debit_amount' => $dr,
                            'credit_amount' => $cr,
                            'currency_code' => $fx->transactionCurrencyCode,
                            'base_currency_code' => $fx->baseCurrencyCode,
                            'fx_rate' => $fx->fxRate,
                            'debit_amount_base' => $fx->amountInBase($dr),
                            'credit_amount_base' => $fx->amountInBase($cr),
                        ]);
                    }

                    AllocationRow::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $linePg->id,
                        'project_id' => $line->project_id,
                        'cost_center_id' => null,
                        'party_id' => $sourcePartyId,
                        'allocation_type' => 'OVERHEAD_ALLOCATION',
                        'amount' => (string) $amt,
                        'currency_code' => $fx->transactionCurrencyCode,
                        'base_currency_code' => $fx->baseCurrencyCode,
                        'fx_rate' => $fx->fxRate,
                        'amount_base' => $fx->amountInBase($amt),
                        'rule_snapshot' => [
                            'kind' => 'overhead_allocation_project_leg',
                            'project_id' => $line->project_id,
                            'project_name' => $proj->name,
                            'overhead_allocation_header_id' => $header->id,
                            'source_posting_group_id' => $header->source_posting_group_id,
                        ],
                    ]);
                }

                $cycleIds = Project::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $header->lines->pluck('project_id'))
                    ->whereNotNull('crop_cycle_id')
                    ->pluck('crop_cycle_id')
                    ->unique()
                    ->values();
                $poolCropCycleId = $cycleIds->count() === 1 ? $cycleIds->first() : null;

                $poolLedger = [
                    ['account_id' => $clearingAccount->id, 'debit_amount' => $total, 'credit_amount' => 0],
                    ['account_id' => $expenseAccount->id, 'debit_amount' => 0, 'credit_amount' => $total],
                ];
                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $poolLedger);

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $poolCropCycleId,
                    'source_type' => 'OVERHEAD_ALLOCATION',
                    'source_id' => $header->id,
                    'posting_date' => $postingDateObj,
                    'idempotency_key' => $effectiveKey,
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                ]);

                foreach ($poolLedger as $row) {
                    $dr = (float) $row['debit_amount'];
                    $cr = (float) $row['credit_amount'];
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $postingGroup->id,
                        'account_id' => $row['account_id'],
                        'debit_amount' => $dr,
                        'credit_amount' => $cr,
                        'currency_code' => $fx->transactionCurrencyCode,
                        'base_currency_code' => $fx->baseCurrencyCode,
                        'fx_rate' => $fx->fxRate,
                        'debit_amount_base' => $fx->amountInBase($dr),
                        'credit_amount_base' => $fx->amountInBase($cr),
                    ]);
                }

                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => null,
                    'cost_center_id' => $header->cost_center_id,
                    'party_id' => $sourcePartyId,
                    'allocation_type' => 'OVERHEAD_ALLOCATION',
                    'amount' => (string) $total,
                    'currency_code' => $fx->transactionCurrencyCode,
                    'base_currency_code' => $fx->baseCurrencyCode,
                    'fx_rate' => $fx->fxRate,
                    'amount_base' => $fx->amountInBase($total),
                    'rule_snapshot' => [
                        'kind' => 'overhead_allocation_cost_center_leg',
                        'cost_center_id' => $header->cost_center_id,
                        'cost_center_name' => $cc->name,
                        'overhead_allocation_header_id' => $header->id,
                        'source_posting_group_id' => $header->source_posting_group_id,
                    ],
                ]);

                $header->update([
                    'status' => OverheadAllocationHeader::STATUS_POSTED,
                    'posting_group_id' => $postingGroup->id,
                ]);

                return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }

    public function availableAmount(string $tenantId, string $sourcePostingGroupId, ?string $excludeHeaderId): float
    {
        $gross = $this->expenseNetForPostingGroup($tenantId, $sourcePostingGroupId);
        $already = OverheadAllocationLine::query()
            ->join('overhead_allocation_headers as h', 'h.id', '=', 'overhead_allocation_lines.overhead_allocation_header_id')
            ->where('h.tenant_id', $tenantId)
            ->where('h.source_posting_group_id', $sourcePostingGroupId)
            ->where('h.status', OverheadAllocationHeader::STATUS_POSTED)
            ->when($excludeHeaderId, fn ($q) => $q->where('h.id', '!=', $excludeHeaderId))
            ->sum('overhead_allocation_lines.amount');

        return round(max(0, $gross - (float) $already), 2);
    }

    private function expenseNetForPostingGroup(string $tenantId, string $postingGroupId): float
    {
        $row = DB::selectOne(
            'SELECT COALESCE(SUM(le.debit_amount - le.credit_amount), 0) AS net
             FROM ledger_entries le
             INNER JOIN accounts a ON a.id = le.account_id AND a.tenant_id = le.tenant_id
             WHERE le.tenant_id = ? AND le.posting_group_id = ? AND a.type = ?',
            [$tenantId, $postingGroupId, 'expense']
        );

        return round(max(0, (float) ($row->net ?? 0)), 2);
    }

    private function assertSourceValid(string $tenantId, string $costCenterId, string $sourcePostingGroupId): void
    {
        $pg = TenantScoped::for(PostingGroup::query(), $tenantId)->find($sourcePostingGroupId);
        if (! $pg) {
            throw ValidationException::withMessages(['source_posting_group_id' => ['Invalid posting group.']]);
        }
        if ($pg->source_type !== 'SUPPLIER_INVOICE') {
            throw ValidationException::withMessages(['source_posting_group_id' => ['Only supplier invoice (bill) postings can be allocated.']]);
        }
        $ccRows = AllocationRow::query()
            ->where('tenant_id', $tenantId)
            ->where('posting_group_id', $sourcePostingGroupId)
            ->where('cost_center_id', $costCenterId)
            ->whereNull('project_id')
            ->count();
        if ($ccRows < 1) {
            throw ValidationException::withMessages([
                'cost_center_id' => ['Source posting must have cost-center overhead allocation rows for this cost center.'],
            ]);
        }
    }

    /**
     * @param  array<int, array{project_id: string, amount: float, percent?: float|null, basis_value?: float|null}>  $lines
     */
    private function validateProjects(string $tenantId, array $lines): void
    {
        foreach ($lines as $line) {
            $this->operationalPostingGuard->ensureCropCycleOpenForProject($line['project_id'], $tenantId);
            TenantScoped::for(Project::query(), $tenantId)->findOrFail($line['project_id']);
        }
    }

    /**
     * @param  array<int, array{project_id: string, percent?: float|null, basis_value?: float|null}>  $lines
     * @return array<int, array{project_id: string, amount: float, percent?: float|null, basis_value?: float|null}>
     */
    private function computeLineAmounts(string $method, float $totalAmount, array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => ['At least one project line is required.']]);
        }

        $n = count($lines);
        if ($method === 'EQUAL_SHARE') {
            $each = round($totalAmount / $n, 2);
            $out = [];
            $sum = 0.0;
            foreach ($lines as $i => $l) {
                $amt = ($i === $n - 1) ? round($totalAmount - $sum, 2) : $each;
                $sum += $amt;
                $out[] = ['project_id' => $l['project_id'], 'amount' => $amt];
            }

            return $out;
        }
        if ($method === 'PERCENTAGE') {
            $pctSum = 0.0;
            foreach ($lines as $l) {
                $pctSum += (float) ($l['percent'] ?? 0);
            }
            if (abs($pctSum - 100) > 0.02) {
                throw ValidationException::withMessages(['lines' => ['Percentages must sum to 100.']]);
            }
            $out = [];
            $allocated = 0.0;
            foreach ($lines as $i => $l) {
                $p = (float) ($l['percent'] ?? 0);
                $amt = ($i === $n - 1)
                    ? round($totalAmount - $allocated, 2)
                    : round($totalAmount * ($p / 100), 2);
                $allocated += $amt;
                $out[] = ['project_id' => $l['project_id'], 'amount' => $amt, 'percent' => $p];
            }

            return $out;
        }
        if ($method === 'AREA') {
            $basisSum = 0.0;
            foreach ($lines as $l) {
                $basisSum += (float) ($l['basis_value'] ?? 0);
            }
            if ($basisSum <= 0) {
                throw ValidationException::withMessages(['lines' => ['AREA method requires positive basis values.']]);
            }
            $out = [];
            $allocated = 0.0;
            foreach ($lines as $i => $l) {
                $b = (float) ($l['basis_value'] ?? 0);
                $amt = ($i === $n - 1)
                    ? round($totalAmount - $allocated, 2)
                    : round($totalAmount * ($b / $basisSum), 2);
                $allocated += $amt;
                $out[] = ['project_id' => $l['project_id'], 'amount' => $amt, 'basis_value' => $b];
            }

            return $out;
        }

        throw ValidationException::withMessages(['method' => ['Invalid allocation method.']]);
    }
}

<?php

namespace App\Services\Machinery;

use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Machine;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Services\Accounting\PostValidationService;
use App\Services\LedgerWriteGuard;
use App\Services\OperationalPostingGuard;
use App\Services\PostingDateGuard;
use App\Services\SystemAccountService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Third-party / external machinery income: Dr AR / Cr MACHINERY_SERVICE_INCOME with machine attribution.
 * Not full invoicing — receivable is recorded; cash receipt uses existing payment flows.
 */
class MachineryExternalIncomePostingService
{
    private const SOURCE_TYPE = 'MACHINERY_EXTERNAL_INCOME';

    public function __construct(
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private OperationalPostingGuard $guard,
        private PostingDateGuard $postingDateGuard,
    ) {}

    /**
     * @param  array{party_id: string, memo?: string|null}  $data
     */
    public function post(
        string $tenantId,
        string $machineId,
        string $cropCycleId,
        float $amount,
        string $postingDate,
        array $data,
        ?string $idempotencyKey = null
    ): PostingGroup {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        $key = $idempotencyKey ?? 'machinery_external_income:' . $machineId . ':' . $postingDate . ':' . round($amount, 2);

        return LedgerWriteGuard::scoped(self::class, function () use ($tenantId, $machineId, $cropCycleId, $amount, $postingDate, $data, $key) {
            return DB::transaction(function () use ($tenantId, $machineId, $cropCycleId, $amount, $postingDate, $data, $key) {
                $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
                if ($existing) {
                    return $existing->load(['allocationRows', 'ledgerEntries.account']);
                }

                Machine::where('id', $machineId)->where('tenant_id', $tenantId)->firstOrFail();
                $cycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();
                Party::where('id', $data['party_id'])->where('tenant_id', $tenantId)->firstOrFail();

                $this->guard->ensureCropCycleOpen($cropCycleId, $tenantId);

                $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateObj));

                if ($cycle->start_date && $postingDateObj < $cycle->start_date->format('Y-m-d')) {
                    throw new \InvalidArgumentException('Posting date is before crop cycle start date.');
                }
                if ($cycle->end_date && $postingDateObj > $cycle->end_date->format('Y-m-d')) {
                    throw new \InvalidArgumentException('Posting date is after crop cycle end date.');
                }

                $arAccount = $this->accountService->getByCode($tenantId, 'AR');
                $incomeAccount = $this->accountService->getByCode($tenantId, 'MACHINERY_SERVICE_INCOME');

                $ledgerLines = [
                    ['account_id' => $arAccount->id, 'debit_amount' => $amount, 'credit_amount' => 0.0],
                    ['account_id' => $incomeAccount->id, 'debit_amount' => 0.0, 'credit_amount' => $amount],
                ];
                $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

                $sourceId = (string) Str::uuid();

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $cropCycleId,
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => $sourceId,
                    'posting_date' => $postingDateObj,
                    'idempotency_key' => $key,
                ]);

                foreach ($ledgerLines as $row) {
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $postingGroup->id,
                        'account_id' => $row['account_id'],
                        'debit_amount' => (string) $row['debit_amount'],
                        'credit_amount' => (string) $row['credit_amount'],
                        'currency_code' => 'GBP',
                    ]);
                }

                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => null,
                    'party_id' => $data['party_id'],
                    'allocation_type' => 'MACHINERY_EXTERNAL_INCOME',
                    'allocation_scope' => 'SHARED',
                    'amount' => (string) round($amount, 2),
                    'machine_id' => $machineId,
                    'rule_snapshot' => [
                        'source' => 'machinery_external_income',
                        'memo' => $data['memo'] ?? null,
                    ],
                ]);

                return $postingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
            });
        });
    }
}

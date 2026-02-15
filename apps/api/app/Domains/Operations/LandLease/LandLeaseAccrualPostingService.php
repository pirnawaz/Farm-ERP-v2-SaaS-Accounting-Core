<?php

namespace App\Domains\Operations\LandLease;

use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Services\OperationalPostingGuard;
use App\Services\SystemAccountService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LandLeaseAccrualPostingService
{
    public const SOURCE_TYPE = 'LAND_LEASE_ACCRUAL';

    public function __construct(
        private SystemAccountService $accountService,
        private OperationalPostingGuard $guard
    ) {}

    /**
     * Post a land lease accrual. Idempotent by (tenant_id, source_type, source_id).
     * Creates one PostingGroup, one AllocationRow (LEASE_RENT, LANDLORD_ONLY), and balanced LedgerEntries.
     *
     * @throws \Exception
     */
    public function postAccrual(
        string $accrualId,
        string $tenantId,
        string $postingDate,
        string $postedByUserId
    ): PostingGroup {
        $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');

        return DB::transaction(function () use ($accrualId, $tenantId, $postingDateObj, $postedByUserId) {
            $existing = PostingGroup::where('tenant_id', $tenantId)
                ->where('source_type', self::SOURCE_TYPE)
                ->where('source_id', $accrualId)
                ->first();

            if ($existing) {
                $accrual = LandLeaseAccrual::where('id', $accrualId)
                    ->where('tenant_id', $tenantId)
                    ->first();
                if ($accrual && $accrual->status !== LandLeaseAccrual::STATUS_POSTED) {
                    $accrual->update([
                        'status' => LandLeaseAccrual::STATUS_POSTED,
                        'posting_group_id' => $existing->id,
                        'posted_at' => now(),
                        'posted_by' => $postedByUserId,
                    ]);
                }
                return $existing->load(['allocationRows', 'ledgerEntries.account']);
            }

            $accrual = LandLeaseAccrual::where('id', $accrualId)
                ->where('tenant_id', $tenantId)
                ->where('status', LandLeaseAccrual::STATUS_DRAFT)
                ->with(['lease', 'project'])
                ->firstOrFail();

            $lease = $accrual->lease;
            if (!$lease) {
                throw new \Exception('Accrual lease not found.');
            }

            $project = $accrual->project;
            if (!$project || !$project->crop_cycle_id) {
                throw new \Exception('Project has no crop cycle; cannot post accrual.');
            }

            $this->guard->ensureCropCycleOpen($project->crop_cycle_id, $tenantId);

            $cropCycle = CropCycle::where('id', $project->crop_cycle_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            if ($cropCycle->start_date && $postingDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Posting date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $postingDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Posting date is after crop cycle end date.');
            }

            $expenseAccount = $this->accountService->getByCode($tenantId, 'EXP_LANDLORD_ONLY');
            $liabilityAccount = $this->accountService->getByCode($tenantId, 'DUE_TO_LANDLORD');

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $project->crop_cycle_id,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $accrual->id,
                'posting_date' => $postingDateObj,
            ]);

            $amount = (string) $accrual->amount;
            $ruleSnapshot = [
                'source' => 'maqada',
                'lease_id' => $lease->id,
                'lease_accrual_id' => $accrual->id,
                'land_parcel_id' => $lease->land_parcel_id,
                'landlord_party_id' => $lease->landlord_party_id,
                'period_start' => $accrual->period_start->format('Y-m-d'),
                'period_end' => $accrual->period_end->format('Y-m-d'),
                'frequency' => $lease->frequency,
                'rent_amount' => (string) $lease->rent_amount,
            ];

            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $accrual->project_id,
                'party_id' => $lease->landlord_party_id,
                'allocation_type' => 'LEASE_RENT',
                'allocation_scope' => 'LANDLORD_ONLY',
                'amount' => $amount,
                'rule_snapshot' => $ruleSnapshot,
            ]);

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $expenseAccount->id,
                'debit_amount' => $amount,
                'credit_amount' => '0.00',
                'currency_code' => 'GBP',
            ]);
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $liabilityAccount->id,
                'debit_amount' => '0.00',
                'credit_amount' => $amount,
                'currency_code' => 'GBP',
            ]);

            $totalDebits = LedgerEntry::where('posting_group_id', $postingGroup->id)->sum('debit_amount');
            $totalCredits = LedgerEntry::where('posting_group_id', $postingGroup->id)->sum('credit_amount');
            if (abs((float) $totalDebits - (float) $totalCredits) > 0.01) {
                throw new \Exception('Debits and credits do not balance.');
            }

            $accrual->update([
                'status' => LandLeaseAccrual::STATUS_POSTED,
                'posting_group_id' => $postingGroup->id,
                'posted_at' => now(),
                'posted_by' => $postedByUserId,
            ]);

            return $postingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
        });
    }
}

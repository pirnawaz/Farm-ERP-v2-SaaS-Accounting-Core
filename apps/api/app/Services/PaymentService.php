<?php

namespace App\Services;

use App\Models\LabWorker;
use App\Models\LabWorkerBalance;
use App\Models\Payment;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\LedgerEntry;
use App\Models\Settlement;
use App\Models\Project;
use App\Models\CropCycle;
use App\Models\AllocationRow;
use App\Services\SaleARService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class PaymentService
{
    public function __construct(
        private SystemAccountService $accountService,
        private SaleARService $arService
    ) {}

    /**
     * Post a payment to the accounting system.
     * 
     * This is idempotent: if a posting_group exists for tenant+idempotency_key, returns existing.
     * 
     * @param string $paymentId
     * @param string $tenantId
     * @param string $postingDate YYYY-MM-DD format
     * @param string $idempotencyKey
     * @param string|null $cropCycleId Required if payment has no settlement_id
     * @param string|null $userRole For role validation
     * @return PostingGroup
     * @throws \Exception
     */
    public function postPayment(
        string $paymentId,
        string $tenantId,
        string $postingDate,
        string $idempotencyKey,
        ?string $cropCycleId = null,
        ?string $userRole = null,
        ?string $allocationMode = null,
        ?array $manualAllocations = null
    ): PostingGroup {
        return DB::transaction(function () use ($paymentId, $tenantId, $postingDate, $idempotencyKey, $cropCycleId, $userRole, $allocationMode, $manualAllocations) {
            // Validate role
            if ($userRole && !in_array($userRole, ['accountant', 'tenant_admin'])) {
                throw new \Exception('Only accountant or tenant_admin can post payments');
            }

            // Check idempotency first
            $existingPostingGroup = PostingGroup::where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingPostingGroup) {
                // Ensure payment is linked
                $payment = Payment::where('id', $paymentId)
                    ->where('tenant_id', $tenantId)
                    ->first();
                if ($payment && $payment->status !== 'POSTED') {
                    $payment->update([
                        'status' => 'POSTED',
                        'posting_group_id' => $existingPostingGroup->id,
                        'posted_at' => now(),
                    ]);
                }
                return $existingPostingGroup->load(['ledgerEntries.account']);
            }

            // Load payment, must be DRAFT and belong to tenant
            $payment = Payment::where('id', $paymentId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'DRAFT')
                ->firstOrFail();

            // Load party to determine payable account
            $party = Party::where('id', $payment->party_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            // Determine crop_cycle_id
            $finalCropCycleId = null;
            if ($payment->settlement_id) {
                // Validate settlement
                $settlement = Settlement::where('id', $payment->settlement_id)
                    ->where('tenant_id', $tenantId)
                    ->with('project')
                    ->firstOrFail();

                // Validate party matches (check allocation_rows on settlement's posting_group)
                $allocationExists = AllocationRow::where('posting_group_id', $settlement->posting_group_id)
                    ->where('party_id', $payment->party_id)
                    ->whereIn('allocation_type', ['POOL_SHARE', 'KAMDARI'])
                    ->exists();

                if (!$allocationExists) {
                    throw new \Exception('Payment party does not match settlement allocations');
                }

                // Get crop_cycle_id from settlement's project
                $project = Project::where('id', $settlement->project_id)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();
                $finalCropCycleId = $project->crop_cycle_id;

                // Verify crop cycle is OPEN
                $cropCycle = CropCycle::where('id', $finalCropCycleId)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();

                if ($cropCycle->status !== 'OPEN') {
                    throw new \Exception('Cannot post payment to a closed crop cycle');
                }
            } else {
                // No settlement, require crop_cycle_id parameter
                if (!$cropCycleId) {
                    throw new \Exception('Crop cycle ID is required when payment is not linked to a settlement');
                }

                $finalCropCycleId = $cropCycleId;

                // Verify crop cycle exists and is OPEN
                $cropCycle = CropCycle::where('id', $finalCropCycleId)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();

                if ($cropCycle->status !== 'OPEN') {
                    throw new \Exception('Cannot post payment to a closed crop cycle');
                }
            }

            // Validate Payment OUT (non-WAGES) does not exceed outstanding payable
            $isWages = $payment->direction === 'OUT' && (string) ($payment->purpose ?? '') === 'WAGES';
            if ($payment->direction === 'OUT' && !$isWages) {
                $balanceSummary = $this->getPartyPayableBalance($payment->party_id, $tenantId, $postingDate);
                $outstandingTotal = (float) $balanceSummary['outstanding_total'];
                if ($payment->amount > $outstandingTotal) {
                    throw ValidationException::withMessages([
                        'amount' => [
                            'Payment amount exceeds outstanding payable (' . number_format($outstandingTotal, 2, '.', '') . '). Reduce amount or add advance.',
                        ],
                    ]);
                }
            }

            // Determine payable/debit account for Payment OUT
            $payableAccount = null;
            if ($isWages) {
                $payableAccount = $this->accountService->getByCode($tenantId, 'WAGES_PAYABLE');
            } else {
                $partyTypes = $party->party_types ?? [];
                if (in_array('HARI', $partyTypes)) {
                    $payableAccount = $this->accountService->getByCode($tenantId, 'PAYABLE_HARI');
                } elseif (in_array('KAMDAR', $partyTypes)) {
                    $payableAccount = $this->accountService->getByCode($tenantId, 'PAYABLE_KAMDAR');
                } elseif (in_array('LANDLORD', $partyTypes)) {
                    $payableAccount = $this->accountService->getByCode($tenantId, 'PAYABLE_LANDLORD');
                } elseif (in_array('VENDOR', $partyTypes) && $payment->direction === 'OUT') {
                    $payableAccount = $this->accountService->getByCode($tenantId, 'AP');
                } else {
                    $payableAccount = $this->accountService->getByCode($tenantId, 'PAYABLE_LANDLORD');
                }
            }

            // Get CASH account
            $cashAccount = $this->accountService->getByCode($tenantId, 'CASH');

            // Create posting group
            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $finalCropCycleId,
                'source_type' => 'ADJUSTMENT',
                'source_id' => $payment->id,
                'posting_date' => Carbon::parse($postingDate)->format('Y-m-d'),
                'idempotency_key' => $idempotencyKey,
            ]);

            // Create ledger entries
            if ($payment->direction === 'OUT') {
                // Dr payable_account, Cr CASH
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $payableAccount->id,
                    'debit_amount' => $payment->amount,
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $cashAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $payment->amount,
                    'currency_code' => 'GBP',
                ]);
            } else {
                // direction = IN - must clear receivables
                // Validate that party has receivable balance
                $financialSourceService = app(PartyFinancialSourceService::class);
                $receivableData = $financialSourceService->getPostedReceivableTotals(
                    $party->id,
                    $tenantId,
                    null, // from: all time
                    $postingDate // to: posting date
                );
                
                $receivableBalance = $receivableData['total'];
                
                if ($receivableBalance <= 0) {
                    throw new \Exception('Cannot post Payment IN: Party has no outstanding receivable balance. Create a Sale first.');
                }
                
                if ($payment->amount > $receivableBalance) {
                    throw new \Exception("Cannot post Payment IN: Amount ({$payment->amount}) exceeds outstanding receivable balance ({$receivableBalance})");
                }
                
                // Dr CASH, Cr AR (clears receivable)
                $arAccount = $this->accountService->getByCode($tenantId, 'AR');

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $cashAccount->id,
                    'debit_amount' => $payment->amount,
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);

                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $arAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => $payment->amount,
                    'currency_code' => 'GBP',
                ]);
            }

            // Verify debits == credits
            $totalDebits = LedgerEntry::where('posting_group_id', $postingGroup->id)
                ->sum('debit_amount');
            $totalCredits = LedgerEntry::where('posting_group_id', $postingGroup->id)
                ->sum('credit_amount');

            if (abs($totalDebits - $totalCredits) > 0.01) {
                throw new \Exception('Debits and credits do not balance');
            }

            // Update payment status to POSTED
            $payment->update([
                'status' => 'POSTED',
                'posting_group_id' => $postingGroup->id,
                'posted_at' => now(),
            ]);

            // For Payment OUT with purpose=WAGES: decrement lab_worker_balances
            if ($isWages) {
                $worker = LabWorker::where('party_id', $payment->party_id)->where('tenant_id', $tenantId)->first();
                if (!$worker) {
                    throw new \Exception('Party is not linked to a worker. Link the worker to a Party for wage payments.');
                }
                $balance = LabWorkerBalance::where('tenant_id', $tenantId)->where('worker_id', $worker->id)->first();
                if (!$balance) {
                    throw new \Exception('Worker has no balance record.');
                }
                $payableBalance = (float) $balance->payable_balance;
                $amount = (float) $payment->amount;
                if ($amount > $payableBalance) {
                    throw new \Exception('Wage payment amount exceeds worker payable balance.');
                }
                $balance->decrement('payable_balance', $amount);
            }

            // For Payment IN, allocate to sales
            if ($payment->direction === 'IN') {
                $mode = $allocationMode ?? 'FIFO';
                
                try {
                    $this->arService->allocatePaymentToSales(
                        $payment->id,
                        $tenantId,
                        $postingGroup->id,
                        $postingDate,
                        $mode,
                        $manualAllocations
                    );
                } catch (\Exception $e) {
                    // If allocation fails, we should rollback the entire transaction
                    // But since we're in a transaction, this will be handled automatically
                    throw $e;
                }
            }

            // Reload posting group with relationships
            return $postingGroup->fresh(['ledgerEntries.account']);
        });
    }

    /**
     * Get party payable balance summary.
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string|null $asOfDate YYYY-MM-DD format
     * @return array
     */
    public function getPartyPayableBalance(string $partyId, string $tenantId, ?string $asOfDate = null): array
    {
        $financialSourceService = app(PartyFinancialSourceService::class);
        
        $allocationData = $financialSourceService->getPostedAllocationTotals(
            $partyId,
            $tenantId,
            null,
            $asOfDate
        );
        
        $supplierAp = $financialSourceService->getSupplierPayableFromGRN(
            $partyId,
            $tenantId,
            null,
            $asOfDate
        );
        
        $paymentData = $financialSourceService->getPostedPaymentsTotals(
            $partyId,
            $tenantId,
            null,
            $asOfDate
        );

        $allocatedTotal = $allocationData['total'] + $supplierAp;
        $paidTotal = $paymentData['out'];
        $outstandingTotal = $allocatedTotal - $paidTotal;

        return [
            'allocated_total' => number_format($allocatedTotal, 2, '.', ''),
            'paid_total' => number_format($paidTotal, 2, '.', ''),
            'outstanding_total' => number_format($outstandingTotal, 2, '.', ''),
        ];
    }
}

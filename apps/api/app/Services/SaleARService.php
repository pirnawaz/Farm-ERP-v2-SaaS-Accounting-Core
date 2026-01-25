<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Payment;
use App\Models\SalePaymentAllocation;
use App\Models\PostingGroup;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SaleARService
{
    /**
     * Get outstanding amount for a specific sale as of a date.
     * 
     * @param string $saleId
     * @param string $tenantId
     * @param string|null $asOfDate YYYY-MM-DD format, null = all time
     * @return float Outstanding amount (sale.amount - allocated amount)
     */
    public function getSaleOutstanding(
        string $saleId,
        string $tenantId,
        ?string $asOfDate = null
    ): float {
        $sale = Sale::where('id', $saleId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'POSTED')
            ->firstOrFail();

        $query = SalePaymentAllocation::where('tenant_id', $tenantId)
            ->where('sale_id', $saleId);

        if ($asOfDate) {
            $query->where('allocation_date', '<=', $asOfDate);
        }

        $allocatedAmount = (float) $query->sum('amount');
        $outstanding = (float) $sale->amount - $allocatedAmount;

        return max(0, $outstanding);
    }

    /**
     * Get open sales for a buyer party with outstanding amounts.
     * 
     * @param string $buyerPartyId
     * @param string $tenantId
     * @param string|null $asOfDate YYYY-MM-DD format, null = all time
     * @return array Array of sales with outstanding info
     */
    public function getBuyerOpenSales(
        string $buyerPartyId,
        string $tenantId,
        ?string $asOfDate = null
    ): array {
        // Get all posted sales for buyer
        $sales = Sale::where('tenant_id', $tenantId)
            ->where('buyer_party_id', $buyerPartyId)
            ->where('status', 'POSTED')
            ->orderBy('posting_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        $openSales = [];

        foreach ($sales as $sale) {
            $outstanding = $this->getSaleOutstanding($sale->id, $tenantId, $asOfDate);
            
            if ($outstanding > 0) {
                // Get allocated amount
                $allocationQuery = SalePaymentAllocation::where('tenant_id', $tenantId)
                    ->where('sale_id', $sale->id);
                
                if ($asOfDate) {
                    $allocationQuery->where('allocation_date', '<=', $asOfDate);
                }
                
                $allocatedAmount = (float) $allocationQuery->sum('amount');

                $openSales[] = [
                    'sale_id' => $sale->id,
                    'sale_no' => $sale->sale_no,
                    'posting_date' => $sale->posting_date->format('Y-m-d'),
                    'sale_date' => $sale->sale_date ? $sale->sale_date->format('Y-m-d') : $sale->posting_date->format('Y-m-d'),
                    'due_date' => $sale->due_date ? $sale->due_date->format('Y-m-d') : $sale->posting_date->format('Y-m-d'),
                    'amount' => number_format((float) $sale->amount, 2, '.', ''),
                    'allocated' => number_format($allocatedAmount, 2, '.', ''),
                    'outstanding' => number_format($outstanding, 2, '.', ''),
                ];
            }
        }

        return $openSales;
    }

    /**
     * Allocate a payment to sales (FIFO or manual).
     * 
     * @param string $paymentId
     * @param string $tenantId
     * @param string $postingGroupId
     * @param string $allocationDate YYYY-MM-DD format
     * @param string $allocationMode 'FIFO' or 'MANUAL'
     * @param array|null $manualAllocations [{sale_id, amount}] only if MANUAL
     * @return array Created allocations
     * @throws \Exception
     */
    public function allocatePaymentToSales(
        string $paymentId,
        string $tenantId,
        string $postingGroupId,
        string $allocationDate,
        string $allocationMode = 'FIFO',
        ?array $manualAllocations = null
    ): array {
        return DB::transaction(function () use ($paymentId, $tenantId, $postingGroupId, $allocationDate, $allocationMode, $manualAllocations) {
            // Load payment
            $payment = Payment::where('id', $paymentId)
                ->where('tenant_id', $tenantId)
                ->where('direction', 'IN')
                ->where('status', 'POSTED')
                ->firstOrFail();

            // Check if already allocated (idempotency)
            $existingAllocations = SalePaymentAllocation::where('tenant_id', $tenantId)
                ->where('payment_id', $paymentId)
                ->where('posting_group_id', $postingGroupId)
                ->get();

            if ($existingAllocations->count() > 0) {
                // Already allocated, return existing
                return $existingAllocations->map(function ($alloc) {
                    return [
                        'id' => $alloc->id,
                        'sale_id' => $alloc->sale_id,
                        'amount' => $alloc->amount,
                    ];
                })->toArray();
            }

            $buyerPartyId = $payment->party_id;
            $paymentAmount = (float) $payment->amount;
            $allocations = [];

            if ($allocationMode === 'FIFO') {
                // Get open sales ordered FIFO (query directly for accurate outstanding)
                $sales = Sale::where('tenant_id', $tenantId)
                    ->where('buyer_party_id', $buyerPartyId)
                    ->where('status', 'POSTED')
                    ->orderBy('posting_date', 'asc')
                    ->orderBy('created_at', 'asc')
                    ->get();
                
                $remainingAmount = $paymentAmount;
                
                foreach ($sales as $sale) {
                    if ($remainingAmount <= 0) {
                        break;
                    }
                    
                    $saleOutstanding = $this->getSaleOutstanding($sale->id, $tenantId, $allocationDate);
                    
                    if ($saleOutstanding <= 0) {
                        continue; // Skip fully paid sales
                    }
                    
                    $allocationAmount = min($remainingAmount, $saleOutstanding);
                    
                    if ($allocationAmount > 0) {
                        $allocation = SalePaymentAllocation::create([
                            'tenant_id' => $tenantId,
                            'sale_id' => $sale->id,
                            'payment_id' => $paymentId,
                            'posting_group_id' => $postingGroupId,
                            'allocation_date' => $allocationDate,
                            'amount' => $allocationAmount,
                        ]);
                        
                        $allocations[] = [
                            'id' => $allocation->id,
                            'sale_id' => $allocation->sale_id,
                            'amount' => $allocation->amount,
                        ];
                        
                        $remainingAmount -= $allocationAmount;
                    }
                }
                
                if ($remainingAmount > 0.01) {
                    throw new \Exception("Payment amount ({$paymentAmount}) exceeds total outstanding receivables. Remaining unallocated: {$remainingAmount}");
                }
            } else {
                // MANUAL mode
                if (!$manualAllocations || !is_array($manualAllocations)) {
                    throw new \Exception('Manual allocations array is required for MANUAL mode');
                }

                $totalAllocated = 0;
                
                foreach ($manualAllocations as $manualAlloc) {
                    if (!isset($manualAlloc['sale_id']) || !isset($manualAlloc['amount'])) {
                        throw new \Exception('Each manual allocation must have sale_id and amount');
                    }
                    
                    $saleId = $manualAlloc['sale_id'];
                    $allocAmount = (float) $manualAlloc['amount'];
                    
                    if ($allocAmount <= 0) {
                        throw new \Exception('Allocation amount must be greater than 0');
                    }
                    
                    // Verify sale belongs to same buyer and tenant
                    $sale = Sale::where('id', $saleId)
                        ->where('tenant_id', $tenantId)
                        ->where('buyer_party_id', $buyerPartyId)
                        ->where('status', 'POSTED')
                        ->firstOrFail();
                    
                    // Check outstanding
                    $outstanding = $this->getSaleOutstanding($saleId, $tenantId, $allocationDate);
                    
                    if ($allocAmount > $outstanding) {
                        throw new \Exception("Allocation amount ({$allocAmount}) exceeds outstanding ({$outstanding}) for sale {$saleId}");
                    }
                    
                    $allocation = SalePaymentAllocation::create([
                        'tenant_id' => $tenantId,
                        'sale_id' => $saleId,
                        'payment_id' => $paymentId,
                        'posting_group_id' => $postingGroupId,
                        'allocation_date' => $allocationDate,
                        'amount' => $allocAmount,
                    ]);
                    
                    $allocations[] = [
                        'id' => $allocation->id,
                        'sale_id' => $allocation->sale_id,
                        'amount' => $allocation->amount,
                    ];
                    
                    $totalAllocated += $allocAmount;
                }
                
                // Verify total matches payment amount
                if (abs($totalAllocated - $paymentAmount) > 0.01) {
                    throw new \Exception("Total allocated amount ({$totalAllocated}) must equal payment amount ({$paymentAmount})");
                }
            }

            return $allocations;
        });
    }

    /**
     * Get allocation preview for a payment (before posting).
     * 
     * @param string $buyerPartyId
     * @param string $tenantId
     * @param float $paymentAmount
     * @param string $postingDate YYYY-MM-DD format
     * @return array Preview data
     */
    public function getAllocationPreview(
        string $buyerPartyId,
        string $tenantId,
        float $paymentAmount,
        string $postingDate
    ): array {
        // Get receivable totals
        $financialSourceService = app(PartyFinancialSourceService::class);
        $receivableData = $financialSourceService->getPostedReceivableTotals(
            $buyerPartyId,
            $tenantId,
            null,
            $postingDate
        );

        $totalReceivable = $receivableData['total'];
        $openSales = $this->getBuyerOpenSales($buyerPartyId, $tenantId, $postingDate);

        // Calculate FIFO suggested allocations
        $suggestedAllocations = [];
        $remainingAmount = $paymentAmount;

        foreach ($openSales as $openSale) {
            if ($remainingAmount <= 0) {
                break;
            }

            $saleOutstanding = (float) $openSale['outstanding'];
            $allocationAmount = min($remainingAmount, $saleOutstanding);

            if ($allocationAmount > 0) {
                $suggestedAllocations[] = [
                    'sale_id' => $openSale['sale_id'],
                    'sale_no' => $openSale['sale_no'],
                    'posting_date' => $openSale['posting_date'],
                    'due_date' => $openSale['due_date'],
                    'outstanding' => $openSale['outstanding'],
                    'amount' => number_format($allocationAmount, 2, '.', ''),
                ];

                $remainingAmount -= $allocationAmount;
            }
        }

        return [
            'total_receivable' => number_format($totalReceivable, 2, '.', ''),
            'payment_amount' => number_format($paymentAmount, 2, '.', ''),
            'open_sales' => $openSales,
            'suggested_allocations' => $suggestedAllocations,
            'unallocated_amount' => number_format(max(0, $remainingAmount), 2, '.', ''),
        ];
    }
}

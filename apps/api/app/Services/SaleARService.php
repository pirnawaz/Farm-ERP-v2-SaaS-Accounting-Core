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
            ->where('sale_id', $saleId)
            ->where(function ($q) {
                $q->where('status', SalePaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            });

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
        // Open sales = posted invoices only (credit notes are instruments, not receivables to collect)
        $sales = Sale::where('tenant_id', $tenantId)
            ->where('buyer_party_id', $buyerPartyId)
            ->where('status', 'POSTED')
            ->whereNull('reversal_posting_group_id')
            ->where(function ($q) {
                $q->where('sale_kind', Sale::SALE_KIND_INVOICE)->orWhereNull('sale_kind');
            })
            ->orderBy('posting_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        $openSales = [];

        foreach ($sales as $sale) {
            $outstanding = $this->getSaleOutstanding($sale->id, $tenantId, $asOfDate);
            
            if ($outstanding > 0) {
                // Get allocated amount (ACTIVE only)
                $allocationQuery = SalePaymentAllocation::where('tenant_id', $tenantId)
                    ->where('sale_id', $sale->id)
                    ->where(function ($q) {
                        $q->where('status', SalePaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
                    });
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
                // Get open invoices only (credit notes are instruments, not receivables to pay)
                $sales = Sale::where('tenant_id', $tenantId)
                    ->where('buyer_party_id', $buyerPartyId)
                    ->where('status', 'POSTED')
                    ->where(function ($q) {
                        $q->where('sale_kind', Sale::SALE_KIND_INVOICE)->orWhereNull('sale_kind');
                    })
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
                            'status' => SalePaymentAllocation::STATUS_ACTIVE,
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
                    
                    // Verify sale is an invoice belonging to same buyer and tenant
                    $sale = Sale::where('id', $saleId)
                        ->where('tenant_id', $tenantId)
                        ->where('buyer_party_id', $buyerPartyId)
                        ->where('status', 'POSTED')
                        ->where(function ($q) {
                            $q->where('sale_kind', Sale::SALE_KIND_INVOICE)->orWhereNull('sale_kind');
                        })
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
                        'status' => SalePaymentAllocation::STATUS_ACTIVE,
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

    /**
     * Preview apply payment (IN) to open sales: FIFO or MANUAL mode.
     * Payment must be posted, not reversed, direction IN.
     *
     * @return array{payment_summary: array, open_sales: array, suggested_allocations: array}
     */
    public function previewApplyPaymentToSales(string $tenantId, string $paymentId, string $mode = 'FIFO'): array
    {
        $payment = Payment::where('id', $paymentId)
            ->where('tenant_id', $tenantId)
            ->posted()
            ->notReversed()
            ->where('direction', 'IN')
            ->firstOrFail();

        $appliedAmount = (float) SalePaymentAllocation::where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->where(function ($q) {
                $q->where('status', SalePaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            })
            ->sum('amount');
        $paymentAmount = (float) $payment->amount;
        $unappliedAmount = $paymentAmount - $appliedAmount;

        $openSales = $this->getOpenSalesForPayment($tenantId, $payment->party_id);
        $suggestedAllocations = [];

        if ($mode === 'FIFO' && $unappliedAmount > 0) {
            $remaining = $unappliedAmount;
            foreach ($openSales as $sale) {
                if ($remaining <= 0) {
                    break;
                }
                $open = (float) $sale['open_balance'];
                $alloc = min($remaining, $open);
                if ($alloc > 0) {
                    $suggestedAllocations[] = ['sale_id' => $sale['sale_id'], 'amount' => number_format($alloc, 2, '.', '')];
                    $remaining -= $alloc;
                }
            }
        }

        return [
            'payment_summary' => [
                'id' => $payment->id,
                'amount' => number_format($paymentAmount, 2, '.', ''),
                'unapplied_amount' => number_format(max(0, $unappliedAmount), 2, '.', ''),
            ],
            'open_sales' => $openSales,
            'suggested_allocations' => $suggestedAllocations,
        ];
    }

    /**
     * Get open sales for a party (posted, not reversed) with total, already_applied, open_balance.
     */
    private function getOpenSalesForPayment(string $tenantId, string $buyerPartyId): array
    {
        // Only invoices; credit notes are applied as instruments, not listed as open sales to pay
        $sales = Sale::where('tenant_id', $tenantId)
            ->where('buyer_party_id', $buyerPartyId)
            ->where('status', 'POSTED')
            ->whereNull('reversal_posting_group_id')
            ->where(function ($q) {
                $q->where('sale_kind', Sale::SALE_KIND_INVOICE)->orWhereNull('sale_kind');
            })
            ->orderBy('posting_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        $list = [];
        foreach ($sales as $sale) {
            $allocated = (float) SalePaymentAllocation::where('tenant_id', $tenantId)
                ->where('sale_id', $sale->id)
                ->where(function ($q) {
                    $q->where('status', SalePaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
                })
                ->sum('amount');
            $total = (float) $sale->amount;
            $openBalance = max(0, $total - $allocated);
            if ($openBalance <= 0) {
                continue;
            }
            $list[] = [
                'sale_id' => $sale->id,
                'sale_no' => $sale->sale_no,
                'posting_date' => $sale->posting_date->format('Y-m-d'),
                'due_date' => $sale->due_date ? $sale->due_date->format('Y-m-d') : $sale->posting_date->format('Y-m-d'),
                'total' => number_format($total, 2, '.', ''),
                'already_applied' => number_format($allocated, 2, '.', ''),
                'open_balance' => number_format($openBalance, 2, '.', ''),
            ];
        }
        return $list;
    }

    /**
     * Apply payment to sales: create ACTIVE allocations. Sum(allocations) <= unapplied; each <= sale open_balance.
     *
     * @param array|null $allocations For MANUAL: [['sale_id' => ..., 'amount' => ...], ...]
     * @param string|null $allocationDate YYYY-MM-DD, default today
     * @param string|null $createdBy User id for audit
     * @return array Updated payment allocation summary
     */
    public function applyPaymentToSales(
        string $tenantId,
        string $paymentId,
        string $mode,
        ?array $allocations = null,
        ?string $allocationDate = null,
        ?string $createdBy = null
    ): array {
        $allocationDate = $allocationDate ?: Carbon::today()->format('Y-m-d');

        return DB::transaction(function () use ($tenantId, $paymentId, $mode, $allocations, $allocationDate, $createdBy) {
            $payment = Payment::where('id', $paymentId)
                ->where('tenant_id', $tenantId)
                ->posted()
                ->notReversed()
                ->where('direction', 'IN')
                ->firstOrFail();

            if (!$payment->posting_group_id) {
                throw new \InvalidArgumentException('Payment must have a posting_group_id to apply to sales.');
            }

            $appliedAmount = (float) SalePaymentAllocation::where('tenant_id', $tenantId)
                ->where('payment_id', $paymentId)
                ->where(function ($q) {
                    $q->where('status', SalePaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
                })
                ->sum('amount');
            $paymentAmount = (float) $payment->amount;
            $unappliedAmount = $paymentAmount - $appliedAmount;

            if ($unappliedAmount <= 0) {
                throw new \InvalidArgumentException('Payment has no unapplied amount to allocate.');
            }

            $toCreate = [];
            if ($mode === 'FIFO') {
                $openSales = $this->getOpenSalesForPayment($tenantId, $payment->party_id);
                $remaining = $unappliedAmount;
                foreach ($openSales as $sale) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $open = (float) $sale['open_balance'];
                    $alloc = min($remaining, $open);
                    if ($alloc > 0) {
                        $toCreate[] = ['sale_id' => $sale['sale_id'], 'amount' => $alloc];
                        $remaining -= $alloc;
                    }
                }
            } else {
                if (!$allocations || !is_array($allocations)) {
                    throw new \InvalidArgumentException('allocations array is required for MANUAL mode.');
                }
                $totalRequested = 0;
                foreach ($allocations as $a) {
                    $saleId = $a['sale_id'] ?? null;
                    $amount = isset($a['amount']) ? (float) $a['amount'] : 0;
                    if (!$saleId || $amount <= 0) {
                        continue;
                    }
                    $openBalance = $this->getSaleOutstanding($saleId, $tenantId, $allocationDate);
                    if ($amount > $openBalance) {
                        throw new \InvalidArgumentException("Allocation amount ({$amount}) exceeds open balance ({$openBalance}) for sale {$saleId}.");
                    }
                    $sale = Sale::where('id', $saleId)->where('tenant_id', $tenantId)->where('buyer_party_id', $payment->party_id)->where('status', 'POSTED')->whereNull('reversal_posting_group_id')->firstOrFail();
                    $toCreate[] = ['sale_id' => $saleId, 'amount' => $amount];
                    $totalRequested += $amount;
                }
                if ($totalRequested > $unappliedAmount) {
                    throw new \InvalidArgumentException("Total allocation amount ({$totalRequested}) exceeds unapplied amount ({$unappliedAmount}).");
                }
            }

            foreach ($toCreate as $item) {
                SalePaymentAllocation::create([
                    'tenant_id' => $tenantId,
                    'sale_id' => $item['sale_id'],
                    'payment_id' => $paymentId,
                    'posting_group_id' => $payment->posting_group_id,
                    'allocation_date' => $allocationDate,
                    'amount' => $item['amount'],
                    'status' => SalePaymentAllocation::STATUS_ACTIVE,
                    'created_by' => $createdBy,
                ]);
            }

            return $this->getPaymentAllocationSummary($tenantId, $paymentId);
        });
    }

    /**
     * Unapply (void) allocations for a payment. Optionally by sale_id. Only ACTIVE can be voided.
     *
     * @param string|null $saleId If null, void all ACTIVE allocations for this payment
     * @param string|null $voidedBy User id for audit
     * @return array Updated payment allocation summary
     */
    public function unapplyPaymentFromSales(string $tenantId, string $paymentId, ?string $saleId = null, ?string $voidedBy = null): array
    {
        return DB::transaction(function () use ($tenantId, $paymentId, $saleId, $voidedBy) {
            $payment = Payment::where('id', $paymentId)
                ->where('tenant_id', $tenantId)
                ->posted()
                ->notReversed()
                ->where('direction', 'IN')
                ->firstOrFail();

            $query = SalePaymentAllocation::where('tenant_id', $tenantId)
                ->where('payment_id', $paymentId)
                ->where('status', SalePaymentAllocation::STATUS_ACTIVE);
            if ($saleId !== null) {
                $query->where('sale_id', $saleId);
            }
            $query->update([
                'status' => SalePaymentAllocation::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $voidedBy,
            ]);

            return $this->getPaymentAllocationSummary($tenantId, $paymentId);
        });
    }

    /**
     * Return payment allocation summary: id, amount, unapplied_amount, allocations (ACTIVE only).
     */
    public function getPaymentAllocationSummary(string $tenantId, string $paymentId): array
    {
        $payment = Payment::where('id', $paymentId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $appliedAmount = (float) SalePaymentAllocation::where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->where(function ($q) {
                $q->where('status', SalePaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            })
            ->sum('amount');
        $paymentAmount = (float) $payment->amount;
        $unappliedAmount = $paymentAmount - $appliedAmount;

        $allocations = SalePaymentAllocation::where('tenant_id', $tenantId)
            ->where('payment_id', $paymentId)
            ->where(function ($q) {
                $q->where('status', SalePaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            })
            ->with('sale:id,sale_no,amount,posting_date')
            ->orderBy('allocation_date')
            ->orderBy('id')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'sale_id' => $a->sale_id,
                'sale_no' => $a->sale?->sale_no,
                'amount' => number_format((float) $a->amount, 2, '.', ''),
                'allocation_date' => $a->allocation_date->format('Y-m-d'),
            ])
            ->values()
            ->all();

        return [
            'payment_id' => $payment->id,
            'amount' => number_format($paymentAmount, 2, '.', ''),
            'unapplied_amount' => number_format(max(0, $unappliedAmount), 2, '.', ''),
            'allocations' => $allocations,
        ];
    }

    /**
     * AR Aging report: open invoice balances per customer in buckets (current, 1_30, 31_60, 61_90, 90_plus).
     * Only ACTIVE (or null status) allocations count; only posted, non-reversed sales with buyer_party_id; only open_balance > 0.
     *
     * @param string $tenantId
     * @param string $asOfDate YYYY-MM-DD
     * @return array { as_of, customers: [{ party_id, party_name, totals, invoices }], grand_totals }
     */
    public function getARAging(string $tenantId, string $asOfDate): array
    {
        $asOf = Carbon::parse($asOfDate)->startOfDay();

        // Invoice-centric aging: only INVOICE (or legacy null) sales; credit notes are not aged as open invoices
        $sales = Sale::where('tenant_id', $tenantId)
            ->where('status', 'POSTED')
            ->whereNull('reversal_posting_group_id')
            ->whereNotNull('buyer_party_id')
            ->where(function ($q) {
                $q->where('sale_kind', Sale::SALE_KIND_INVOICE)->orWhereNull('sale_kind');
            })
            ->with('buyerParty:id,name')
            ->get();

        $appliedBySale = SalePaymentAllocation::where('tenant_id', $tenantId)
            ->where('allocation_date', '<=', $asOfDate)
            ->where(function ($q) {
                $q->where('status', SalePaymentAllocation::STATUS_ACTIVE)->orWhereNull('status');
            })
            ->groupBy('sale_id')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as applied_sum')
            ->pluck('applied_sum', 'sale_id')
            ->all();

        $partyRows = [];
        $grandTotals = [
            'current' => 0.0,
            '1_30' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            '90_plus' => 0.0,
            'total' => 0.0,
        ];

        foreach ($sales as $sale) {
            $amount = (float) $sale->amount;
            $applied = (float) ($appliedBySale[$sale->id] ?? 0);
            $openBalance = $amount - $applied;
            if ($openBalance <= 0) {
                continue;
            }

            $dueDate = $sale->due_date ? $sale->due_date->copy()->startOfDay() : ($sale->sale_date ? $sale->sale_date->copy()->startOfDay() : $sale->posting_date->copy()->startOfDay());
            // Positive = overdue (as_of after due_date), negative or zero = current
            $daysOverdue = (int) $dueDate->diffInDays($asOf, false);

            $bucket = $this->bucketForDaysOverdue($daysOverdue);
            $partyId = $sale->buyer_party_id;
            $partyName = $sale->buyerParty ? $sale->buyerParty->name : '';

            if (!isset($partyRows[$partyId])) {
                $partyRows[$partyId] = [
                    'party_id' => $partyId,
                    'party_name' => $partyName,
                    'totals' => [
                        'current' => 0.0,
                        '1_30' => 0.0,
                        '31_60' => 0.0,
                        '61_90' => 0.0,
                        '90_plus' => 0.0,
                        'total' => 0.0,
                    ],
                    'invoices' => [],
                ];
            }

            $partyRows[$partyId]['totals'][$bucket] += $openBalance;
            $partyRows[$partyId]['totals']['total'] += $openBalance;
            $partyRows[$partyId]['invoices'][] = [
                'sale_id' => $sale->id,
                'sale_no' => $sale->sale_no,
                'sale_date' => $sale->sale_date ? $sale->sale_date->format('Y-m-d') : ($sale->posting_date ? $sale->posting_date->format('Y-m-d') : ''),
                'due_date' => $dueDate->format('Y-m-d'),
                'amount' => number_format($amount, 2, '.', ''),
                'applied' => number_format($applied, 2, '.', ''),
                'open_balance' => number_format($openBalance, 2, '.', ''),
                'bucket' => $bucket,
            ];
            $grandTotals[$bucket] += $openBalance;
            $grandTotals['total'] += $openBalance;
        }

        $customers = array_values(array_map(function ($row) {
            $row['totals'] = array_map(fn ($v) => number_format((float) $v, 2, '.', ''), $row['totals']);
            return $row;
        }, $partyRows));

        $grandTotalsFormatted = array_map(fn ($v) => number_format((float) $v, 2, '.', ''), $grandTotals);

        return [
            'as_of' => $asOfDate,
            'customers' => $customers,
            'grand_totals' => $grandTotalsFormatted,
        ];
    }

    private function bucketForDaysOverdue(int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'current';
        }
        if ($daysOverdue <= 30) {
            return '1_30';
        }
        if ($daysOverdue <= 60) {
            return '31_60';
        }
        if ($daysOverdue <= 90) {
            return '61_90';
        }
        return '90_plus';
    }
}

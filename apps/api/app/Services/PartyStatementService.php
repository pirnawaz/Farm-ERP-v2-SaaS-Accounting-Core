<?php

namespace App\Services;

use App\Models\Party;
use App\Models\AllocationRow;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SalePaymentAllocation;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\CropCycle;
use App\Models\SettlementOffset;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PartyStatementService
{
    /**
     * Get party statement with breakdown and line items.
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string|null $from YYYY-MM-DD format
     * @param string|null $to YYYY-MM-DD format
     * @param string $groupBy 'cycle' or 'project'
     * @return array
     */
    public function getStatement(
        string $partyId,
        string $tenantId,
        ?string $from = null,
        ?string $to = null,
        string $groupBy = 'cycle'
    ): array {
        // Validate party exists
        $party = Party::where('id', $partyId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Determine date range
        if (!$from || !$to) {
            // Default to current crop cycle or last 365 days
            $openCycle = CropCycle::where('tenant_id', $tenantId)
                ->where('status', 'OPEN')
                ->orderBy('start_date', 'desc')
                ->first();
            
            if ($openCycle) {
                $from = $from ?: $openCycle->start_date->format('Y-m-d');
                $to = $to ?: ($openCycle->end_date ? $openCycle->end_date->format('Y-m-d') : Carbon::today()->format('Y-m-d'));
            } else {
                $to = $to ?: Carbon::today()->format('Y-m-d');
                $from = $from ?: Carbon::parse($to)->subYear()->format('Y-m-d');
            }
        }

        $fromDate = Carbon::parse($from);
        $toDate = Carbon::parse($to);

        // Use shared service for single source of truth
        $financialSourceService = app(PartyFinancialSourceService::class);
        
        // Get allocation totals from shared service for consistency
        $allocationData = $financialSourceService->getPostedAllocationTotals($partyId, $tenantId, $from, $to);
        
        // Get allocations with project/crop cycle info (need joins for breakdown)
        // Include POOL_SHARE, KAMDARI, and ADVANCE_OFFSET allocation types from SETTLEMENT (active posting groups only)
        $allocationQuery = AllocationRow::where('allocation_rows.tenant_id', $tenantId)
            ->where('allocation_rows.party_id', $partyId)
            ->whereIn('allocation_rows.allocation_type', ['POOL_SHARE', 'KAMDARI', 'ADVANCE_OFFSET'])
            ->join('posting_groups', 'allocation_rows.posting_group_id', '=', 'posting_groups.id')
            ->where('posting_groups.source_type', 'SETTLEMENT');
        PostingGroup::applyActiveOn($allocationQuery, 'posting_groups');
        $allocations = $allocationQuery->whereBetween('posting_groups.posting_date', [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')])
            ->join('projects', 'allocation_rows.project_id', '=', 'projects.id')
            ->join('crop_cycles', 'projects.crop_cycle_id', '=', 'crop_cycles.id')
            ->select(
                'allocation_rows.*',
                'posting_groups.posting_date',
                'posting_groups.id as posting_group_id',
                'projects.id as project_id',
                'projects.name as project_name',
                'crop_cycles.id as crop_cycle_id',
                'crop_cycles.name as crop_cycle_name'
            )
            ->orderBy('posting_groups.posting_date', 'asc')
            ->get();

        // Get inventory issue allocations
        $inventoryAllocationData = $financialSourceService->getPostedInventoryIssueAllocations($partyId, $tenantId, $from, $to);
        $inventoryAllocations = $inventoryAllocationData['allocations']->load(['postingGroup', 'project.cropCycle']);
        
        // Add project/crop cycle info to inventory allocations
        foreach ($inventoryAllocations as $alloc) {
            if ($alloc->project) {
                $alloc->project_name = $alloc->project->name;
                $alloc->crop_cycle_id = $alloc->project->crop_cycle_id;
                if ($alloc->project->cropCycle) {
                    $alloc->crop_cycle_name = $alloc->project->cropCycle->name;
                }
            }
            if ($alloc->postingGroup) {
                $alloc->posting_date = $alloc->postingGroup->posting_date;
            }
        }

        // Get settlement offsets for this party in date range
        $offsets = SettlementOffset::where('settlement_offsets.tenant_id', $tenantId)
            ->where('settlement_offsets.party_id', $partyId)
            ->whereBetween('settlement_offsets.posting_date', [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')])
            ->join('settlements', 'settlement_offsets.settlement_id', '=', 'settlements.id')
            ->join('projects', 'settlements.project_id', '=', 'projects.id')
            ->join('crop_cycles', 'projects.crop_cycle_id', '=', 'crop_cycles.id')
            ->select(
                'settlement_offsets.*',
                'settlements.id as settlement_id',
                'projects.id as project_id',
                'projects.name as project_name',
                'crop_cycles.id as crop_cycle_id',
                'crop_cycles.name as crop_cycle_name'
            )
            ->orderBy('settlement_offsets.posting_date', 'asc')
            ->get();

        // Get payments (posted only) - use shared service for totals
        $paymentData = $financialSourceService->getPostedPaymentsTotals($partyId, $tenantId, $from, $to);
        $payments = $paymentData['payments']->load('saleAllocations.sale');

        // Get advances (posted only) - use shared service for totals
        $advanceData = $financialSourceService->getPostedAdvancesTotals($partyId, $tenantId, $from, $to);
        $advances = $advanceData['advances'];

        // Get sales (posted only) - use shared service for totals
        $salesData = $financialSourceService->getPostedSalesTotals($partyId, $tenantId, $from, $to);
        $sales = $salesData['sales']->load(['project', 'cropCycle']);

        // Calculate summary totals (using shared service data for consistency)
        $totalAllocationsIncreasing = $allocationData['total'];
        $totalInventoryIssueAllocations = $inventoryAllocationData['total'];
        $totalAllocationsDecreasing = 0; // For Phase 4, no decreasing allocations
        $totalPaymentsOut = $paymentData['out'];
        $totalPaymentsIn = $paymentData['in'];
        $unassignedPaymentsTotal = $paymentData['unassigned_out'];
        $totalAdvancesOut = $advanceData['out'];
        $totalAdvancesIn = $advanceData['in'];
        $totalSales = $salesData['total'];

        // Calculate closing balances (from all time, not just date range) - use shared service
        $allTimeAllocationData = $financialSourceService->getPostedAllocationTotals(
            $partyId,
            $tenantId,
            null, // from: all time
            $toDate->format('Y-m-d') // to: end date
        );
        
        $allTimePaymentData = $financialSourceService->getPostedPaymentsTotals(
            $partyId,
            $tenantId,
            null, // from: all time
            $toDate->format('Y-m-d') // to: end date
        );
        
        $allTimeAdvanceData = $financialSourceService->getPostedAdvancesTotals(
            $partyId,
            $tenantId,
            null, // from: all time
            $toDate->format('Y-m-d') // to: end date
        );

        $allTimeAllocations = $allTimeAllocationData['total'];
        $allTimePaymentsOut = $allTimePaymentData['out'];
        $allTimePaymentsIn = $allTimePaymentData['in'];
        $allTimeAdvancesOut = $allTimeAdvanceData['out'];
        $allTimeAdvancesIn = $allTimeAdvanceData['in'];
        
        $allTimeSalesData = $financialSourceService->getPostedSalesTotals(
            $partyId,
            $tenantId,
            null, // from: all time
            $toDate->format('Y-m-d') // to: end date
        );
        $allTimeSales = $allTimeSalesData['total'];

        // Include supplier AP (from GRN) in closing payable - same source of truth as getPartyPayableBalance
        $allTimeSupplierAp = $financialSourceService->getSupplierPayableFromGRN(
            $partyId,
            $tenantId,
            null, // from: all time
            $toDate->format('Y-m-d') // to: end date
        );
        $closingBalancePayable = max(0, $allTimeAllocations + $allTimeSupplierAp - $allTimePaymentsOut);
        $closingBalanceReceivable = max(0, $allTimeSales - $allTimePaymentsIn); // Sales - Payments IN
        $closingBalanceAdvance = max(0, $allTimeAdvancesOut - $allTimeAdvancesIn);

        // Build grouped breakdown
        $groupedBreakdown = $this->buildGroupedBreakdown($allocations, $payments, $offsets, $sales, $groupBy);

        // Build line items (chronological)
        $lineItems = $this->buildLineItems($allocations, $payments, $advances, $offsets, $sales, $inventoryAllocations);

        return [
            'party_id' => $partyId,
            'from' => $from,
            'to' => $to,
            'summary' => [
                'total_allocations_increasing_balance' => number_format((float) $totalAllocationsIncreasing, 2, '.', ''),
                'supplier_payable_total' => number_format((float) $allTimeSupplierAp, 2, '.', ''),
                'total_inventory_issue_allocations' => number_format((float) $totalInventoryIssueAllocations, 2, '.', ''),
                'total_allocations_decreasing_balance' => number_format((float) $totalAllocationsDecreasing, 2, '.', ''),
                'total_payments_out' => number_format((float) $totalPaymentsOut, 2, '.', ''),
                'total_payments_in' => number_format((float) $totalPaymentsIn, 2, '.', ''),
                'unassigned_payments_total' => number_format((float) $unassignedPaymentsTotal, 2, '.', ''),
                'total_advances_out' => number_format((float) $totalAdvancesOut, 2, '.', ''),
                'total_advances_in' => number_format((float) $totalAdvancesIn, 2, '.', ''),
                'total_sales' => number_format((float) $totalSales, 2, '.', ''),
                'closing_balance_payable' => number_format((float) $closingBalancePayable, 2, '.', ''),
                'closing_balance_receivable' => number_format((float) $closingBalanceReceivable, 2, '.', ''),
                'closing_balance_advance' => number_format((float) $closingBalanceAdvance, 2, '.', ''),
            ],
            'grouped_breakdown' => $groupedBreakdown,
            'line_items' => $lineItems,
        ];
    }

    /**
     * Build grouped breakdown by cycle or project.
     */
    private function buildGroupedBreakdown($allocations, $payments, $offsets, $sales, string $groupBy): array
    {
        $groups = [];

        if ($groupBy === 'cycle') {
            // Group by crop cycle
            $cycleGroups = [];
            
            foreach ($allocations as $allocation) {
                // Only count POOL_SHARE and KAMDARI for increasing allocations
                // ADVANCE_OFFSET reduces payable, so we'll handle it separately
                if (!in_array($allocation->allocation_type, ['POOL_SHARE', 'KAMDARI'])) {
                    continue;
                }
                
                $cycleId = $allocation->crop_cycle_id;
                if (!isset($cycleGroups[$cycleId])) {
                    $cycleGroups[$cycleId] = [
                        'crop_cycle_id' => $cycleId,
                        'crop_cycle_name' => $allocation->crop_cycle_name,
                        'total_allocations' => 0,
                        'total_offsets' => 0,
                        'total_payments_out' => 0,
                        'total_payments_in' => 0,
                        'total_sales' => 0,
                        'projects' => [],
                    ];
                }
                $cycleGroups[$cycleId]['total_allocations'] += (float) $allocation->amount;

                // Group by project within cycle
                $projectId = $allocation->project_id;
                if (!isset($cycleGroups[$cycleId]['projects'][$projectId])) {
                    $cycleGroups[$cycleId]['projects'][$projectId] = [
                        'project_id' => $projectId,
                        'project_name' => $allocation->project_name,
                        'total_allocations' => 0,
                        'total_offsets' => 0,
                        'total_payments_out' => 0,
                        'total_payments_in' => 0,
                        'total_sales' => 0,
                    ];
                }
                $cycleGroups[$cycleId]['projects'][$projectId]['total_allocations'] += (float) $allocation->amount;
            }

            // Add offsets to groups
            foreach ($offsets as $offset) {
                $cycleId = $offset->crop_cycle_id;
                if (isset($cycleGroups[$cycleId])) {
                    $cycleGroups[$cycleId]['total_offsets'] += (float) $offset->offset_amount;
                    $projectId = $offset->project_id;
                    if (isset($cycleGroups[$cycleId]['projects'][$projectId])) {
                        $cycleGroups[$cycleId]['projects'][$projectId]['total_offsets'] += (float) $offset->offset_amount;
                    }
                }
            }

            // Add payments to groups
            foreach ($payments as $payment) {
                // Find which cycle/project this payment relates to
                // For Phase 4, we'll attribute to the most recent allocation if settlement_id exists
                if ($payment->settlement_id) {
                    $settlement = \App\Models\Settlement::where('id', $payment->settlement_id)->first();
                    if ($settlement) {
                        $project = Project::where('id', $settlement->project_id)->first();
                        if ($project) {
                            $cycleId = $project->crop_cycle_id;
                            if (isset($cycleGroups[$cycleId])) {
                                if ($payment->direction === 'OUT') {
                                    $cycleGroups[$cycleId]['total_payments_out'] += (float) $payment->amount;
                                    if (isset($cycleGroups[$cycleId]['projects'][$project->id])) {
                                        $cycleGroups[$cycleId]['projects'][$project->id]['total_payments_out'] += (float) $payment->amount;
                                    }
                                } else {
                                    $cycleGroups[$cycleId]['total_payments_in'] += (float) $payment->amount;
                                    if (isset($cycleGroups[$cycleId]['projects'][$project->id])) {
                                        $cycleGroups[$cycleId]['projects'][$project->id]['total_payments_in'] += (float) $payment->amount;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Add sales to groups
            foreach ($sales as $sale) {
                if ($sale->project_id) {
                    $project = Project::where('id', $sale->project_id)->first();
                    if ($project) {
                        $cycleId = $project->crop_cycle_id;
                        if (isset($cycleGroups[$cycleId])) {
                            $cycleGroups[$cycleId]['total_sales'] += (float) $sale->amount;
                            $projectId = $project->id;
                            if (isset($cycleGroups[$cycleId]['projects'][$projectId])) {
                                $cycleGroups[$cycleId]['projects'][$projectId]['total_sales'] += (float) $sale->amount;
                            }
                        }
                    }
                }
            }

            // Convert to array format and calculate net
            foreach ($cycleGroups as $cycleId => $cycleData) {
                $netOutstanding = $cycleData['total_allocations'] - $cycleData['total_offsets'] - $cycleData['total_payments_out'] + $cycleData['total_payments_in'];
                $projects = [];
                foreach ($cycleData['projects'] as $projectId => $projectData) {
                    $projectNet = $projectData['total_allocations'] - $projectData['total_offsets'] - $projectData['total_payments_out'] + $projectData['total_payments_in'];
                    $projects[] = [
                        'project_id' => $projectId,
                        'project_name' => $projectData['project_name'],
                        'total_allocations' => number_format((float) $projectData['total_allocations'], 2, '.', ''),
                        'total_offsets' => number_format((float) $projectData['total_offsets'], 2, '.', ''),
                        'total_payments_out' => number_format((float) $projectData['total_payments_out'], 2, '.', ''),
                        'total_payments_in' => number_format((float) $projectData['total_payments_in'], 2, '.', ''),
                        'net_outstanding' => number_format((float) $projectNet, 2, '.', ''),
                    ];
                }
                
                $groups[] = [
                    'crop_cycle_id' => $cycleId,
                    'crop_cycle_name' => $cycleData['crop_cycle_name'],
                    'total_allocations' => number_format((float) $cycleData['total_allocations'], 2, '.', ''),
                    'total_offsets' => number_format((float) $cycleData['total_offsets'], 2, '.', ''),
                    'total_payments_out' => number_format((float) $cycleData['total_payments_out'], 2, '.', ''),
                    'total_payments_in' => number_format((float) $cycleData['total_payments_in'], 2, '.', ''),
                    'net_outstanding' => number_format((float) $netOutstanding, 2, '.', ''),
                    'projects' => $projects,
                ];
            }
        } else {
            // Group by project
            $projectGroups = [];
            
            foreach ($allocations as $allocation) {
                // Only count POOL_SHARE and KAMDARI for increasing allocations
                if (!in_array($allocation->allocation_type, ['POOL_SHARE', 'KAMDARI'])) {
                    continue;
                }
                
                $projectId = $allocation->project_id;
                if (!isset($projectGroups[$projectId])) {
                    $projectGroups[$projectId] = [
                        'project_id' => $projectId,
                        'project_name' => $allocation->project_name,
                        'crop_cycle_id' => $allocation->crop_cycle_id,
                        'crop_cycle_name' => $allocation->crop_cycle_name,
                        'total_allocations' => 0,
                        'total_offsets' => 0,
                        'total_payments_out' => 0,
                        'total_payments_in' => 0,
                        'total_sales' => 0,
                    ];
                }
                $projectGroups[$projectId]['total_allocations'] += (float) $allocation->amount;
            }

            // Add offsets
            foreach ($offsets as $offset) {
                $projectId = $offset->project_id;
                if (isset($projectGroups[$projectId])) {
                    $projectGroups[$projectId]['total_offsets'] += (float) $offset->offset_amount;
                }
            }

            // Add payments
            foreach ($payments as $payment) {
                if ($payment->settlement_id) {
                    $settlement = \App\Models\Settlement::where('id', $payment->settlement_id)->first();
                    if ($settlement && isset($projectGroups[$settlement->project_id])) {
                        if ($payment->direction === 'OUT') {
                            $projectGroups[$settlement->project_id]['total_payments_out'] += (float) $payment->amount;
                        } else {
                            $projectGroups[$settlement->project_id]['total_payments_in'] += (float) $payment->amount;
                        }
                    }
                }
            }

            // Add sales to groups
            foreach ($sales as $sale) {
                if ($sale->project_id && isset($projectGroups[$sale->project_id])) {
                    $projectGroups[$sale->project_id]['total_sales'] += (float) $sale->amount;
                }
            }

            // Convert to array format
            foreach ($projectGroups as $projectId => $projectData) {
                $netOutstanding = $projectData['total_allocations'] - $projectData['total_offsets'] - $projectData['total_payments_out'] + $projectData['total_payments_in'];
                $groups[] = [
                    'project_id' => $projectId,
                    'project_name' => $projectData['project_name'],
                    'crop_cycle_id' => $projectData['crop_cycle_id'],
                    'crop_cycle_name' => $projectData['crop_cycle_name'],
                    'total_allocations' => number_format((float) $projectData['total_allocations'], 2, '.', ''),
                    'total_offsets' => number_format((float) $projectData['total_offsets'], 2, '.', ''),
                    'total_payments_out' => number_format((float) $projectData['total_payments_out'], 2, '.', ''),
                    'total_payments_in' => number_format((float) $projectData['total_payments_in'], 2, '.', ''),
                    'net_outstanding' => number_format((float) $netOutstanding, 2, '.', ''),
                ];
            }
        }

        return $groups;
    }

    /**
     * Build chronological line items.
     */
    private function buildLineItems($allocations, $payments, $advances, $offsets, $sales, $inventoryAllocations = null): array
    {
        $lines = [];

        // Add allocation lines (POOL_SHARE, KAMDARI only - offsets handled separately)
        foreach ($allocations as $allocation) {
            if ($allocation->allocation_type === 'ADVANCE_OFFSET') {
                continue; // Skip offset allocations, we'll add them as SETTLEMENT_OFFSET type
            }
            
            $lines[] = [
                'date' => $allocation->posting_date,
                'type' => 'ALLOCATION',
                'reference' => $allocation->posting_group_id,
                'description' => "Settlement allocation - {$allocation->project_name} ({$allocation->allocation_type})",
                'amount' => number_format((float) $allocation->amount, 2, '.', ''),
                'direction' => '+',
            ];
        }

        // Add inventory issue allocation lines
        if ($inventoryAllocations) {
            foreach ($inventoryAllocations as $allocation) {
                $projectName = $allocation->project_name ?? 'Unknown Project';
                $allocationMode = $allocation->rule_snapshot['allocation_mode'] ?? 'UNKNOWN';
                $postingDate = $allocation->posting_date ?? ($allocation->postingGroup->posting_date ?? '');
                
                $lines[] = [
                    'date' => $postingDate,
                    'type' => 'INVENTORY_ISSUE_ALLOCATION',
                    'reference' => $allocation->posting_group_id,
                    'description' => "Inventory issue cost - {$projectName} ({$allocationMode})",
                    'amount' => number_format((float) $allocation->amount, 2, '.', ''),
                    'direction' => '+', // Increases payable (party owes for their share of inventory cost)
                ];
            }
        }

        // Add offset lines
        foreach ($offsets as $offset) {
            $lines[] = [
                'date' => $offset->posting_date->format('Y-m-d'),
                'type' => 'SETTLEMENT_OFFSET',
                'reference' => $offset->settlement_id,
                'description' => "Settlement offset - {$offset->project_name} (Advance applied to settlement)",
                'amount' => number_format((float) $offset->offset_amount, 2, '.', ''),
                'direction' => '-', // Reduces payable
            ];
        }

        // Add payment lines
        foreach ($payments as $payment) {
            $lines[] = [
                'date' => $payment->payment_date->format('Y-m-d'),
                'type' => 'PAYMENT',
                'reference' => $payment->id,
                'description' => "Payment {$payment->direction} - {$payment->method}",
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'direction' => $payment->direction === 'OUT' ? '-' : '+',
            ];

            // For Payment IN, add allocation lines if they exist
            if ($payment->direction === 'IN' && $payment->posting_group_id) {
                $allocations = SalePaymentAllocation::where('tenant_id', $tenantId)
                    ->where('payment_id', $payment->id)
                    ->where('posting_group_id', $payment->posting_group_id)
                    ->with('sale')
                    ->get();

                foreach ($allocations as $allocation) {
                    $sale = $allocation->sale;
                    $saleRef = $sale->sale_no ?: "Sale #{$sale->id}";
                    $lines[] = [
                        'date' => $allocation->allocation_date->format('Y-m-d'),
                        'type' => 'AR_ALLOCATION',
                        'reference' => $allocation->sale_id,
                        'description' => "Applied to {$saleRef}",
                        'amount' => number_format((float) $allocation->amount, 2, '.', ''),
                        'direction' => '-', // Reduces receivable
                    ];
                }
            }
        }

        // Add advance lines
        foreach ($advances as $advance) {
            $typeLabel = match($advance->type) {
                'HARI_ADVANCE' => 'Hari Advance',
                'VENDOR_ADVANCE' => 'Vendor Advance',
                'LOAN' => 'Loan',
                default => $advance->type,
            };
            $lines[] = [
                'date' => $advance->posting_date->format('Y-m-d'),
                'type' => 'ADVANCE',
                'reference' => $advance->id,
                'description' => "Advance {$advance->direction} - {$typeLabel} ({$advance->method})",
                'amount' => number_format((float) $advance->amount, 2, '.', ''),
                'direction' => $advance->direction === 'OUT' ? '+' : '-', // OUT = they owe us (asset increases), IN = repayment (asset decreases)
            ];
        }

        // Add sale lines
        foreach ($sales as $sale) {
            $projectName = $sale->project ? $sale->project->name : 'Unassigned';
            $lines[] = [
                'date' => $sale->posting_date->format('Y-m-d'),
                'type' => 'SALE',
                'reference' => $sale->id,
                'description' => "Sale - {$projectName}",
                'amount' => number_format((float) $sale->amount, 2, '.', ''),
                'direction' => '+', // Increases receivable
            ];
        }

        // Sort by date
        usort($lines, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        // Calculate running balance
        $runningBalance = 0;
        foreach ($lines as &$line) {
            if ($line['direction'] === '+') {
                $runningBalance += (float) $line['amount'];
            } else {
                $runningBalance -= (float) $line['amount'];
            }
            $line['running_balance'] = number_format($runningBalance, 2, '.', '');
        }

        return $lines;
    }

    /**
     * Get AR (Accounts Receivable) statement for a party: Sales (invoices) and Payments IN (receipts) only.
     * Ledger-driven; uses same active semantics as Module 1 (posted + not reversed).
     * No landlord/settlement-only lines.
     *
     * @param string $tenantId
     * @param string $partyId
     * @param string $from YYYY-MM-DD
     * @param string $to YYYY-MM-DD
     * @return array{party: array, period: array{from: string, to: string}, lines: array, totals: array}
     */
    public function getARStatement(string $tenantId, string $partyId, string $from, string $to): array
    {
        $party = Party::where('id', $partyId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Opening balance: sales (posted, not reversed) before from minus payments IN (posted, not reversed) before from
        $openingSales = (float) Sale::where('tenant_id', $tenantId)
            ->where('buyer_party_id', $partyId)
            ->where('status', 'POSTED')
            ->whereNull('reversal_posting_group_id')
            ->where('posting_date', '<', $from)
            ->sum('amount');
        $openingPaymentsIn = (float) Payment::where('tenant_id', $tenantId)
            ->where('party_id', $partyId)
            ->posted()
            ->notReversed()
            ->where('direction', 'IN')
            ->where('payment_date', '<', $from)
            ->sum('amount');
        $openingBalance = $openingSales - $openingPaymentsIn;

        // Sales in period (posted, not reversed) - AR-relevant only
        $sales = Sale::where('tenant_id', $tenantId)
            ->where('buyer_party_id', $partyId)
            ->where('status', 'POSTED')
            ->whereNull('reversal_posting_group_id')
            ->whereBetween('posting_date', [$from, $to])
            ->orderBy('posting_date')
            ->orderBy('id')
            ->get();

        // Payments IN in period (posted, not reversed)
        $paymentsIn = Payment::where('tenant_id', $tenantId)
            ->where('party_id', $partyId)
            ->posted()
            ->notReversed()
            ->where('direction', 'IN')
            ->whereBetween('payment_date', [$from, $to])
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        $rawLines = [];
        foreach ($sales as $sale) {
            $date = $sale->posting_date instanceof \Carbon\Carbon
                ? $sale->posting_date->format('Y-m-d')
                : $sale->posting_date;
            $desc = $sale->sale_no ? "Sale {$sale->sale_no}" : "Sale #{$sale->id}";
            $amount = (float) $sale->amount;
            $rawLines[] = [
                'posting_date' => $date,
                'sort_key' => $date . '_SALE_' . $sale->id,
                'description' => $desc,
                'source_type' => 'SALE',
                'source_id' => $sale->id,
                'posting_group_id' => $sale->posting_group_id,
                'debit' => '0.00',
                'credit' => number_format($amount, 2, '.', ''),
                'net' => number_format($amount, 2, '.', ''),
                'debit_val' => 0,
                'credit_val' => $amount,
            ];
        }
        foreach ($paymentsIn as $payment) {
            $date = $payment->payment_date->format('Y-m-d');
            $rawLines[] = [
                'posting_date' => $date,
                'sort_key' => $date . '_PAYMENT_' . $payment->id,
                'description' => 'Payment IN - ' . ($payment->method ?? ''),
                'source_type' => 'PAYMENT',
                'source_id' => $payment->id,
                'posting_group_id' => $payment->posting_group_id,
                'debit' => number_format((float) $payment->amount, 2, '.', ''),
                'credit' => '0.00',
                'net' => number_format(-(float) $payment->amount, 2, '.', ''),
                'debit_val' => (float) $payment->amount,
                'credit_val' => 0,
            ];
        }
        usort($rawLines, fn ($a, $b) => strcmp($a['sort_key'], $b['sort_key']));

        $runningBalance = $openingBalance;
        $debitTotal = 0.0;
        $creditTotal = 0.0;
        $lines = [];
        foreach ($rawLines as $raw) {
            $runningBalance += $raw['credit_val'] - $raw['debit_val'];
            $debitTotal += $raw['debit_val'];
            $creditTotal += $raw['credit_val'];
            $lines[] = [
                'posting_date' => $raw['posting_date'],
                'description' => $raw['description'],
                'source_type' => $raw['source_type'],
                'source_id' => $raw['source_id'],
                'posting_group_id' => $raw['posting_group_id'],
                'debit' => $raw['debit'],
                'credit' => $raw['credit'],
                'net' => $raw['net'],
                'running_balance' => number_format($runningBalance, 2, '.', ''),
            ];
        }

        return [
            'party' => $party->only(['id', 'name', 'party_types']),
            'period' => ['from' => $from, 'to' => $to],
            'lines' => $lines,
            'totals' => [
                'debit_total' => number_format($debitTotal, 2, '.', ''),
                'credit_total' => number_format($creditTotal, 2, '.', ''),
                'opening_balance' => number_format($openingBalance, 2, '.', ''),
                'closing_balance' => number_format($runningBalance, 2, '.', ''),
            ],
        ];
    }
}

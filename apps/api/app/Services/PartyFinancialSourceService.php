<?php

namespace App\Services;

use App\Models\AllocationRow;
use App\Models\Payment;
use App\Models\Advance;
use App\Models\Sale;
use App\Models\PostingGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PartyFinancialSourceService
{
    /**
     * Get posted allocation totals for a party.
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string|null $from YYYY-MM-DD format
     * @param string|null $to YYYY-MM-DD format
     * @return array ['total' => float, 'allocations' => Collection]
     */
    public function getPostedAllocationTotals(
        string $partyId,
        string $tenantId,
        ?string $from = null,
        ?string $to = null
    ): array {
        $query = AllocationRow::where('allocation_rows.tenant_id', $tenantId)
            ->where('allocation_rows.party_id', $partyId)
            ->whereIn('allocation_rows.allocation_type', ['POOL_SHARE', 'KAMDARI'])
            ->join('posting_groups', 'allocation_rows.posting_group_id', '=', 'posting_groups.id')
            ->where('posting_groups.source_type', 'SETTLEMENT');

        if ($from) {
            $query->where('posting_groups.posting_date', '>=', $from);
        }
        if ($to) {
            $query->where('posting_groups.posting_date', '<=', $to);
        }

        $allocations = $query->select(
            'allocation_rows.id',
            'allocation_rows.tenant_id',
            'allocation_rows.party_id',
            'allocation_rows.posting_group_id',
            'allocation_rows.project_id',
            'allocation_rows.allocation_type',
            'allocation_rows.amount',
            'posting_groups.posting_date',
            'posting_groups.id as posting_group_id'
        )
        ->get();

        $total = (float) $allocations->sum('amount');

        return [
            'total' => $total,
            'allocations' => $allocations,
        ];
    }

    /**
     * Get supplier payable from GRN (AllocationRow SUPPLIER_AP) for a party.
     * Excludes reversed GRNs (PG has been reversed).
     *
     * @param string $partyId
     * @param string $tenantId
     * @param string|null $from YYYY-MM-DD
     * @param string|null $to YYYY-MM-DD
     * @return float
     */
    public function getSupplierPayableFromGRN(
        string $partyId,
        string $tenantId,
        ?string $from = null,
        ?string $to = null
    ): float {
        $sub = AllocationRow::where('allocation_rows.tenant_id', $tenantId)
            ->where('allocation_rows.party_id', $partyId)
            ->where('allocation_rows.allocation_type', 'SUPPLIER_AP')
            ->join('posting_groups', 'allocation_rows.posting_group_id', '=', 'posting_groups.id')
            ->where('posting_groups.source_type', 'INVENTORY_GRN')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('posting_groups as rev')
                    ->whereColumn('rev.reversal_of_posting_group_id', 'posting_groups.id');
            });

        if ($from) {
            $sub->where('posting_groups.posting_date', '>=', $from);
        }
        if ($to) {
            $sub->where('posting_groups.posting_date', '<=', $to);
        }

        return (float) $sub->sum('allocation_rows.amount');
    }

    /**
     * Get posted payment totals for a party.
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string|null $from YYYY-MM-DD format
     * @param string|null $to YYYY-MM-DD format
     * @return array ['out' => float, 'in' => float, 'unassigned_out' => float, 'payments' => Collection]
     */
    public function getPostedPaymentsTotals(
        string $partyId,
        string $tenantId,
        ?string $from = null,
        ?string $to = null
    ): array {
        $query = Payment::where('tenant_id', $tenantId)
            ->where('party_id', $partyId)
            ->where('status', 'POSTED');

        if ($from) {
            $query->where('payment_date', '>=', $from);
        }
        if ($to) {
            $query->where('payment_date', '<=', $to);
        }

        $payments = $query->get();

        $outTotal = (float) $payments->where('direction', 'OUT')->sum('amount');
        $inTotal = (float) $payments->where('direction', 'IN')->sum('amount');
        
        // Unassigned payments (OUT direction, no settlement_id)
        $unassignedOut = (float) $payments
            ->where('direction', 'OUT')
            ->where('settlement_id', null)
            ->sum('amount');

        return [
            'out' => $outTotal,
            'in' => $inTotal,
            'unassigned_out' => $unassignedOut,
            'payments' => $payments,
        ];
    }

    /**
     * Get posted statement lines (allocations + payments) for a party.
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string|null $from YYYY-MM-DD format
     * @param string|null $to YYYY-MM-DD format
     * @return Collection
     */
    public function getPostedStatementLines(
        string $partyId,
        string $tenantId,
        ?string $from = null,
        ?string $to = null
    ): Collection {
        // Get allocations with project/crop cycle info
        $allocationQuery = AllocationRow::where('allocation_rows.tenant_id', $tenantId)
            ->where('allocation_rows.party_id', $partyId)
            ->whereIn('allocation_rows.allocation_type', ['POOL_SHARE', 'KAMDARI'])
            ->join('posting_groups', 'allocation_rows.posting_group_id', '=', 'posting_groups.id')
            ->where('posting_groups.source_type', 'SETTLEMENT')
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
            );

        if ($from) {
            $allocationQuery->where('posting_groups.posting_date', '>=', $from);
        }
        if ($to) {
            $allocationQuery->where('posting_groups.posting_date', '<=', $to);
        }

        $allocations = $allocationQuery->get();

        // Get payments
        $paymentQuery = Payment::where('tenant_id', $tenantId)
            ->where('party_id', $partyId)
            ->where('status', 'POSTED');

        if ($from) {
            $paymentQuery->where('payment_date', '>=', $from);
        }
        if ($to) {
            $paymentQuery->where('payment_date', '<=', $to);
        }

        $payments = $paymentQuery->get();

        // Combine into a single collection with metadata
        $lines = collect();
        
        foreach ($allocations as $allocation) {
            $lines->push([
                'type' => 'allocation',
                'date' => $allocation->posting_date,
                'data' => $allocation,
            ]);
        }

        foreach ($payments as $payment) {
            $lines->push([
                'type' => 'payment',
                'date' => $payment->payment_date->format('Y-m-d'),
                'data' => $payment,
            ]);
        }

        // Get advances
        $advanceQuery = Advance::where('tenant_id', $tenantId)
            ->where('party_id', $partyId)
            ->where('status', 'POSTED');

        if ($from) {
            $advanceQuery->where('posting_date', '>=', $from);
        }
        if ($to) {
            $advanceQuery->where('posting_date', '<=', $to);
        }

        $advances = $advanceQuery->get();

        foreach ($advances as $advance) {
            $lines->push([
                'type' => 'advance',
                'date' => $advance->posting_date->format('Y-m-d'),
                'data' => $advance,
            ]);
        }

        return $lines->sortBy('date');
    }

    /**
     * Get posted advance totals for a party.
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string|null $from YYYY-MM-DD format
     * @param string|null $to YYYY-MM-DD format
     * @return array ['out' => float, 'in' => float, 'advances' => Collection]
     */
    public function getPostedAdvancesTotals(
        string $partyId,
        string $tenantId,
        ?string $from = null,
        ?string $to = null
    ): array {
        $query = Advance::where('tenant_id', $tenantId)
            ->where('party_id', $partyId)
            ->where('status', 'POSTED');

        if ($from) {
            $query->where('posting_date', '>=', $from);
        }
        if ($to) {
            $query->where('posting_date', '<=', $to);
        }

        $advances = $query->get();

        $outTotal = (float) $advances->where('direction', 'OUT')->sum('amount');
        $inTotal = (float) $advances->where('direction', 'IN')->sum('amount');

        return [
            'out' => $outTotal,
            'in' => $inTotal,
            'advances' => $advances,
        ];
    }

    /**
     * Get outstanding advance balance for a party as of a specific date.
     * This calculates: (sum of OUT advances) - (sum of IN advances/repayments) up to the given date.
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string $asOfDate YYYY-MM-DD format - advances with posting_date <= this date are included
     * @return float Outstanding advance balance (always >= 0)
     */
    public function getOutstandingAdvanceBalance(
        string $partyId,
        string $tenantId,
        string $asOfDate
    ): float {
        $advanceData = $this->getPostedAdvancesTotals(
            $partyId,
            $tenantId,
            null, // from: all time
            $asOfDate // to: as of date
        );

        $outTotal = $advanceData['out'];
        $inTotal = $advanceData['in'];
        
        // Outstanding = disbursed (OUT) - repaid (IN)
        // Ensure it's never negative
        return max(0, $outTotal - $inTotal);
    }

    /**
     * Get posted sales totals for a party (buyer).
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string|null $from YYYY-MM-DD format
     * @param string|null $to YYYY-MM-DD format
     * @return array ['total' => float, 'sales' => Collection]
     */
    public function getPostedSalesTotals(
        string $partyId,
        string $tenantId,
        ?string $from = null,
        ?string $to = null
    ): array {
        $query = Sale::where('tenant_id', $tenantId)
            ->where('buyer_party_id', $partyId)
            ->where('status', 'POSTED');

        if ($from) {
            $query->where('posting_date', '>=', $from);
        }
        if ($to) {
            $query->where('posting_date', '<=', $to);
        }

        $sales = $query->get();

        $total = (float) $sales->sum('amount');

        return [
            'total' => $total,
            'sales' => $sales,
        ];
    }

    /**
     * Get posted receivable totals for a party (buyer).
     * Receivable balance = posted sales - posted payment IN
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string|null $from YYYY-MM-DD format
     * @param string|null $to YYYY-MM-DD format
     * @return array ['total' => float, 'sales' => Collection, 'payments_in' => Collection]
     */
    public function getPostedReceivableTotals(
        string $partyId,
        string $tenantId,
        ?string $from = null,
        ?string $to = null
    ): array {
        $salesData = $this->getPostedSalesTotals($partyId, $tenantId, $from, $to);
        $paymentData = $this->getPostedPaymentsTotals($partyId, $tenantId, $from, $to);

        $salesTotal = $salesData['total'];
        $paymentsInTotal = $paymentData['in'];
        
        // Receivable = sales - payments IN (what buyer owes us)
        $receivableTotal = max(0, $salesTotal - $paymentsInTotal);

        return [
            'total' => $receivableTotal,
            'sales_total' => $salesTotal,
            'payments_in_total' => $paymentsInTotal,
            'sales' => $salesData['sales'],
            'payments_in' => $paymentData['payments']->where('direction', 'IN'),
        ];
    }

    /**
     * Get posted inventory issue allocation totals for a party.
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string|null $from YYYY-MM-DD format
     * @param string|null $to YYYY-MM-DD format
     * @return array ['total' => float, 'allocations' => Collection]
     */
    public function getPostedInventoryIssueAllocations(
        string $partyId,
        string $tenantId,
        ?string $from = null,
        ?string $to = null
    ): array {
        $query = AllocationRow::where('allocation_rows.tenant_id', $tenantId)
            ->where('allocation_rows.party_id', $partyId)
            ->join('posting_groups', 'allocation_rows.posting_group_id', '=', 'posting_groups.id')
            ->where('posting_groups.source_type', 'INVENTORY_ISSUE')
            ->whereRaw("allocation_rows.rule_snapshot->>'cost_type' = 'INVENTORY_INPUT'");

        if ($from) {
            $query->where('posting_groups.posting_date', '>=', $from);
        }
        if ($to) {
            $query->where('posting_groups.posting_date', '<=', $to);
        }

        $allocations = $query->select(
            'allocation_rows.id',
            'allocation_rows.tenant_id',
            'allocation_rows.party_id',
            'allocation_rows.posting_group_id',
            'allocation_rows.project_id',
            'allocation_rows.allocation_type',
            'allocation_rows.amount',
            'allocation_rows.rule_snapshot',
            'posting_groups.posting_date',
            'posting_groups.id as posting_group_id'
        )
        ->get();

        $total = (float) $allocations->sum('amount');

        return [
            'total' => $total,
            'allocations' => $allocations,
        ];
    }
}

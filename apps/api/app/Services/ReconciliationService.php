<?php

namespace App\Services;

use App\Models\OperationalTransaction;
use App\Models\PostingGroup;
use App\Models\LedgerEntry;
use App\Models\AllocationRow;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReconciliationService
{
    public function __construct(
        private SystemAccountService $accountService,
        private PartyFinancialSourceService $financialSourceService
    ) {}

    /**
     * Reconcile project settlement totals against OperationalTransactions.
     * Returns detailed breakdown of OT sums by type and classification.
     * 
     * This is read-only and replicates SettlementService::previewSettlement logic
     * but returns detailed reconciliation components.
     * 
     * Note: Uses same date logic as SettlementService (posting_date <= to_date, no from_date filter)
     * to ensure exact reconciliation.
     * 
     * @param string $projectId
     * @param string $tenantId
     * @param string $fromDate YYYY-MM-DD format (for reference, not used in query to match SettlementService)
     * @param string $toDate YYYY-MM-DD format (used as up_to_date, matching SettlementService)
     * @return array
     */
    public function reconcileProjectSettlementVsOT(
        string $projectId,
        string $tenantId,
        string $fromDate,
        string $toDate
    ): array {
        $toDateObj = Carbon::parse($toDate);

        // Get all posted transactions for this project up to to_date.
        // "Posted" = OT has status POSTED and posting_group_id set, with that PG's posting_date <= to_date.
        // This matches SettlementService::previewSettlement logic exactly.
        // Exclude reversed posting groups (those that have a reversal).
        $postedTransactions = OperationalTransaction::where('operational_transactions.tenant_id', $tenantId)
            ->where('operational_transactions.project_id', $projectId)
            ->where('operational_transactions.status', 'POSTED')
            ->whereNotNull('operational_transactions.posting_group_id')
            ->join('posting_groups', 'operational_transactions.posting_group_id', '=', 'posting_groups.id')
            ->where('posting_groups.posting_date', '<=', $toDateObj->format('Y-m-d'))
            // Exclude posting groups that have been reversed
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('posting_groups as rev')
                    ->whereColumn('rev.reversal_of_posting_group_id', 'posting_groups.id');
            })
            ->select('operational_transactions.*')
            ->get();

        // Calculate totals by type and classification
        $otRevenue = $postedTransactions->where('type', 'INCOME')->sum('amount');
        $otExpensesTotal = $postedTransactions->where('type', 'EXPENSE')->sum('amount');
        
        $otSharedCosts = $postedTransactions
            ->where('type', 'EXPENSE')
            ->where('classification', 'SHARED')
            ->sum('amount');

        $otLandlordOnlyCosts = $postedTransactions
            ->where('type', 'EXPENSE')
            ->where('classification', 'LANDLORD_ONLY')
            ->sum('amount');

        $otHariOnlyCosts = $postedTransactions
            ->where('type', 'EXPENSE')
            ->where('classification', 'HARI_ONLY')
            ->sum('amount');

        // Also get counts for transparency
        $otIncomeCount = $postedTransactions->where('type', 'INCOME')->count();
        $otExpenseCount = $postedTransactions->where('type', 'EXPENSE')->count();

        return [
            'ot_revenue' => (float) $otRevenue,
            'ot_expenses_total' => (float) $otExpensesTotal,
            'ot_shared_costs' => (float) $otSharedCosts,
            'ot_landlord_only_costs' => (float) $otLandlordOnlyCosts,
            'ot_hari_only_costs' => (float) $otHariOnlyCosts,
            'ot_income_count' => $otIncomeCount,
            'ot_expense_count' => $otExpenseCount,
            'date_range' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
        ];
    }

    /**
     * Reconcile project ledger income and expense totals.
     * Computes totals from ledger entries for income and expense accounts
     * linked to the project via AllocationRows.
     * 
     * @param string $projectId
     * @param string $tenantId
     * @param string $fromDate YYYY-MM-DD format
     * @param string $toDate YYYY-MM-DD format
     * @return array
     */
    public function reconcileProjectLedgerIncomeExpense(
        string $projectId,
        string $tenantId,
        string $fromDate,
        string $toDate
    ): array {
        $fromDateObj = Carbon::parse($fromDate);
        $toDateObj = Carbon::parse($toDate);

        // Use same query pattern as ReportController::projectPL but scoped to single project
        // and exclude reversed posting groups
        $query = "
            SELECT
                le.currency_code,
                SUM(CASE WHEN a.type = 'income' THEN (le.credit_amount - le.debit_amount) ELSE 0 END) AS income,
                SUM(CASE WHEN a.type = 'expense' THEN (le.debit_amount - le.credit_amount) ELSE 0 END) AS expenses,
                SUM(
                    CASE 
                        WHEN a.type = 'income' THEN (le.credit_amount - le.debit_amount)
                        WHEN a.type = 'expense' THEN -(le.debit_amount - le.credit_amount)
                        ELSE 0 
                    END
                ) AS net_profit
            FROM ledger_entries le
            JOIN accounts a ON a.id = le.account_id
            JOIN posting_groups pg ON pg.id = le.posting_group_id
            JOIN allocation_rows ar ON ar.posting_group_id = pg.id
            WHERE le.tenant_id = :tenant_id
                AND ar.project_id = :project_id
                AND pg.posting_date >= :from_date
                AND pg.posting_date <= :to_date
                AND NOT EXISTS (
                    SELECT 1
                    FROM posting_groups rev
                    WHERE rev.reversal_of_posting_group_id = pg.id
                )
            GROUP BY le.currency_code
        ";

        $results = DB::select($query, [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'from_date' => $fromDateObj->format('Y-m-d'),
            'to_date' => $toDateObj->format('Y-m-d'),
        ]);

        // Aggregate across currencies (typically just GBP)
        $ledgerIncome = 0;
        $ledgerExpenses = 0;
        $ledgerNet = 0;

        foreach ($results as $row) {
            $ledgerIncome += (float) $row->income;
            $ledgerExpenses += (float) $row->expenses;
            $ledgerNet += (float) $row->net_profit;
        }

        return [
            'ledger_income' => $ledgerIncome,
            'ledger_expenses' => $ledgerExpenses,
            'ledger_net' => $ledgerNet,
            'currency_breakdown' => array_map(function ($row) {
                return [
                    'currency_code' => $row->currency_code,
                    'income' => (float) $row->income,
                    'expenses' => (float) $row->expenses,
                    'net_profit' => (float) $row->net_profit,
                ];
            }, $results),
            'date_range' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
        ];
    }

    /**
     * Reconcile supplier accounts payable.
     * Compares supplier outstanding balance (from AllocationRows) with AP ledger movements.
     * 
     * Note: If ledger entries aren't party-attributed, this will document the limitation.
     * 
     * @param string $partyId
     * @param string $tenantId
     * @param string $fromDate YYYY-MM-DD format
     * @param string $toDate YYYY-MM-DD format
     * @return array
     */
    public function reconcileSupplierAP(
        string $partyId,
        string $tenantId,
        string $fromDate,
        string $toDate
    ): array {
        // Get supplier outstanding from AllocationRows (SUPPLIER_AP allocation type)
        $supplierOutstanding = $this->financialSourceService->getSupplierPayableFromGRN(
            $partyId,
            $tenantId,
            $fromDate,
            $toDate
        );

        // Try to get AP account
        try {
            $apAccount = $this->accountService->getByCode($tenantId, 'AP');
        } catch (\Exception $e) {
            // AP account might not exist, that's okay
            $apAccount = null;
        }

        $apLedgerMovement = 0;
        $reconciliationStatus = 'NOT_ATTRIBUTABLE';
        $notes = [];

        if ($apAccount) {
            // Check if we can attribute AP ledger entries to this party
            // AP ledger entries are typically created from GRN postings, but they may not
            // be directly linked to parties. We'll check AllocationRows for SUPPLIER_AP
            // and see if we can trace to ledger entries.
            
            // Get posting groups for this party's SUPPLIER_AP allocations in date range
            $postingGroupIds = AllocationRow::where('tenant_id', $tenantId)
                ->where('party_id', $partyId)
                ->where('allocation_type', 'SUPPLIER_AP')
                ->join('posting_groups', 'allocation_rows.posting_group_id', '=', 'posting_groups.id')
                ->where('posting_groups.posting_date', '>=', $fromDate)
                ->where('posting_groups.posting_date', '<=', $toDate)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('posting_groups as rev')
                        ->whereColumn('rev.reversal_of_posting_group_id', 'posting_groups.id');
                })
                ->pluck('posting_groups.id')
                ->toArray();

            if (!empty($postingGroupIds)) {
                // Get AP ledger entries from those posting groups
                $apLedgerMovement = LedgerEntry::where('tenant_id', $tenantId)
                    ->where('account_id', $apAccount->id)
                    ->whereIn('posting_group_id', $postingGroupIds)
                    ->sum(DB::raw('credit_amount - debit_amount'));

                // AP increases with credits (we owe more), decreases with debits (we pay)
                // So positive movement = increase in payable
                $reconciliationStatus = 'ATTRIBUTABLE';
            } else {
                $notes[] = 'No SUPPLIER_AP allocation rows found for this party in date range';
            }
        } else {
            $notes[] = 'AP account not found in system accounts';
        }

        // Also get payment movements (payments OUT reduce AP)
        $paymentTotals = $this->financialSourceService->getPostedPaymentsTotals(
            $partyId,
            $tenantId,
            $fromDate,
            $toDate
        );
        $paymentOutstanding = $paymentTotals['out'] ?? 0;

        $netSupplierOutstanding = $supplierOutstanding - $paymentOutstanding;

        return [
            'supplier_outstanding' => (float) $supplierOutstanding,
            'payment_outstanding' => (float) $paymentOutstanding,
            'net_supplier_outstanding' => (float) $netSupplierOutstanding,
            'ap_ledger_movement' => (float) $apLedgerMovement,
            'reconciliation_status' => $reconciliationStatus,
            'notes' => $notes,
            'date_range' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
        ];
    }
}

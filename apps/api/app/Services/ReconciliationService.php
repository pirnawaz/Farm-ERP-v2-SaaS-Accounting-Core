<?php

namespace App\Services;

use App\Models\OperationalTransaction;
use App\Models\PostingGroup;
use App\Models\LedgerEntry;
use App\Models\AllocationRow;
use App\Models\Account;
use App\Models\Project;
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
     * @param bool $excludeCogs When true, exclude COGS account codes (config reconciliation.cogs_account_codes)
     *                         so reconciliation matches OT (OT has no separate COGS line).
     * @return array
     */
    public function reconcileProjectLedgerIncomeExpense(
        string $projectId,
        string $tenantId,
        string $fromDate,
        string $toDate,
        bool $excludeCogs = false
    ): array {
        $fromDateObj = Carbon::parse($fromDate);
        $toDateObj = Carbon::parse($toDate);

        $cogsCodes = $excludeCogs ? (config('reconciliation.cogs_account_codes', ['COGS_PRODUCE']) ?: []) : [];
        $excludeClause = '';
        if (!empty($cogsCodes)) {
            $placeholders = implode(',', array_fill(0, count($cogsCodes), '?'));
            $excludeClause = " AND a.code NOT IN ({$placeholders})";
        }

        // Use same pattern as ReportController::projectPL: CTE with DISTINCT posting_group_id to avoid
        // double-counting when a posting_group has multiple allocation_rows for the same project.
        $query = "
            WITH project_pg AS (
                SELECT DISTINCT posting_group_id
                FROM allocation_rows
                WHERE tenant_id = ? AND project_id = ?
            )
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
            JOIN project_pg ON project_pg.posting_group_id = pg.id
            WHERE le.tenant_id = ?
                AND pg.posting_date >= ?
                AND pg.posting_date <= ?
                {$excludeClause}
            GROUP BY le.currency_code
        ";

        $bindings = array_merge(
            [$tenantId, $projectId, $tenantId, $fromDateObj->format('Y-m-d'), $toDateObj->format('Y-m-d')],
            $cogsCodes
        );
        $results = DB::select($query, $bindings);

        // Aggregate across currencies (typically just GBP). Reversals are included so (credit - debit) nets.
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
            $postingGroupIds = AllocationRow::where('allocation_rows.tenant_id', $tenantId)
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

    /**
     * Reconcile crop cycle: aggregate OT totals for all projects in the cycle.
     * Sums results of reconcileProjectSettlementVsOT across projects.
     *
     * @param string $cropCycleId
     * @param string $tenantId
     * @param string $fromDate YYYY-MM-DD
     * @param string $toDate YYYY-MM-DD
     * @return array
     */
    public function reconcileCropCycleSettlementVsOT(
        string $cropCycleId,
        string $tenantId,
        string $fromDate,
        string $toDate
    ): array {
        $projectIds = Project::where('crop_cycle_id', $cropCycleId)
            ->where('tenant_id', $tenantId)
            ->pluck('id')
            ->toArray();

        if (empty($projectIds)) {
            return [
                'ot_revenue' => 0.0,
                'ot_expenses_total' => 0.0,
                'ot_shared_costs' => 0.0,
                'ot_landlord_only_costs' => 0.0,
                'ot_hari_only_costs' => 0.0,
                'ot_income_count' => 0,
                'ot_expense_count' => 0,
                'date_range' => ['from' => $fromDate, 'to' => $toDate],
            ];
        }

        $toDateObj = Carbon::parse($toDate);
        $postedTransactions = OperationalTransaction::where('operational_transactions.tenant_id', $tenantId)
            ->whereIn('operational_transactions.project_id', $projectIds)
            ->where('operational_transactions.status', 'POSTED')
            ->whereNotNull('operational_transactions.posting_group_id')
            ->join('posting_groups', 'operational_transactions.posting_group_id', '=', 'posting_groups.id')
            ->where('posting_groups.posting_date', '<=', $toDateObj->format('Y-m-d'))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('posting_groups as rev')
                    ->whereColumn('rev.reversal_of_posting_group_id', 'posting_groups.id');
            })
            ->select('operational_transactions.*')
            ->get();

        $otRevenue = $postedTransactions->where('type', 'INCOME')->sum('amount');
        $otExpensesTotal = $postedTransactions->where('type', 'EXPENSE')->sum('amount');
        $otSharedCosts = $postedTransactions->where('type', 'EXPENSE')->where('classification', 'SHARED')->sum('amount');
        $otLandlordOnlyCosts = $postedTransactions->where('type', 'EXPENSE')->where('classification', 'LANDLORD_ONLY')->sum('amount');
        $otHariOnlyCosts = $postedTransactions->where('type', 'EXPENSE')->where('classification', 'HARI_ONLY')->sum('amount');

        return [
            'ot_revenue' => (float) $otRevenue,
            'ot_expenses_total' => (float) $otExpensesTotal,
            'ot_shared_costs' => (float) $otSharedCosts,
            'ot_landlord_only_costs' => (float) $otLandlordOnlyCosts,
            'ot_hari_only_costs' => (float) $otHariOnlyCosts,
            'ot_income_count' => $postedTransactions->where('type', 'INCOME')->count(),
            'ot_expense_count' => $postedTransactions->where('type', 'EXPENSE')->count(),
            'date_range' => ['from' => $fromDate, 'to' => $toDate],
        ];
    }

    /**
     * Reconcile crop cycle: aggregate ledger income/expense for all projects in the cycle.
     *
     * @param bool $excludeCogs When true, exclude COGS account codes for settlement reconciliation.
     * @return array
     */
    public function reconcileCropCycleLedgerIncomeExpense(
        string $cropCycleId,
        string $tenantId,
        string $fromDate,
        string $toDate,
        bool $excludeCogs = false
    ): array {
        $fromDateObj = Carbon::parse($fromDate);
        $toDateObj = Carbon::parse($toDate);

        $cogsCodes = $excludeCogs ? (config('reconciliation.cogs_account_codes', ['COGS_PRODUCE']) ?: []) : [];
        $excludeClause = '';
        if (!empty($cogsCodes)) {
            $placeholders = implode(',', array_fill(0, count($cogsCodes), '?'));
            $excludeClause = " AND a.code NOT IN ({$placeholders})";
        }

        // CTE with DISTINCT posting_group_id per crop cycle to avoid double-counting.
        $query = "
            WITH crop_cycle_pg AS (
                SELECT DISTINCT ar.posting_group_id
                FROM allocation_rows ar
                JOIN projects p ON p.id = ar.project_id AND p.tenant_id = ? AND p.crop_cycle_id = ?
                WHERE ar.tenant_id = ?
            )
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
            JOIN crop_cycle_pg ON crop_cycle_pg.posting_group_id = pg.id
            WHERE le.tenant_id = ?
                AND pg.posting_date >= ?
                AND pg.posting_date <= ?
                {$excludeClause}
            GROUP BY le.currency_code
        ";

        $bindings = array_merge(
            [$tenantId, $cropCycleId, $tenantId, $tenantId, $fromDateObj->format('Y-m-d'), $toDateObj->format('Y-m-d')],
            $cogsCodes
        );
        $results = DB::select($query, $bindings);

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
            'date_range' => ['from' => $fromDate, 'to' => $toDate],
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use App\Domains\Accounting\Reports\FinancialStatementsService;
use App\Domains\Reporting\TrialBalanceService;
use App\Domains\Reporting\GeneralLedgerService;
use App\Domains\Reporting\ProfitLossService;
use App\Domains\Reporting\BalanceSheetService;
use App\Services\SettlementService;
use App\Services\ReconciliationService;
use App\Services\SaleARService;
use App\Services\BillPaymentService;
use App\Services\PartyAccountService;
use App\Services\PartyLedgerService;
use App\Services\PartySummaryService;
use App\Services\RoleAgeingService;
use App\Services\LandlordStatementService;
use App\Models\Project;
use App\Models\OperationalTransaction;
use App\Models\Settlement;
use App\Models\Payment;
use App\Models\PostingGroup;
use App\Models\LedgerEntry;
use App\Models\Sale;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ReportController extends Controller
{
    private const RECONCILIATION_TOLERANCE = 0.01;

    public function __construct(
        private ReconciliationService $reconciliationService,
        private SettlementService $settlementService,
        private LandlordStatementService $landlordStatementService,
        private FinancialStatementsService $financialStatementsService,
        private TrialBalanceService $trialBalanceService,
        private GeneralLedgerService $generalLedgerService,
        private ProfitLossService $profitLossService,
        private BalanceSheetService $balanceSheetService
    ) {}

    /**
     * GET /api/reports/trial-balance
     * Returns trial balance by account as-of a date. Optional filters: project_id, crop_cycle_id.
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'as_of' => 'required|date',
            'project_id' => 'nullable|uuid',
            'crop_cycle_id' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $asOf = $request->input('as_of');
        $filters = array_filter([
            'project_id' => $request->input('project_id'),
            'crop_cycle_id' => $request->input('crop_cycle_id'),
        ]);

        $data = $this->trialBalanceService->getTrialBalance($tenantId, $asOf, $filters);
        return response()->json($data);
    }

    /**
     * GET /api/reports/profit-loss
     * Profit & Loss (Income Statement) for date range. Read-only from ledger.
     */
    public function profitLoss(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'compare_from' => 'nullable|date',
            'compare_to' => 'nullable|date|after_or_equal:compare_from',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $from = $request->input('from');
        $to = $request->input('to');
        $compareFrom = $request->input('compare_from');
        $compareTo = $request->input('compare_to');
        if (($compareFrom !== null) !== ($compareTo !== null)) {
            return response()->json(['errors' => ['compare_from' => ['Both compare_from and compare_to are required for comparison.']]], 422);
        }
        $data = $this->financialStatementsService->getProfitLoss($tenantId, $from, $to, $compareFrom, $compareTo);
        return response()->json($data);
    }

    /**
     * GET /api/reports/profit-loss/project
     * P&L scoped to a project (from allocation_rows). Required: project_id, from, to.
     */
    public function profitLossProject(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|uuid',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $from = $request->input('from');
        $to = $request->input('to');
        $projectId = $request->input('project_id');
        $filters = ['project_id' => $projectId];
        $data = $this->profitLossService->getProfitLoss($tenantId, $from, $to, $filters);
        return response()->json($data);
    }

    /**
     * GET /api/reports/profit-loss/crop-cycle
     * P&L scoped to a crop cycle. Required: crop_cycle_id, from, to.
     */
    public function profitLossCropCycle(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'crop_cycle_id' => 'required|uuid',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $from = $request->input('from');
        $to = $request->input('to');
        $cropCycleId = $request->input('crop_cycle_id');
        $filters = ['crop_cycle_id' => $cropCycleId];
        $data = $this->profitLossService->getProfitLoss($tenantId, $from, $to, $filters);
        return response()->json($data);
    }

    /**
     * GET /api/reports/balance-sheet
     * Balance Sheet as-of date. Optional: crop_cycle_id, project_id (tenant-wide if omitted).
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'as_of' => 'required|date',
            'crop_cycle_id' => 'nullable|uuid',
            'project_id' => 'nullable|uuid',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $asOf = $request->input('as_of');
        $filters = [];
        if ($request->filled('crop_cycle_id')) {
            $filters['crop_cycle_id'] = $request->input('crop_cycle_id');
        }
        if ($request->filled('project_id')) {
            $filters['project_id'] = $request->input('project_id');
        }
        $data = $this->balanceSheetService->getBalanceSheet($tenantId, $asOf, $filters);
        return response()->json($data);
    }
    
    /**
     * GET /api/reports/general-ledger
     * Account drill-down: opening balance, entries (running balance), closing balance.
     * Required: account_id, from, to. Optional: project_id, crop_cycle_id.
     */
    public function generalLedger(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'account_id' => 'required|uuid',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'project_id' => 'nullable|uuid',
            'crop_cycle_id' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $from = $request->input('from');
        $to = $request->input('to');
        $filters = ['account_id' => $request->input('account_id')];
        if ($request->filled('project_id')) {
            $filters['project_id'] = $request->input('project_id');
        }
        if ($request->filled('crop_cycle_id')) {
            $filters['crop_cycle_id'] = $request->input('crop_cycle_id');
        }

        $data = $this->generalLedgerService->getGeneralLedger($tenantId, $from, $to, $filters);
        return response()->json($data);
    }
    
    /**
     * GET /api/reports/project-pl
     * Returns P&L by project for a date range
     */
    public function projectPL(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'project_id' => 'nullable|uuid',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $from = $request->input('from');
        $to = $request->input('to');
        $projectId = $request->input('project_id');
        
        // CTE avoids duplicating ledger amounts when a posting_group has multiple allocation_rows (same project).
        // Only income/expense accounts; exclude clearing and party control from P&L.
        // Exclude allocation_rows with project_id IS NULL (e.g. FARM_OVERHEAD) so they do not appear in project P&L.
        $query = "
            WITH project_allocations AS (
                SELECT DISTINCT posting_group_id, project_id
                FROM allocation_rows
                WHERE tenant_id = :tenant_id
                    AND project_id IS NOT NULL
            )
            SELECT
                pa.project_id,
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
            JOIN project_allocations pa ON pa.posting_group_id = pg.id
            WHERE le.tenant_id = :tenant_id
                AND pg.posting_date BETWEEN :from AND :to
                AND a.type IN ('income', 'expense')
        ";
        
        $params = [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ];
        
        if ($projectId) {
            $query .= " AND pa.project_id = :project_id";
            $params['project_id'] = $projectId;
        }
        
        $query .= " GROUP BY pa.project_id, le.currency_code
                    ORDER BY pa.project_id";
        
        $results = DB::select($query, $params);
        
        $rows = array_map(function ($row) {
            return [
                'project_id' => $row->project_id,
                'currency_code' => $row->currency_code,
                'income' => (string) $row->income,
                'expenses' => (string) $row->expenses,
                'net_profit' => (string) $row->net_profit,
            ];
        }, $results);
        
        return response()->json($rows);
    }
    
    /**
     * GET /api/reports/crop-cycle-pl
     * Returns P&L by crop cycle for a date range
     */
    public function cropCyclePL(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'crop_cycle_id' => 'nullable|uuid',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $from = $request->input('from');
        $to = $request->input('to');
        $cropCycleId = $request->input('crop_cycle_id');
        
        // CTE avoids duplicating ledger amounts; only income/expense accounts.
        $query = "
            WITH project_allocations AS (
                SELECT DISTINCT ar.posting_group_id, p.crop_cycle_id
                FROM allocation_rows ar
                JOIN projects p ON p.id = ar.project_id
                WHERE ar.tenant_id = :tenant_id
            )
            SELECT
                pa.crop_cycle_id AS crop_cycle_id,
                cc.name AS crop_cycle_name,
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
            JOIN project_allocations pa ON pa.posting_group_id = pg.id
            JOIN crop_cycles cc ON cc.id = pa.crop_cycle_id
            WHERE le.tenant_id = :tenant_id
                AND pg.posting_date BETWEEN :from AND :to
                AND a.type IN ('income', 'expense')
        ";
        
        $params = [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ];
        
        if ($cropCycleId) {
            $query .= " AND pa.crop_cycle_id = :crop_cycle_id";
            $params['crop_cycle_id'] = $cropCycleId;
        }
        
        $query .= " GROUP BY pa.crop_cycle_id, cc.name, le.currency_code
                    ORDER BY pa.crop_cycle_id";
        
        $results = DB::select($query, $params);
        
        $rows = array_map(function ($row) {
            return [
                'crop_cycle_id' => $row->crop_cycle_id,
                'crop_cycle_name' => $row->crop_cycle_name,
                'currency_code' => $row->currency_code,
                'income' => (string) $row->income,
                'expenses' => (string) $row->expenses,
                'net_profit' => (string) $row->net_profit,
            ];
        }, $results);
        
        return response()->json($rows);
    }
    
    /**
     * GET /api/reports/account-balances
     * Returns account balances as of a specific date
     */
    public function accountBalances(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $validator = Validator::make($request->all(), [
            'as_of' => 'required|date',
            'project_id' => 'nullable|uuid',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $asOf = $request->input('as_of');
        $projectId = $request->input('project_id');
        
        // Use direct query with new column names
        $query = "
            SELECT
                a.id AS account_id,
                a.code AS account_code,
                a.name AS account_name,
                a.type AS account_type,
                le.currency_code,
                SUM(le.debit_amount) AS debits,
                SUM(le.credit_amount) AS credits,
                SUM(le.debit_amount - le.credit_amount) AS balance
            FROM ledger_entries le
            JOIN accounts a ON a.id = le.account_id
            JOIN posting_groups pg ON pg.id = le.posting_group_id
            WHERE le.tenant_id = :tenant_id
                AND pg.posting_date <= :as_of
        ";
        
        $params = [
            'tenant_id' => $tenantId,
            'as_of' => $asOf,
        ];
        
        if ($projectId) {
            $query .= " AND EXISTS (
                SELECT 1 FROM allocation_rows ar 
                WHERE ar.posting_group_id = pg.id 
                AND ar.project_id = :project_id
            )";
            $params['project_id'] = $projectId;
        }
        
        $query .= " GROUP BY a.id, a.code, a.name, a.type, le.currency_code
                    ORDER BY a.code";
        
        $results = DB::select($query, $params);
        
        $rows = array_map(function ($row) {
            return [
                'account_id' => $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'account_type' => $row->account_type,
                'currency_code' => $row->currency_code,
                'debits' => (string) $row->debits,
                'credits' => (string) $row->credits,
                'balance' => (string) $row->balance,
            ];
        }, $results);
        
        return response()->json($rows);
    }

    /**
     * GET /api/reports/project-statement
     * Returns project statement with revenue, costs, and settlement breakdown
     */
    public function projectStatement(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'project_id' => 'required|uuid',
            'up_to_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $projectId = $request->input('project_id');
        $upToDate = $request->input('up_to_date');

        $project = Project::where('id', $projectId)
            ->where('tenant_id', $tenantId)
            ->with(['projectRule', 'cropCycle', 'party'])
            ->firstOrFail();

        // Get posted transactions for this project
        $postedTransactionIds = \App\Models\PostingGroup::where('tenant_id', $tenantId)
            ->where('source_type', 'OPERATIONAL')
            ->when($upToDate, function ($query) use ($upToDate) {
                $query->where('posting_date', '<=', $upToDate);
            })
            ->pluck('source_id')
            ->toArray();

        $transactions = OperationalTransaction::where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('status', 'POSTED')
            ->whereIn('id', $postedTransactionIds)
            ->get();

        // Calculate totals
        $revenue = $transactions->where('type', 'INCOME')->sum('amount');
        $sharedCosts = $transactions->where('type', 'EXPENSE')
            ->where('classification', 'SHARED')
            ->sum('amount');
        $hariOnlyCosts = $transactions->where('type', 'EXPENSE')
            ->where('classification', 'HARI_ONLY')
            ->sum('amount');

        // Get latest settlement if exists
        $latestSettlement = Settlement::where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->when($upToDate, function ($query) use ($upToDate) {
                $query->whereHas('postingGroup', function ($q) use ($upToDate) {
                    $q->where('posting_date', '<=', $upToDate);
                });
            })
            ->orderBy('created_at', 'desc')
            ->first();

        $result = [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'crop_cycle' => $project->cropCycle,
                'party' => $project->party,
            ],
            'totals' => [
                'revenue' => (string) $revenue,
                'shared_costs' => (string) $sharedCosts,
                'hari_only_costs' => (string) $hariOnlyCosts,
            ],
        ];

        if ($latestSettlement) {
            $result['settlement'] = [
                'pool_revenue' => (string) $latestSettlement->pool_revenue,
                'shared_costs' => (string) $latestSettlement->shared_costs,
                'pool_profit' => (string) $latestSettlement->pool_profit,
                'kamdari_amount' => (string) $latestSettlement->kamdari_amount,
                'landlord_share' => (string) $latestSettlement->landlord_share,
                'hari_share' => (string) $latestSettlement->hari_share,
                'hari_only_deductions' => (string) $latestSettlement->hari_only_deductions,
                'posting_date' => $latestSettlement->postingGroup->posting_date->format('Y-m-d'),
            ];
        }

        return response()->json($result);
    }

    /**
     * GET /api/reports/cashbook
     * Returns cash movements from posted payments and operational transactions affecting CASH account
     */
    public function cashbook(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $from = $request->input('from');
        $to = $request->input('to');
        
        // Get CASH account ID
        $cashAccount = DB::table('accounts')
            ->where('tenant_id', $tenantId)
            ->where('code', 'CASH')
            ->first();
        
        if (!$cashAccount) {
            return response()->json([]);
        }
        
        // Get ledger entries affecting CASH account
        $ledgerEntries = LedgerEntry::where('ledger_entries.tenant_id', $tenantId)
            ->where('ledger_entries.account_id', $cashAccount->id)
            ->join('posting_groups', 'ledger_entries.posting_group_id', '=', 'posting_groups.id')
            ->whereBetween('posting_groups.posting_date', [$from, $to])
            ->select(
                'posting_groups.posting_date',
                'posting_groups.source_type',
                'posting_groups.source_id',
                'ledger_entries.debit_amount',
                'ledger_entries.credit_amount'
            )
            ->orderBy('posting_groups.posting_date', 'asc')
            ->orderBy('posting_groups.created_at', 'asc')
            ->get();
        
        $results = [];
        
        foreach ($ledgerEntries as $entry) {
            $description = '';
            $reference = '';
            
            if ($entry->source_type === 'ADJUSTMENT') {
                // Try to get payment details
                $payment = Payment::where('id', $entry->source_id)
                    ->where('tenant_id', $tenantId)
                    ->with('party')
                    ->first();
                
                if ($payment) {
                    $description = $payment->direction === 'OUT' 
                        ? 'Payment to ' . ($payment->party->name ?? 'Unknown')
                        : 'Receipt from ' . ($payment->party->name ?? 'Unknown');
                    $reference = $payment->reference ?? '';
                } else {
                    $description = 'Payment';
                }
            } elseif ($entry->source_type === 'OPERATIONAL') {
                // Get operational transaction details
                $transaction = OperationalTransaction::where('id', $entry->source_id)
                    ->where('tenant_id', $tenantId)
                    ->with('project')
                    ->first();
                
                if ($transaction) {
                    $description = $transaction->type === 'INCOME' 
                        ? 'Income: ' . ($transaction->project->name ?? 'Unknown')
                        : 'Expense: ' . ($transaction->project->name ?? 'Unknown');
                } else {
                    $description = 'Operational Transaction';
                }
            } else {
                $description = ucfirst(strtolower($entry->source_type));
            }
            
            $amount = $entry->debit_amount > 0 ? (string) $entry->debit_amount : (string) $entry->credit_amount;
            $type = $entry->debit_amount > 0 ? 'IN' : 'OUT';
            
            $results[] = [
                'date' => $entry->posting_date,
                'description' => $description,
                'reference' => $reference,
                'type' => $type,
                'amount' => $amount,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
            ];
        }
        
        return response()->json($results);
    }

    /**
     * GET /api/reports/ar-ageing
     * Returns AR Ageing report grouped by buyer with buckets
     */
    public function arAgeing(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $asOfDate = $request->input('as_of', Carbon::today()->format('Y-m-d'));
        $asOfDateObj = Carbon::parse($asOfDate);

        $arService = app(SaleARService::class);
        $financialSourceService = app(\App\Services\PartyFinancialSourceService::class);

        // Get all buyers (parties with BUYER type or any party with sales)
        $buyers = Party::where('tenant_id', $tenantId)
            ->where(function ($query) {
                $query->whereJsonContains('party_types', 'BUYER')
                    ->orWhereHas('sales', function ($q) {
                        $q->where('status', 'POSTED');
                    });
            })
            ->get();

        $rows = [];
        $totals = [
            'total_outstanding' => 0,
            'bucket_0_30' => 0,
            'bucket_31_60' => 0,
            'bucket_61_90' => 0,
            'bucket_90_plus' => 0,
        ];

        foreach ($buyers as $buyer) {
            // Get open sales for this buyer
            $openSales = $arService->getBuyerOpenSales($buyer->id, $tenantId, $asOfDate);

            if (empty($openSales)) {
                continue; // Skip buyers with no open sales
            }

            $buyerTotals = [
                'total_outstanding' => 0,
                'bucket_0_30' => 0,
                'bucket_31_60' => 0,
                'bucket_61_90' => 0,
                'bucket_90_plus' => 0,
            ];

            // Calculate ageing buckets for each sale
            // Note: openSales returns formatted strings, so we need to get actual sales
            $sales = Sale::where('tenant_id', $tenantId)
                ->where('buyer_party_id', $buyer->id)
                ->where('status', 'POSTED')
                ->orderBy('posting_date', 'asc')
                ->orderBy('created_at', 'asc')
                ->get();

            foreach ($sales as $sale) {
                $outstanding = $arService->getSaleOutstanding($sale->id, $tenantId, $asOfDate);
                
                if ($outstanding <= 0) {
                    continue; // Skip fully paid sales
                }

                $buyerTotals['total_outstanding'] += $outstanding;

                // Calculate days overdue
                $dueDate = $sale->due_date ? Carbon::parse($sale->due_date) : Carbon::parse($sale->posting_date);
                $daysOverdue = $asOfDateObj->diffInDays($dueDate, false); // negative if not due yet

                // Bucket assignment
                if ($daysOverdue <= 30) {
                    // 0-30 days (includes not due yet)
                    $buyerTotals['bucket_0_30'] += $outstanding;
                } elseif ($daysOverdue <= 60) {
                    $buyerTotals['bucket_31_60'] += $outstanding;
                } elseif ($daysOverdue <= 90) {
                    $buyerTotals['bucket_61_90'] += $outstanding;
                } else {
                    $buyerTotals['bucket_90_plus'] += $outstanding;
                }
            }

            if ($buyerTotals['total_outstanding'] > 0) {
                $rows[] = [
                    'buyer_party_id' => $buyer->id,
                    'buyer_name' => $buyer->name,
                    'total_outstanding' => number_format($buyerTotals['total_outstanding'], 2, '.', ''),
                    'bucket_0_30' => number_format($buyerTotals['bucket_0_30'], 2, '.', ''),
                    'bucket_31_60' => number_format($buyerTotals['bucket_31_60'], 2, '.', ''),
                    'bucket_61_90' => number_format($buyerTotals['bucket_61_90'], 2, '.', ''),
                    'bucket_90_plus' => number_format($buyerTotals['bucket_90_plus'], 2, '.', ''),
                ];

                // Add to grand totals
                $totals['total_outstanding'] += $buyerTotals['total_outstanding'];
                $totals['bucket_0_30'] += $buyerTotals['bucket_0_30'];
                $totals['bucket_31_60'] += $buyerTotals['bucket_31_60'];
                $totals['bucket_61_90'] += $buyerTotals['bucket_61_90'];
                $totals['bucket_90_plus'] += $buyerTotals['bucket_90_plus'];
            }
        }

        return response()->json([
            'as_of' => $asOfDate,
            'buckets' => ['0-30', '31-60', '61-90', '90+'],
            'rows' => $rows,
            'totals' => [
                'total_outstanding' => number_format($totals['total_outstanding'], 2, '.', ''),
                'bucket_0_30' => number_format($totals['bucket_0_30'], 2, '.', ''),
                'bucket_31_60' => number_format($totals['bucket_31_60'], 2, '.', ''),
                'bucket_61_90' => number_format($totals['bucket_61_90'], 2, '.', ''),
                'bucket_90_plus' => number_format($totals['bucket_90_plus'], 2, '.', ''),
            ],
        ]);
    }

    /**
     * GET /api/ar/aging
     * AR Aging report: open invoice balances per customer in buckets (auditable, reconciles to open invoices).
     */
    public function arAging(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $asOf = $request->input('as_of', Carbon::today()->format('Y-m-d'));

        $arService = app(SaleARService::class);
        $data = $arService->getARAging($tenantId, $asOf);

        return response()->json($data);
    }

    /**
     * GET /api/reports/yield
     * Returns yield quantities by crop cycle, optionally grouped by parcel or item
     */
    public function yield(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'crop_cycle_id' => 'nullable|uuid|exists:crop_cycles,id',
            'group_by' => 'nullable|string|in:parcel,item,none',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $cropCycleId = $request->input('crop_cycle_id');
        $groupBy = $request->input('group_by', 'none');

        $query = DB::table('harvest_lines')
            ->join('harvests', 'harvest_lines.harvest_id', '=', 'harvests.id')
            ->join('inv_items', 'harvest_lines.inventory_item_id', '=', 'inv_items.id')
            ->where('harvest_lines.tenant_id', $tenantId)
            ->where('harvests.status', 'POSTED')
            ->select(
                'harvests.crop_cycle_id',
                'harvest_lines.inventory_item_id',
                'inv_items.name as item_name',
                'harvests.land_parcel_id',
                DB::raw('SUM(harvest_lines.quantity) as total_quantity'),
                'harvest_lines.uom'
            );

        if ($cropCycleId) {
            $query->where('harvests.crop_cycle_id', $cropCycleId);
        }

        if ($groupBy === 'parcel') {
            $query->groupBy('harvests.crop_cycle_id', 'harvest_lines.inventory_item_id', 'inv_items.name', 'harvests.land_parcel_id', 'harvest_lines.uom');
        } elseif ($groupBy === 'item') {
            $query->groupBy('harvests.crop_cycle_id', 'harvest_lines.inventory_item_id', 'inv_items.name', 'harvest_lines.uom');
        } else {
            $query->groupBy('harvests.crop_cycle_id', 'harvest_lines.inventory_item_id', 'inv_items.name', 'harvest_lines.uom');
        }

        $results = $query->get();

        $rows = $results->map(function ($row) {
            return [
                'crop_cycle_id' => $row->crop_cycle_id,
                'item_id' => $row->inventory_item_id,
                'item_name' => $row->item_name,
                'land_parcel_id' => $row->land_parcel_id,
                'total_quantity' => (string) $row->total_quantity,
                'uom' => $row->uom,
            ];
        })->toArray();

        return response()->json($rows);
    }

    /**
     * GET /api/reports/cost-per-unit
     * Returns cost per unit for harvested items by crop cycle
     */
    public function costPerUnit(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'crop_cycle_id' => 'required|uuid|exists:crop_cycles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $cropCycleId = $request->input('crop_cycle_id');

        // Get quantities from harvest_lines
        $qtyQuery = DB::table('harvest_lines')
            ->join('harvests', 'harvest_lines.harvest_id', '=', 'harvests.id')
            ->join('inv_items', 'harvest_lines.inventory_item_id', '=', 'inv_items.id')
            ->where('harvest_lines.tenant_id', $tenantId)
            ->where('harvests.crop_cycle_id', $cropCycleId)
            ->where('harvests.status', 'POSTED')
            ->select(
                'harvest_lines.inventory_item_id',
                'inv_items.name as item_name',
                'harvests.land_parcel_id',
                DB::raw('SUM(harvest_lines.quantity) as total_qty')
            )
            ->groupBy('harvest_lines.inventory_item_id', 'inv_items.name', 'harvests.land_parcel_id');

        $qtyResults = $qtyQuery->get();

        // Get cost from allocation_rows
        // Prefer harvest_line_id from rule_snapshot for accurate traceability
        // Fallback to ROW_NUMBER() matching for backward compatibility (old harvests without snapshot)
        $costQuery = DB::select("
            WITH allocation_rows_with_line_id AS (
                SELECT 
                    ar.id,
                    ar.amount,
                    ar.posting_group_id,
                    pg.source_id as harvest_id,
                    (ar.rule_snapshot->>'harvest_line_id')::uuid as harvest_line_id,
                    ROW_NUMBER() OVER (PARTITION BY ar.posting_group_id ORDER BY ar.created_at) as row_num
                FROM allocation_rows ar
                JOIN posting_groups pg ON ar.posting_group_id = pg.id
                WHERE ar.tenant_id = :tenant_id
                    AND pg.source_type = 'HARVEST'
                    AND pg.crop_cycle_id = :crop_cycle_id
            ),
            ranked_harvest_lines AS (
                SELECT 
                    hl.id,
                    hl.inventory_item_id,
                    hl.harvest_id,
                    h.land_parcel_id,
                    ROW_NUMBER() OVER (PARTITION BY hl.harvest_id ORDER BY hl.created_at) as row_num
                FROM harvest_lines hl
                JOIN harvests h ON hl.harvest_id = h.id
                WHERE hl.tenant_id = :tenant_id
                    AND h.crop_cycle_id = :crop_cycle_id
                    AND h.status = 'POSTED'
            ),
            matched_costs AS (
                SELECT 
                    ar.amount,
                    COALESCE(hl_by_id.inventory_item_id, rhl.inventory_item_id) as inventory_item_id,
                    COALESCE(h_by_id.land_parcel_id, h_by_row.land_parcel_id) as land_parcel_id
                FROM allocation_rows_with_line_id ar
                LEFT JOIN harvest_lines hl_by_id ON ar.harvest_line_id = hl_by_id.id
                LEFT JOIN harvests h_by_id ON hl_by_id.harvest_id = h_by_id.id
                LEFT JOIN ranked_harvest_lines rhl ON ar.harvest_id = rhl.harvest_id 
                    AND ar.harvest_line_id IS NULL 
                    AND ar.row_num = rhl.row_num
                LEFT JOIN harvests h_by_row ON rhl.harvest_id = h_by_row.id
                WHERE hl_by_id.id IS NOT NULL OR rhl.id IS NOT NULL
            )
            SELECT 
                inventory_item_id,
                land_parcel_id,
                SUM(amount) as total_cost
            FROM matched_costs
            GROUP BY inventory_item_id, land_parcel_id
        ", [
            'tenant_id' => $tenantId,
            'crop_cycle_id' => $cropCycleId,
        ]);

        $costResults = collect($costQuery)->keyBy(function ($row) {
            return $row->inventory_item_id . '_' . ($row->land_parcel_id ?? 'null');
        });

        $rows = [];
        foreach ($qtyResults as $qtyRow) {
            $key = $qtyRow->inventory_item_id . '_' . ($qtyRow->land_parcel_id ?? 'null');
            $costRow = $costResults->get($key);
            $totalCost = $costRow ? (float) $costRow->total_cost : 0;
            $totalQty = (float) $qtyRow->total_qty;
            $costPerUnit = $totalQty > 0 ? $totalCost / $totalQty : 0;

            $rows[] = [
                'item_id' => $qtyRow->inventory_item_id,
                'item_name' => $qtyRow->item_name,
                'land_parcel_id' => $qtyRow->land_parcel_id,
                'total_cost' => (string) round($totalCost, 2),
                'total_qty' => (string) round($totalQty, 3),
                'cost_per_unit' => (string) round($costPerUnit, 4),
            ];
        }

        return response()->json($rows);
    }

    /**
     * GET /api/reports/sales-margin
     * Returns sales margin report with revenue, COGS, and gross margin
     * Query params: crop_cycle_id (optional), from (optional), to (optional), group_by (sale|item|crop_cycle, default: sale)
     */
    public function salesMargin(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $validator = Validator::make($request->all(), [
            'crop_cycle_id' => ['nullable', 'uuid', 'exists:crop_cycles,id'],
            'from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date', 'date_format:Y-m-d'],
            'group_by' => ['nullable', 'string', 'in:sale,item,crop_cycle'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $cropCycleId = $request->crop_cycle_id;
        $from = $request->from;
        $to = $request->to;
        $groupBy = $request->group_by ?? 'sale';

        // Build base query
        $query = DB::table('sales')
            ->join('sale_lines', 'sales.id', '=', 'sale_lines.sale_id')
            ->leftJoin('sale_inventory_allocations', 'sale_lines.id', '=', 'sale_inventory_allocations.sale_line_id')
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.status', 'POSTED');

        if ($cropCycleId) {
            $query->where('sales.crop_cycle_id', $cropCycleId);
        }

        if ($from) {
            $query->where('sales.posting_date', '>=', $from);
        }

        if ($to) {
            $query->where('sales.posting_date', '<=', $to);
        }

        // Group by clause
        $groupByFields = [];
        $selectFields = [];

        if ($groupBy === 'sale') {
            $groupByFields = ['sales.id'];
            $selectFields = [
                'sales.id as sale_id',
                DB::raw("NULL::uuid as item_id"),
                DB::raw("NULL::uuid as crop_cycle_id"),
            ];
        } elseif ($groupBy === 'item') {
            $groupByFields = ['sale_lines.inventory_item_id'];
            $selectFields = [
                DB::raw("NULL::uuid as sale_id"),
                'sale_lines.inventory_item_id as item_id',
                DB::raw("NULL::uuid as crop_cycle_id"),
            ];
        } else { // crop_cycle
            $groupByFields = ['sales.crop_cycle_id'];
            $selectFields = [
                DB::raw("NULL::uuid as sale_id"),
                DB::raw("NULL::uuid as item_id"),
                'sales.crop_cycle_id',
            ];
        }

        $selectFields = array_merge($selectFields, [
            DB::raw('SUM(sale_lines.quantity) as qty_sold'),
            DB::raw('SUM(sale_lines.line_total) as revenue_total'),
            DB::raw('COALESCE(SUM(sale_inventory_allocations.total_cost), 0) as cogs_total'),
        ]);

        $results = $query
            ->select($selectFields)
            ->groupBy($groupByFields)
            ->get();

        $rows = [];
        foreach ($results as $row) {
            $revenueTotal = (float) $row->revenue_total;
            $cogsTotal = (float) $row->cogs_total;
            $grossMargin = $revenueTotal - $cogsTotal;
            $grossMarginPct = $revenueTotal > 0 ? ($grossMargin / $revenueTotal) * 100 : 0;

            $rows[] = [
                'sale_id' => $row->sale_id,
                'item_id' => $row->item_id,
                'crop_cycle_id' => $row->crop_cycle_id,
                'qty_sold' => (string) round((float) $row->qty_sold, 3),
                'revenue_total' => (string) round($revenueTotal, 2),
                'cogs_total' => (string) round($cogsTotal, 2),
                'gross_margin' => (string) round($grossMargin, 2),
                'gross_margin_pct' => (string) round($grossMarginPct, 2),
            ];
        }

        return response()->json($rows);
    }

    /**
     * GET /api/reports/settlement-statement
     * Returns settlement statement for a party showing opening balance, settlements, payments, and closing balance
     * Query params: party_id (required), from (optional), to (optional)
     */
    public function settlementStatement(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $validator = Validator::make($request->all(), [
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $partyId = $request->party_id;
        $from = $request->from;
        $to = $request->to ?? Carbon::now()->format('Y-m-d');

        // Opening balance: sum of settlement payables before from date
        $openingQuery = DB::table('settlement_lines')
            ->join('settlements', 'settlement_lines.settlement_id', '=', 'settlements.id')
            ->where('settlement_lines.tenant_id', $tenantId)
            ->where('settlement_lines.party_id', $partyId)
            ->where('settlements.status', 'POSTED');

        if ($from) {
            $openingQuery->where('settlements.posting_date', '<', $from);
        }

        $openingBalance = (float) ($openingQuery->sum('settlement_lines.amount') ?? 0);

        // Settlements in period
        $settlementsQuery = DB::table('settlement_lines')
            ->join('settlements', 'settlement_lines.settlement_id', '=', 'settlements.id')
            ->where('settlement_lines.tenant_id', $tenantId)
            ->where('settlement_lines.party_id', $partyId)
            ->where('settlements.status', 'POSTED');

        if ($from) {
            $settlementsQuery->where('settlements.posting_date', '>=', $from);
        }
        if ($to) {
            $settlementsQuery->where('settlements.posting_date', '<=', $to);
        }

        $settlements = $settlementsQuery
            ->select([
                'settlements.id',
                'settlements.settlement_no',
                'settlements.posting_date',
                'settlement_lines.amount',
                'settlement_lines.role',
            ])
            ->orderBy('settlements.posting_date', 'asc')
            ->get();

        // Payments against payables (from payments allocated to this party's payables)
        // Note: This assumes payments have party_id or allocation to party payables
        // For now, we'll calculate based on payments to this party
        $paymentsQuery = DB::table('payments')
            ->join('posting_groups', 'payments.posting_group_id', '=', 'posting_groups.id')
            ->where('payments.tenant_id', $tenantId)
            ->where('payments.party_id', $partyId)
            ->where('payments.direction', 'OUT')
            ->where('payments.status', 'POSTED');

        if ($from) {
            $paymentsQuery->where('posting_groups.posting_date', '>=', $from);
        }
        if ($to) {
            $paymentsQuery->where('posting_groups.posting_date', '<=', $to);
        }

        $payments = $paymentsQuery
            ->select([
                'payments.id',
                'payments.payment_no',
                'posting_groups.posting_date',
                'payments.amount',
            ])
            ->orderBy('posting_groups.posting_date', 'asc')
            ->get();

        $totalSettlements = (float) $settlements->sum('amount');
        $totalPayments = (float) $payments->sum('amount');
        $closingBalance = $openingBalance + $totalSettlements - $totalPayments;

        return response()->json([
            'party_id' => $partyId,
            'from_date' => $from,
            'to_date' => $to,
            'opening_balance' => round($openingBalance, 2),
            'settlements' => $settlements->map(fn($s) => [
                'id' => $s->id,
                'settlement_no' => $s->settlement_no,
                'posting_date' => $s->posting_date,
                'amount' => round((float) $s->amount, 2),
                'role' => $s->role,
            ]),
            'total_settlements' => round($totalSettlements, 2),
            'payments' => $payments->map(fn($p) => [
                'id' => $p->id,
                'payment_no' => $p->payment_no,
                'posting_date' => $p->posting_date,
                'amount' => round((float) $p->amount, 2),
            ]),
            'total_payments' => round($totalPayments, 2),
            'closing_balance' => round($closingBalance, 2),
        ]);
    }

    /**
     * GET /api/reports/party-ledger
     * Returns party ledger (PARTY_CONTROL_* as single source of truth) with opening/closing balance and running balance rows.
     * Query params: party_id (required), from (required), to (required), project_id (optional), crop_cycle_id (optional)
     */
    public function partyLedger(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'from' => ['required', 'date', 'date_format:Y-m-d'],
            'to' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:from'],
            'project_id' => ['nullable', 'uuid'],
            'crop_cycle_id' => ['nullable', 'uuid'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $partyId = $request->input('party_id');
        $from = $request->input('from');
        $to = $request->input('to');
        $projectId = $request->input('project_id');
        $cropCycleId = $request->input('crop_cycle_id');

        // Ensure party belongs to tenant
        $party = Party::where('id', $partyId)->where('tenant_id', $tenantId)->firstOrFail();

        $partyAccountService = app(PartyAccountService::class);
        $controlAccount = $partyAccountService->getPartyControlAccount($tenantId, $party->id);

        $partyLedgerService = app(PartyLedgerService::class);
        $result = $partyLedgerService->getLedger(
            $tenantId,
            $controlAccount->id,
            $from,
            $to,
            $projectId ?: null,
            $cropCycleId ?: null
        );

        return response()->json([
            'opening_balance' => $result['opening_balance'],
            'closing_balance' => $result['closing_balance'],
            'rows' => $result['rows'],
        ]);
    }

    /**
     * GET /api/reports/party-summary
     * Returns one row per party (Hari/Landlord/Kamdar) with opening, period movement, closing from PARTY_CONTROL_* only.
     * Query params: from (required), to (required), role (optional), project_id (optional), crop_cycle_id (optional)
     */
    public function partySummary(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'from' => ['required', 'date', 'date_format:Y-m-d'],
            'to' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:from'],
            'role' => ['nullable', 'string', 'in:HARI,LANDLORD,KAMDAR'],
            'project_id' => ['nullable', 'uuid'],
            'crop_cycle_id' => ['nullable', 'uuid'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $from = $request->input('from');
        $to = $request->input('to');
        $role = $request->input('role');
        $projectId = $request->input('project_id');
        $cropCycleId = $request->input('crop_cycle_id');

        $service = app(PartySummaryService::class);
        $result = $service->getSummary(
            $tenantId,
            $from,
            $to,
            $role ?: null,
            $projectId ?: null,
            $cropCycleId ?: null
        );

        return response()->json($result);
    }

    /**
     * GET /api/reports/landlord-statement
     * Ledger-backed statement for DUE_TO_LANDLORD scoped to a party (landlord).
     * Query params: party_id (required), date_from (required), date_to (required).
     */
    public function landlordStatement(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'date_from' => ['required', 'date', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $partyId = $request->input('party_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $result = $this->landlordStatementService->getStatement($tenantId, $partyId, $dateFrom, $dateTo);
        return response()->json($result);
    }

    /**
     * GET /api/reports/role-ageing
     * Returns role-level ageing buckets (0-30, 31-60, 61-90, 90+ days) from PARTY_CONTROL_* ledger entries.
     * Query params: as_of (required, YYYY-MM-DD), project_id (optional), crop_cycle_id (optional)
     */
    public function roleAgeing(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'as_of' => ['required', 'date', 'date_format:Y-m-d'],
            'project_id' => ['nullable', 'uuid'],
            'crop_cycle_id' => ['nullable', 'uuid'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $asOf = $request->input('as_of');
        $projectId = $request->input('project_id');
        $cropCycleId = $request->input('crop_cycle_id');

        $service = app(RoleAgeingService::class);
        $result = $service->getAgeing(
            $tenantId,
            $asOf,
            $projectId ?: null,
            $cropCycleId ?: null
        );

        return response()->json($result);
    }

    /**
     * GET /api/reports/crop-cycle-distribution
     * Returns margin distribution by party for a crop cycle
     * Query params: crop_cycle_id (required)
     */
    public function cropCycleDistribution(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $validator = Validator::make($request->all(), [
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $cropCycleId = $request->crop_cycle_id;

        // Get total margin from posted sales
        $salesMargin = DB::table('sales')
            ->join('sale_lines', 'sales.id', '=', 'sale_lines.sale_id')
            ->leftJoin('sale_inventory_allocations', 'sale_lines.id', '=', 'sale_inventory_allocations.sale_line_id')
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.crop_cycle_id', $cropCycleId)
            ->where('sales.status', 'POSTED')
            ->selectRaw('
                COALESCE(SUM(sale_lines.line_total), 0) as revenue_total,
                COALESCE(SUM(sale_inventory_allocations.total_cost), 0) as cogs_total
            ')
            ->first();

        $totalRevenue = (float) ($salesMargin->revenue_total ?? 0);
        $totalCogs = (float) ($salesMargin->cogs_total ?? 0);
        $totalMargin = $totalRevenue - $totalCogs;

        // Get distribution by party from settlements
        $distribution = DB::table('settlement_lines')
            ->join('settlements', 'settlement_lines.settlement_id', '=', 'settlements.id')
            ->join('parties', 'settlement_lines.party_id', '=', 'parties.id')
            ->where('settlement_lines.tenant_id', $tenantId)
            ->where('settlements.crop_cycle_id', $cropCycleId)
            ->where('settlements.status', 'POSTED')
            ->select([
                'parties.id as party_id',
                'parties.name as party_name',
                'settlement_lines.role',
                DB::raw('SUM(settlement_lines.amount) as total_amount'),
            ])
            ->groupBy('parties.id', 'parties.name', 'settlement_lines.role')
            ->orderBy('parties.name')
            ->get();

        return response()->json([
            'crop_cycle_id' => $cropCycleId,
            'total_revenue' => round($totalRevenue, 2),
            'total_cogs' => round($totalCogs, 2),
            'total_margin' => round($totalMargin, 2),
            'distribution' => $distribution->map(fn($d) => [
                'party_id' => $d->party_id,
                'party_name' => $d->party_name,
                'role' => $d->role,
                'amount' => round((float) $d->total_amount, 2),
                'percentage' => $totalMargin > 0 ? round(((float) $d->total_amount / $totalMargin) * 100, 2) : 0,
            ]),
        ]);
    }

    /**
     * GET /api/reports/reconciliation/project
     * Read-only reconciliation checks for a project (settlement vs OT, ledger vs OT).
     * Query: project_id (required), from (required), to (required).
     */
    public function reconciliationProject(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'project_id' => 'required|uuid|exists:projects,id',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $projectId = $request->input('project_id');
        $from = $request->input('from');
        $to = $request->input('to');
        $checks = [];

        // Check 1: Settlement vs OT (pool totals excluding COGS so like-for-like with OT)
        try {
            $settlement = $this->settlementService->getProjectProfitFromLedgerExcludingCOGS($projectId, $tenantId, $to);
            $ot = $this->reconciliationService->reconcileProjectSettlementVsOT($projectId, $tenantId, $from, $to);
            $revenueDelta = (float) $settlement['total_revenue'] - $ot['ot_revenue'];
            $expensesDelta = (float) $settlement['total_expenses'] - $ot['ot_expenses_total'];
            $pass = abs($revenueDelta) < self::RECONCILIATION_TOLERANCE && abs($expensesDelta) < self::RECONCILIATION_TOLERANCE;
            $checks[] = $this->buildReconciliationCheck(
                'settlement_vs_ot',
                'Settlement vs Operational Transactions',
                $pass ? 'PASS' : 'FAIL',
                $pass ? 'Delta: Rs 0' : sprintf('Revenue delta: %.2f; Expenses delta: %.2f', $revenueDelta, $expensesDelta),
                [
                    'settlement_total_revenue' => (float) $settlement['total_revenue'],
                    'settlement_total_expenses' => (float) $settlement['total_expenses'],
                    'ot_revenue' => $ot['ot_revenue'],
                    'ot_expenses_total' => $ot['ot_expenses_total'],
                    'revenue_delta' => $revenueDelta,
                    'expenses_delta' => $expensesDelta,
                ]
            );
        } catch (\Throwable $e) {
            $checks[] = $this->buildReconciliationCheck('settlement_vs_ot', 'Settlement vs Operational Transactions', 'FAIL', $e->getMessage(), ['error' => $e->getMessage()]);
        }

        // Check 2: Ledger vs OT (exclude COGS so ledger matches OT)
        try {
            $ledger = $this->reconciliationService->reconcileProjectLedgerIncomeExpense($projectId, $tenantId, $from, $to, true);
            $ot = $this->reconciliationService->reconcileProjectSettlementVsOT($projectId, $tenantId, $from, $to);
            $incomeDelta = $ledger['ledger_income'] - $ot['ot_revenue'];
            $expensesDelta = $ledger['ledger_expenses'] - $ot['ot_expenses_total'];
            $pass = abs($incomeDelta) < self::RECONCILIATION_TOLERANCE && abs($expensesDelta) < self::RECONCILIATION_TOLERANCE;
            $checks[] = $this->buildReconciliationCheck(
                'ledger_vs_ot',
                'Ledger income/expense vs OT',
                $pass ? 'PASS' : 'FAIL',
                $pass ? 'Delta: Rs 0' : sprintf('Income delta: %.2f; Expenses delta: %.2f', $incomeDelta, $expensesDelta),
                [
                    'ledger_income' => $ledger['ledger_income'],
                    'ledger_expenses' => $ledger['ledger_expenses'],
                    'ot_revenue' => $ot['ot_revenue'],
                    'ot_expenses_total' => $ot['ot_expenses_total'],
                    'income_delta' => $incomeDelta,
                    'expenses_delta' => $expensesDelta,
                ]
            );
        } catch (\Throwable $e) {
            $checks[] = $this->buildReconciliationCheck('ledger_vs_ot', 'Ledger income/expense vs OT', 'FAIL', $e->getMessage(), ['error' => $e->getMessage()]);
        }

        return response()->json([
            'checks' => $checks,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/reports/reconciliation/crop-cycle
     * Read-only reconciliation checks for a crop cycle (aggregate of projects).
     * Query: crop_cycle_id (required), from (required), to (required).
     */
    public function reconciliationCropCycle(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'crop_cycle_id' => 'required|uuid|exists:crop_cycles,id',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $cropCycleId = $request->input('crop_cycle_id');
        $from = $request->input('from');
        $to = $request->input('to');
        $checks = [];

        $projectIds = Project::where('crop_cycle_id', $cropCycleId)->where('tenant_id', $tenantId)->pluck('id')->toArray();
        if (empty($projectIds)) {
            $checks[] = $this->buildReconciliationCheck('crop_cycle_scope', 'Crop cycle scope', 'WARN', 'No projects in this crop cycle', ['project_count' => 0]);
            return response()->json(['checks' => $checks, 'generated_at' => now()->toIso8601String()]);
        }

        // Settlement totals (excluding COGS) = sum of getProjectProfitFromLedgerExcludingCOGS per project
        $settlementRevenue = 0.0;
        $settlementExpenses = 0.0;
        foreach ($projectIds as $pid) {
            try {
                $pool = $this->settlementService->getProjectProfitFromLedgerExcludingCOGS($pid, $tenantId, $to);
                $settlementRevenue += (float) $pool['total_revenue'];
                $settlementExpenses += (float) $pool['total_expenses'];
            } catch (\Throwable $e) {
                $checks[] = $this->buildReconciliationCheck('settlement_vs_ot', 'Settlement vs OT (crop cycle)', 'FAIL', $e->getMessage(), ['error' => $e->getMessage()]);
                return response()->json(['checks' => $checks, 'generated_at' => now()->toIso8601String()]);
            }
        }

        // Check 1: Settlement vs OT (aggregate)
        try {
            $ot = $this->reconciliationService->reconcileCropCycleSettlementVsOT($cropCycleId, $tenantId, $from, $to);
            $revenueDelta = $settlementRevenue - $ot['ot_revenue'];
            $expensesDelta = $settlementExpenses - $ot['ot_expenses_total'];
            $pass = abs($revenueDelta) < self::RECONCILIATION_TOLERANCE && abs($expensesDelta) < self::RECONCILIATION_TOLERANCE;
            $checks[] = $this->buildReconciliationCheck(
                'settlement_vs_ot',
                'Settlement vs OT (crop cycle)',
                $pass ? 'PASS' : 'FAIL',
                $pass ? 'Delta: Rs 0' : sprintf('Revenue delta: %.2f; Expenses delta: %.2f', $revenueDelta, $expensesDelta),
                [
                    'settlement_total_revenue' => $settlementRevenue,
                    'settlement_total_expenses' => $settlementExpenses,
                    'ot_revenue' => $ot['ot_revenue'],
                    'ot_expenses_total' => $ot['ot_expenses_total'],
                    'revenue_delta' => $revenueDelta,
                    'expenses_delta' => $expensesDelta,
                ]
            );
        } catch (\Throwable $e) {
            $checks[] = $this->buildReconciliationCheck('settlement_vs_ot', 'Settlement vs OT (crop cycle)', 'FAIL', $e->getMessage(), ['error' => $e->getMessage()]);
        }

        // Check 2: Ledger vs OT (aggregate, exclude COGS)
        try {
            $ledger = $this->reconciliationService->reconcileCropCycleLedgerIncomeExpense($cropCycleId, $tenantId, $from, $to, true);
            $ot = $this->reconciliationService->reconcileCropCycleSettlementVsOT($cropCycleId, $tenantId, $from, $to);
            $incomeDelta = $ledger['ledger_income'] - $ot['ot_revenue'];
            $expensesDelta = $ledger['ledger_expenses'] - $ot['ot_expenses_total'];
            $pass = abs($incomeDelta) < self::RECONCILIATION_TOLERANCE && abs($expensesDelta) < self::RECONCILIATION_TOLERANCE;
            $checks[] = $this->buildReconciliationCheck(
                'ledger_vs_ot',
                'Ledger vs OT (crop cycle)',
                $pass ? 'PASS' : 'FAIL',
                $pass ? 'Delta: Rs 0' : sprintf('Income delta: %.2f; Expenses delta: %.2f', $incomeDelta, $expensesDelta),
                [
                    'ledger_income' => $ledger['ledger_income'],
                    'ledger_expenses' => $ledger['ledger_expenses'],
                    'ot_revenue' => $ot['ot_revenue'],
                    'ot_expenses_total' => $ot['ot_expenses_total'],
                    'income_delta' => $incomeDelta,
                    'expenses_delta' => $expensesDelta,
                ]
            );
        } catch (\Throwable $e) {
            $checks[] = $this->buildReconciliationCheck('ledger_vs_ot', 'Ledger vs OT (crop cycle)', 'FAIL', $e->getMessage(), ['error' => $e->getMessage()]);
        }

        return response()->json([
            'checks' => $checks,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/reports/reconciliation/supplier-ap
     * Read-only supplier AP reconciliation for one party.
     * Query: party_id (required), from (required), to (required).
     */
    public function reconciliationSupplierAp(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'party_id' => 'required|uuid|exists:parties,id',
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $partyId = $request->input('party_id');
        $from = $request->input('from');
        $to = $request->input('to');
        $checks = [];

        try {
            $ap = $this->reconciliationService->reconcileSupplierAP($partyId, $tenantId, $from, $to);
            $delta = $ap['net_supplier_outstanding'] - $ap['ap_ledger_movement'];
            $attributable = $ap['reconciliation_status'] === 'ATTRIBUTABLE';
            $pass = $attributable && abs($delta) < self::RECONCILIATION_TOLERANCE;
            $warn = !$attributable;
            $status = $pass ? 'PASS' : ($warn ? 'WARN' : 'FAIL');
            $summary = $warn ? 'AP not fully attributable to ledger' : ($pass ? 'Delta: Rs 0' : sprintf('Delta: %.2f', $delta));
            $checks[] = $this->buildReconciliationCheck(
                'supplier_ap',
                'Supplier AP reconciliation',
                $status,
                $summary,
                [
                    'supplier_outstanding' => $ap['supplier_outstanding'],
                    'payment_outstanding' => $ap['payment_outstanding'],
                    'net_supplier_outstanding' => $ap['net_supplier_outstanding'],
                    'ap_ledger_movement' => $ap['ap_ledger_movement'],
                    'reconciliation_status' => $ap['reconciliation_status'],
                    'notes' => $ap['notes'],
                    'delta' => $delta,
                ]
            );
        } catch (\Throwable $e) {
            $checks[] = $this->buildReconciliationCheck('supplier_ap', 'Supplier AP reconciliation', 'FAIL', $e->getMessage(), ['error' => $e->getMessage()]);
        }

        return response()->json([
            'checks' => $checks,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/reports/ar-control-reconciliation
     * Auditor-grade AR Control Account Reconciliation: proves open invoice subledger AR == GL AR (as_of),
     * and explains any delta with unapplied receipts/credits (payments and credit notes post to AR at posting time).
     */
    public function arControlReconciliation(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'as_of' => ['required', 'date', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $asOf = $request->input('as_of');
        $asOfObj = Carbon::parse($asOf);

        // 1) GL AR total: ledger entries for account code AR, posting_date <= as_of
        $glArRow = DB::selectOne("
            SELECT COALESCE(SUM(le.debit_amount - le.credit_amount), 0) AS net
            FROM ledger_entries le
            JOIN accounts a ON a.id = le.account_id AND a.tenant_id = le.tenant_id
            JOIN posting_groups pg ON pg.id = le.posting_group_id
            WHERE pg.tenant_id = :tenant_id
              AND a.code = 'AR'
              AND pg.posting_date <= :as_of
        ", [
            'tenant_id' => $tenantId,
            'as_of' => $asOf,
        ]);
        $glArTotal = (float) ($glArRow->net ?? 0);

        // 2) Subledger: open invoices only (sale_kind INVOICE or NULL), POSTED, not reversed, posting_date <= as_of
        //    Allocations reduce invoices only when status ACTIVE and allocation_date <= as_of
        $subledgerRow = DB::selectOne("
            SELECT COALESCE(SUM(s.amount - COALESCE(a.allocated, 0)), 0) AS open_total
            FROM sales s
            LEFT JOIN (
                SELECT sale_id, SUM(amount) AS allocated
                FROM sale_payment_allocations
                WHERE tenant_id = :tenant_id
                  AND (status = 'ACTIVE' OR status IS NULL)
                  AND allocation_date <= :as_of2
                GROUP BY sale_id
            ) a ON a.sale_id = s.id
            WHERE s.tenant_id = :tenant_id2
              AND (s.sale_kind = 'INVOICE' OR s.sale_kind IS NULL)
              AND s.status = 'POSTED'
              AND s.reversal_posting_group_id IS NULL
              AND s.posting_date <= :as_of3
              AND (s.amount - COALESCE(a.allocated, 0)) > 0
        ", [
            'tenant_id' => $tenantId,
            'tenant_id2' => $tenantId,
            'as_of2' => $asOf,
            'as_of3' => $asOf,
        ]);
        $subledgerOpenInvoicesTotal = (float) ($subledgerRow->open_total ?? 0);

        // 3) Unapplied payments (and credit notes): posted payments that hit AR, minus ACTIVE allocations as_of
        //    Payments IN (CASH/BANK/CREDIT_NOTE) when posted credit AR; include only pg.posting_date <= as_of
        $unappliedRow = DB::selectOne("
            SELECT
                COALESCE(SUM(p.amount), 0) - COALESCE(SUM(alloc.allocated), 0) AS unapplied
            FROM payments p
            JOIN posting_groups pg ON pg.id = p.posting_group_id AND pg.tenant_id = p.tenant_id
            LEFT JOIN (
                SELECT payment_id, SUM(amount) AS allocated
                FROM sale_payment_allocations
                WHERE tenant_id = :tenant_id
                  AND (status = 'ACTIVE' OR status IS NULL)
                  AND allocation_date <= :as_of_alloc
                GROUP BY payment_id
            ) alloc ON alloc.payment_id = p.id
            WHERE p.tenant_id = :tenant_id2
              AND p.direction = 'IN'
              AND p.status = 'POSTED'
              AND p.reversal_posting_group_id IS NULL
              AND p.posting_group_id IS NOT NULL
              AND pg.posting_date <= :as_of_pg
        ", [
            'tenant_id' => $tenantId,
            'tenant_id2' => $tenantId,
            'as_of_alloc' => $asOf,
            'as_of_pg' => $asOf,
        ]);
        $unappliedPaymentsTotal = (float) ($unappliedRow->unapplied ?? 0);

        $delta = $subledgerOpenInvoicesTotal - $glArTotal;
        $explainedDelta = $unappliedPaymentsTotal;

        // 4) Open invoices drilldown: limit 200, order by posting_date asc
        $openInvoices = DB::select("
            SELECT
                s.id AS sale_id,
                s.sale_no AS sale_number,
                s.buyer_party_id,
                par.name AS buyer_name,
                s.posting_date,
                s.due_date,
                s.amount AS invoice_total,
                COALESCE(a.allocated, 0) AS allocated_to_as_of,
                (s.amount - COALESCE(a.allocated, 0)) AS open_balance_as_of
            FROM sales s
            LEFT JOIN parties par ON par.id = s.buyer_party_id AND par.tenant_id = s.tenant_id
            LEFT JOIN (
                SELECT sale_id, SUM(amount) AS allocated
                FROM sale_payment_allocations
                WHERE tenant_id = :tenant_id
                  AND (status = 'ACTIVE' OR status IS NULL)
                  AND allocation_date <= :as_of2
                GROUP BY sale_id
            ) a ON a.sale_id = s.id
            WHERE s.tenant_id = :tenant_id2
              AND (s.sale_kind = 'INVOICE' OR s.sale_kind IS NULL)
              AND s.status = 'POSTED'
              AND s.reversal_posting_group_id IS NULL
              AND s.posting_date <= :as_of3
              AND (s.amount - COALESCE(a.allocated, 0)) > 0
            ORDER BY s.posting_date ASC, s.id ASC
            LIMIT 200
        ", [
            'tenant_id' => $tenantId,
            'tenant_id2' => $tenantId,
            'as_of2' => $asOf,
            'as_of3' => $asOf,
        ]);

        $openInvoicesList = [];
        foreach ($openInvoices as $row) {
            $dueDate = $row->due_date ? Carbon::parse($row->due_date) : Carbon::parse($row->posting_date);
            $daysOverdue = (int) $dueDate->diffInDays($asOfObj, false);
            $openInvoicesList[] = [
                'sale_id' => $row->sale_id,
                'sale_number' => $row->sale_number,
                'buyer_party_id' => $row->buyer_party_id,
                'buyer_name' => $row->buyer_name ?? '',
                'posting_date' => $row->posting_date,
                'due_date' => $row->due_date ?? $row->posting_date,
                'invoice_total' => number_format((float) $row->invoice_total, 2, '.', ''),
                'allocated_to_as_of' => number_format((float) $row->allocated_to_as_of, 2, '.', ''),
                'open_balance_as_of' => number_format((float) $row->open_balance_as_of, 2, '.', ''),
                'days_overdue' => $daysOverdue,
            ];
        }

        return response()->json([
            'as_of' => $asOf,
            'subledger_open_invoices_total' => round($subledgerOpenInvoicesTotal, 2),
            'gl_ar_total' => round($glArTotal, 2),
            'delta' => round($delta, 2),
            'unapplied_payments_total' => round($unappliedPaymentsTotal, 2),
            'explained_delta' => round($explainedDelta, 2),
            'open_invoices' => $openInvoicesList,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/reports/customer-balances
     * Customer balance report (as-of): per-customer open invoices, unapplied receipts/credits, net balance.
     * Read-only; invoice-only for open balance; allocation cutoff allocation_date <= as_of; ACTIVE allocations only.
     */
    public function customerBalances(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'as_of' => ['required', 'date', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $asOf = $request->input('as_of');
        $limit = (int) ($request->input('limit', 200));
        $offset = (int) ($request->input('offset', 0));
        $search = $request->input('search');

        $params = [
            'tenant_id' => $tenantId,
            'as_of' => $asOf,
            'limit' => $limit + 1,
            'offset' => $offset,
        ];

        $searchClause = '';
        if ($search !== null && $search !== '') {
            $searchClause = " AND (par.name ILIKE :search OR par.id::text ILIKE :search)";
            $params['search'] = '%' . $search . '%';
        }

        $rowsSql = "
            WITH invoices_agg AS (
                SELECT s.buyer_party_id,
                    SUM(s.amount - COALESCE(a.allocated, 0)) AS open_invoices_total
                FROM sales s
                LEFT JOIN (
                    SELECT sale_id, SUM(amount) AS allocated
                    FROM sale_payment_allocations
                    WHERE tenant_id = :tenant_id
                      AND (COALESCE(status, 'ACTIVE') = 'ACTIVE')
                      AND allocation_date <= :as_of
                    GROUP BY sale_id
                ) a ON a.sale_id = s.id
                WHERE s.tenant_id = :tenant_id
                  AND (s.sale_kind = 'INVOICE' OR s.sale_kind IS NULL)
                  AND s.status = 'POSTED'
                  AND s.reversal_posting_group_id IS NULL
                  AND s.posting_date <= :as_of
                  AND (s.amount - COALESCE(a.allocated, 0)) > 0
                GROUP BY s.buyer_party_id
            ),
            payments_agg AS (
                SELECT p.party_id AS buyer_party_id,
                    COALESCE(SUM(p.amount), 0) - COALESCE(SUM(alloc.allocated), 0) AS unapplied_total
                FROM payments p
                JOIN posting_groups pg ON pg.id = p.posting_group_id AND pg.tenant_id = p.tenant_id
                LEFT JOIN (
                    SELECT payment_id, SUM(amount) AS allocated
                    FROM sale_payment_allocations
                    WHERE tenant_id = :tenant_id
                      AND (COALESCE(status, 'ACTIVE') = 'ACTIVE')
                      AND allocation_date <= :as_of
                    GROUP BY payment_id
                ) alloc ON alloc.payment_id = p.id
                WHERE p.tenant_id = :tenant_id
                  AND p.direction = 'IN'
                  AND p.status = 'POSTED'
                  AND p.reversal_posting_group_id IS NULL
                  AND p.posting_group_id IS NOT NULL
                  AND pg.posting_date <= :as_of
                GROUP BY p.party_id
            ),
            combined AS (
                SELECT COALESCE(i.buyer_party_id, pa.buyer_party_id) AS buyer_party_id,
                    COALESCE(i.open_invoices_total, 0) AS open_invoices_total,
                    COALESCE(pa.unapplied_total, 0) AS unapplied_total
                FROM invoices_agg i
                FULL OUTER JOIN payments_agg pa ON i.buyer_party_id = pa.buyer_party_id
            )
            SELECT c.buyer_party_id,
                par.name AS buyer_name,
                c.open_invoices_total,
                c.unapplied_total,
                (c.open_invoices_total - c.unapplied_total) AS net_balance
            FROM combined c
            JOIN parties par ON par.id = c.buyer_party_id AND par.tenant_id = :tenant_id
            WHERE (c.open_invoices_total != 0 OR c.unapplied_total != 0)
            {$searchClause}
            ORDER BY par.name ASC, c.buyer_party_id ASC
            LIMIT :limit OFFSET :offset
        ";

        $rows = DB::select($rowsSql, $params);

        $hasMore = false;
        if (count($rows) > $limit) {
            $hasMore = true;
            $rows = array_slice($rows, 0, $limit);
        }

        $totalsSql = "
            WITH invoices_agg AS (
                SELECT s.buyer_party_id, SUM(s.amount - COALESCE(a.allocated, 0)) AS open_invoices_total
                FROM sales s
                LEFT JOIN (
                    SELECT sale_id, SUM(amount) AS allocated FROM sale_payment_allocations
                    WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of
                    GROUP BY sale_id
                ) a ON a.sale_id = s.id
                WHERE s.tenant_id = :tenant_id AND (s.sale_kind = 'INVOICE' OR s.sale_kind IS NULL)
                  AND s.status = 'POSTED' AND s.reversal_posting_group_id IS NULL AND s.posting_date <= :as_of
                  AND (s.amount - COALESCE(a.allocated, 0)) > 0
                GROUP BY s.buyer_party_id
            ),
            payments_agg AS (
                SELECT p.party_id AS buyer_party_id,
                    COALESCE(SUM(p.amount), 0) - COALESCE(SUM(alloc.allocated), 0) AS unapplied_total
                FROM payments p
                JOIN posting_groups pg ON pg.id = p.posting_group_id AND pg.tenant_id = p.tenant_id
                LEFT JOIN (
                    SELECT payment_id, SUM(amount) AS allocated FROM sale_payment_allocations
                    WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of
                    GROUP BY payment_id
                ) alloc ON alloc.payment_id = p.id
                WHERE p.tenant_id = :tenant_id AND p.direction = 'IN' AND p.status = 'POSTED'
                  AND p.reversal_posting_group_id IS NULL AND p.posting_group_id IS NOT NULL AND pg.posting_date <= :as_of
                GROUP BY p.party_id
            ),
            combined AS (
                SELECT COALESCE(i.buyer_party_id, pa.buyer_party_id) AS buyer_party_id,
                    COALESCE(i.open_invoices_total, 0) AS open_invoices_total,
                    COALESCE(pa.unapplied_total, 0) AS unapplied_total
                FROM invoices_agg i
                FULL OUTER JOIN payments_agg pa ON i.buyer_party_id = pa.buyer_party_id
            )
            SELECT COALESCE(SUM(open_invoices_total), 0) AS open_invoices_total,
                   COALESCE(SUM(unapplied_total), 0) AS unapplied_total,
                   COALESCE(SUM(open_invoices_total - unapplied_total), 0) AS net_balance
            FROM combined
            WHERE open_invoices_total != 0 OR unapplied_total != 0
        ";
        $totalsRow = DB::selectOne($totalsSql, ['tenant_id' => $tenantId, 'as_of' => $asOf]);
        $totals = [
            'open_invoices_total' => round((float) ($totalsRow->open_invoices_total ?? 0), 2),
            'unapplied_total' => round((float) ($totalsRow->unapplied_total ?? 0), 2),
            'net_balance' => round((float) ($totalsRow->net_balance ?? 0), 2),
        ];

        $rowsList = array_map(function ($row) {
            return [
                'buyer_party_id' => $row->buyer_party_id,
                'buyer_name' => $row->buyer_name ?? '',
                'open_invoices_total' => round((float) $row->open_invoices_total, 2),
                'unapplied_total' => round((float) $row->unapplied_total, 2),
                'net_balance' => round((float) $row->net_balance, 2),
            ];
        }, $rows);

        return response()->json([
            'as_of' => $asOf,
            'rows' => $rowsList,
            'totals' => $totals,
            'generated_at' => now()->toIso8601String(),
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * GET /api/reports/customer-balance-detail
     * Drilldown for one customer: open_invoices[] and unapplied_instruments[].
     */
    public function customerBalanceDetail(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'as_of' => ['required', 'date', 'date_format:Y-m-d'],
            'buyer_party_id' => ['required', 'uuid', 'exists:parties,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $asOf = $request->input('as_of');
        $buyerPartyId = $request->input('buyer_party_id');
        $asOfObj = Carbon::parse($asOf);

        $openInvoices = DB::select("
            SELECT s.id AS sale_id, s.sale_no AS sale_number, s.buyer_party_id, par.name AS buyer_name,
                s.posting_date, s.due_date, s.amount AS invoice_total,
                COALESCE(a.allocated, 0) AS allocated_to_as_of,
                (s.amount - COALESCE(a.allocated, 0)) AS open_balance_as_of
            FROM sales s
            LEFT JOIN parties par ON par.id = s.buyer_party_id AND par.tenant_id = s.tenant_id
            LEFT JOIN (
                SELECT sale_id, SUM(amount) AS allocated
                FROM sale_payment_allocations
                WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of
                GROUP BY sale_id
            ) a ON a.sale_id = s.id
            WHERE s.tenant_id = :tenant_id AND s.buyer_party_id = :buyer_party_id
              AND (s.sale_kind = 'INVOICE' OR s.sale_kind IS NULL)
              AND s.status = 'POSTED' AND s.reversal_posting_group_id IS NULL AND s.posting_date <= :as_of
              AND (s.amount - COALESCE(a.allocated, 0)) > 0
            ORDER BY s.posting_date ASC, s.id ASC
        ", [
            'tenant_id' => $tenantId,
            'as_of' => $asOf,
            'buyer_party_id' => $buyerPartyId,
        ]);

        $unappliedInstruments = DB::select("
            SELECT p.id AS payment_id, p.method AS payment_method, pg.posting_date, p.amount,
                COALESCE(alloc.allocated, 0) AS allocated_to_as_of,
                (p.amount - COALESCE(alloc.allocated, 0)) AS unapplied_as_of
            FROM payments p
            JOIN posting_groups pg ON pg.id = p.posting_group_id AND pg.tenant_id = p.tenant_id
            LEFT JOIN (
                SELECT payment_id, SUM(amount) AS allocated
                FROM sale_payment_allocations
                WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of
                GROUP BY payment_id
            ) alloc ON alloc.payment_id = p.id
            WHERE p.tenant_id = :tenant_id AND p.party_id = :buyer_party_id
              AND p.direction = 'IN' AND p.status = 'POSTED'
              AND p.reversal_posting_group_id IS NULL AND p.posting_group_id IS NOT NULL
              AND pg.posting_date <= :as_of
              AND (p.amount - COALESCE(alloc.allocated, 0)) > 0
            ORDER BY pg.posting_date ASC, p.id ASC
        ", [
            'tenant_id' => $tenantId,
            'as_of' => $asOf,
            'buyer_party_id' => $buyerPartyId,
        ]);

        $openInvoicesList = array_map(function ($row) use ($asOfObj) {
            $dueDate = $row->due_date ? Carbon::parse($row->due_date) : Carbon::parse($row->posting_date);
            $daysOverdue = (int) $dueDate->diffInDays($asOfObj, false);
            return [
                'sale_id' => $row->sale_id,
                'sale_number' => $row->sale_number,
                'buyer_party_id' => $row->buyer_party_id,
                'buyer_name' => $row->buyer_name ?? '',
                'posting_date' => $row->posting_date,
                'due_date' => $row->due_date ?? $row->posting_date,
                'invoice_total' => number_format((float) $row->invoice_total, 2, '.', ''),
                'allocated_to_as_of' => number_format((float) $row->allocated_to_as_of, 2, '.', ''),
                'open_balance_as_of' => number_format((float) $row->open_balance_as_of, 2, '.', ''),
                'days_overdue' => $daysOverdue,
            ];
        }, $openInvoices);

        $unappliedList = array_map(function ($row) {
            return [
                'payment_id' => $row->payment_id,
                'payment_method' => $row->payment_method,
                'posting_date' => $row->posting_date,
                'amount' => number_format((float) $row->amount, 2, '.', ''),
                'allocated_to_as_of' => number_format((float) $row->allocated_to_as_of, 2, '.', ''),
                'unapplied_as_of' => number_format((float) $row->unapplied_as_of, 2, '.', ''),
            ];
        }, $unappliedInstruments);

        return response()->json([
            'as_of' => $asOf,
            'buyer_party_id' => $buyerPartyId,
            'open_invoices' => $openInvoicesList,
            'unapplied_instruments' => $unappliedList,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/reports/ap-ageing
     * Supplier payables ageing (bill-only). GRNs = bills; allocation cutoff allocation_date <= as_of; ACTIVE only.
     */
    public function apAgeing(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $asOfDate = $request->input('as_of', Carbon::today()->format('Y-m-d'));
        $asOfDateObj = Carbon::parse($asOfDate);

        $billPaymentService = app(BillPaymentService::class);

        $suppliers = Party::where('tenant_id', $tenantId)
            ->whereHas('supplierGrns', function ($q) {
                $q->where('status', 'POSTED');
            })
            ->get();

        $rows = [];
        $totals = [
            'total_outstanding' => 0.0,
            'bucket_0_30' => 0.0,
            'bucket_31_60' => 0.0,
            'bucket_61_90' => 0.0,
            'bucket_90_plus' => 0.0,
        ];

        foreach ($suppliers as $supplier) {
            $openBills = $billPaymentService->getSupplierOpenBills($supplier->id, $tenantId, $asOfDate);
            if (empty($openBills)) {
                continue;
            }

            $supplierTotals = [
                'total_outstanding' => 0.0,
                'bucket_0_30' => 0.0,
                'bucket_31_60' => 0.0,
                'bucket_61_90' => 0.0,
                'bucket_90_plus' => 0.0,
            ];

            foreach ($openBills as $bill) {
                $outstanding = (float) $bill['outstanding'];
                $dueDate = $bill['due_date'] ?? $bill['posting_date'];
                $dueDateObj = Carbon::parse($dueDate);
                $daysOverdue = (int) $asOfDateObj->diffInDays($dueDateObj, false);

                $supplierTotals['total_outstanding'] += $outstanding;
                if ($daysOverdue <= 30) {
                    $supplierTotals['bucket_0_30'] += $outstanding;
                } elseif ($daysOverdue <= 60) {
                    $supplierTotals['bucket_31_60'] += $outstanding;
                } elseif ($daysOverdue <= 90) {
                    $supplierTotals['bucket_61_90'] += $outstanding;
                } else {
                    $supplierTotals['bucket_90_plus'] += $outstanding;
                }
            }

            if ($supplierTotals['total_outstanding'] > 0) {
                $rows[] = [
                    'supplier_party_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'total_outstanding' => number_format($supplierTotals['total_outstanding'], 2, '.', ''),
                    'bucket_0_30' => number_format($supplierTotals['bucket_0_30'], 2, '.', ''),
                    'bucket_31_60' => number_format($supplierTotals['bucket_31_60'], 2, '.', ''),
                    'bucket_61_90' => number_format($supplierTotals['bucket_61_90'], 2, '.', ''),
                    'bucket_90_plus' => number_format($supplierTotals['bucket_90_plus'], 2, '.', ''),
                ];
                $totals['total_outstanding'] += $supplierTotals['total_outstanding'];
                $totals['bucket_0_30'] += $supplierTotals['bucket_0_30'];
                $totals['bucket_31_60'] += $supplierTotals['bucket_31_60'];
                $totals['bucket_61_90'] += $supplierTotals['bucket_61_90'];
                $totals['bucket_90_plus'] += $supplierTotals['bucket_90_plus'];
            }
        }

        return response()->json([
            'as_of' => $asOfDate,
            'buckets' => ['0-30', '31-60', '61-90', '90+'],
            'rows' => $rows,
            'totals' => [
                'total_outstanding' => number_format($totals['total_outstanding'], 2, '.', ''),
                'bucket_0_30' => number_format($totals['bucket_0_30'], 2, '.', ''),
                'bucket_31_60' => number_format($totals['bucket_31_60'], 2, '.', ''),
                'bucket_61_90' => number_format($totals['bucket_61_90'], 2, '.', ''),
                'bucket_90_plus' => number_format($totals['bucket_90_plus'], 2, '.', ''),
            ],
        ]);
    }

    /**
     * GET /api/reports/ap-control-reconciliation
     * AP subledger (open bills) vs GL AP control; unapplied supplier payments explain delta.
     */
    public function apControlReconciliation(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'as_of' => ['required', 'date', 'date_format:Y-m-d'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $asOf = $request->input('as_of');

        $glApRow = DB::selectOne("
            SELECT COALESCE(SUM(le.credit_amount - le.debit_amount), 0) AS net
            FROM ledger_entries le
            JOIN accounts a ON a.id = le.account_id AND a.tenant_id = le.tenant_id
            JOIN posting_groups pg ON pg.id = le.posting_group_id
            WHERE pg.tenant_id = :tenant_id AND a.code = 'AP' AND pg.posting_date <= :as_of
        ", ['tenant_id' => $tenantId, 'as_of' => $asOf]);
        $glApTotal = (float) ($glApRow->net ?? 0);

        $subledgerRow = DB::selectOne("
            SELECT COALESCE(SUM(ar.amount - COALESCE(ga.allocated, 0)), 0) AS open_total
            FROM inv_grns g
            JOIN allocation_rows ar ON ar.posting_group_id = g.posting_group_id AND ar.tenant_id = g.tenant_id
                AND ar.allocation_type = 'SUPPLIER_AP'
            LEFT JOIN (
                SELECT grn_id, SUM(amount) AS allocated
                FROM grn_payment_allocations
                WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of2
                GROUP BY grn_id
            ) ga ON ga.grn_id = g.id
            WHERE g.tenant_id = :tenant_id2 AND g.status = 'POSTED'
              AND g.posting_date <= :as_of3
              AND (ar.amount - COALESCE(ga.allocated, 0)) > 0
        ", [
            'tenant_id' => $tenantId,
            'tenant_id2' => $tenantId,
            'as_of2' => $asOf,
            'as_of3' => $asOf,
        ]);
        $subledgerOpenBillsTotal = (float) ($subledgerRow->open_total ?? 0);

        $unappliedRow = DB::selectOne("
            SELECT COALESCE(SUM(p.amount), 0) - COALESCE(SUM(alloc.allocated), 0) AS unapplied
            FROM payments p
            JOIN posting_groups pg ON pg.id = p.posting_group_id AND pg.tenant_id = p.tenant_id
            LEFT JOIN (
                SELECT payment_id, SUM(amount) AS allocated
                FROM grn_payment_allocations
                WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of_alloc
                GROUP BY payment_id
            ) alloc ON alloc.payment_id = p.id
            WHERE p.tenant_id = :tenant_id2 AND p.direction = 'OUT'
              AND p.status = 'POSTED' AND (p.reversal_posting_group_id IS NULL AND p.reversed_at IS NULL)
              AND p.posting_group_id IS NOT NULL AND pg.posting_date <= :as_of_pg
        ", [
            'tenant_id' => $tenantId,
            'tenant_id2' => $tenantId,
            'as_of_alloc' => $asOf,
            'as_of_pg' => $asOf,
        ]);
        $unappliedSupplierPaymentsTotal = (float) ($unappliedRow->unapplied ?? 0);

        $delta = $subledgerOpenBillsTotal - $glApTotal;
        $explainedDelta = $unappliedSupplierPaymentsTotal;

        $openBills = DB::select("
            SELECT g.id AS grn_id, g.doc_no AS bill_number, g.supplier_party_id, par.name AS supplier_name,
                g.posting_date, g.posting_date AS due_date,
                ar.amount AS bill_total,
                COALESCE(ga.allocated, 0) AS allocated_to_as_of,
                (ar.amount - COALESCE(ga.allocated, 0)) AS open_balance_as_of
            FROM inv_grns g
            JOIN allocation_rows ar ON ar.posting_group_id = g.posting_group_id AND ar.tenant_id = g.tenant_id
                AND ar.allocation_type = 'SUPPLIER_AP'
            LEFT JOIN parties par ON par.id = g.supplier_party_id AND par.tenant_id = g.tenant_id
            LEFT JOIN (
                SELECT grn_id, SUM(amount) AS allocated
                FROM grn_payment_allocations
                WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of2
                GROUP BY grn_id
            ) ga ON ga.grn_id = g.id
            WHERE g.tenant_id = :tenant_id2 AND g.status = 'POSTED' AND g.posting_date <= :as_of3
              AND (ar.amount - COALESCE(ga.allocated, 0)) > 0
            ORDER BY g.posting_date ASC, g.id ASC
            LIMIT 200
        ", [
            'tenant_id' => $tenantId,
            'tenant_id2' => $tenantId,
            'as_of2' => $asOf,
            'as_of3' => $asOf,
        ]);

        $openBillsList = array_map(function ($row) {
            return [
                'grn_id' => $row->grn_id,
                'bill_number' => $row->bill_number,
                'supplier_party_id' => $row->supplier_party_id,
                'supplier_name' => $row->supplier_name ?? '',
                'posting_date' => $row->posting_date,
                'due_date' => $row->due_date,
                'bill_total' => number_format((float) $row->bill_total, 2, '.', ''),
                'allocated_to_as_of' => number_format((float) $row->allocated_to_as_of, 2, '.', ''),
                'open_balance_as_of' => number_format((float) $row->open_balance_as_of, 2, '.', ''),
            ];
        }, $openBills);

        return response()->json([
            'as_of' => $asOf,
            'subledger_open_bills_total' => round($subledgerOpenBillsTotal, 2),
            'gl_ap_total' => round($glApTotal, 2),
            'delta' => round($delta, 2),
            'unapplied_supplier_payments_total' => round($unappliedSupplierPaymentsTotal, 2),
            'explained_delta' => round($explainedDelta, 2),
            'open_bills' => $openBillsList,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/reports/supplier-balances
     * Per-supplier open bills, unapplied payments, net_balance = open_bills - unapplied (positive = we owe supplier).
     */
    public function supplierBalances(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'as_of' => ['required', 'date', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $asOf = $request->input('as_of');
        $limit = (int) ($request->input('limit', 200));
        $offset = (int) ($request->input('offset', 0));
        $search = $request->input('search');
        $params = [
            'tenant_id' => $tenantId,
            'as_of' => $asOf,
            'limit' => $limit + 1,
            'offset' => $offset,
        ];
        $searchClause = '';
        if ($search !== null && $search !== '') {
            $searchClause = " AND (par.name ILIKE :search OR par.id::text ILIKE :search)";
            $params['search'] = '%' . $search . '%';
        }

        $rowsSql = "
            WITH bills_agg AS (
                SELECT g.supplier_party_id,
                    SUM(ar.amount - COALESCE(ga.allocated, 0)) AS open_bills_total
                FROM inv_grns g
                JOIN allocation_rows ar ON ar.posting_group_id = g.posting_group_id AND ar.tenant_id = g.tenant_id
                    AND ar.allocation_type = 'SUPPLIER_AP'
                LEFT JOIN (
                    SELECT grn_id, SUM(amount) AS allocated
                    FROM grn_payment_allocations
                    WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of
                    GROUP BY grn_id
                ) ga ON ga.grn_id = g.id
                WHERE g.tenant_id = :tenant_id AND g.status = 'POSTED' AND g.posting_date <= :as_of
                  AND (ar.amount - COALESCE(ga.allocated, 0)) > 0
                GROUP BY g.supplier_party_id
            ),
            payments_agg AS (
                SELECT p.party_id AS supplier_party_id,
                    COALESCE(SUM(p.amount), 0) - COALESCE(SUM(alloc.allocated), 0) AS unapplied_total
                FROM payments p
                JOIN posting_groups pg ON pg.id = p.posting_group_id AND pg.tenant_id = p.tenant_id
                LEFT JOIN (
                    SELECT payment_id, SUM(amount) AS allocated
                    FROM grn_payment_allocations
                    WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of
                    GROUP BY payment_id
                ) alloc ON alloc.payment_id = p.id
                WHERE p.tenant_id = :tenant_id AND p.direction = 'OUT'
                  AND p.status = 'POSTED' AND p.reversal_posting_group_id IS NULL AND p.reversed_at IS NULL
                  AND p.posting_group_id IS NOT NULL AND pg.posting_date <= :as_of
                GROUP BY p.party_id
            ),
            combined AS (
                SELECT COALESCE(b.supplier_party_id, pa.supplier_party_id) AS supplier_party_id,
                    COALESCE(b.open_bills_total, 0) AS open_bills_total,
                    COALESCE(pa.unapplied_total, 0) AS unapplied_total
                FROM bills_agg b
                FULL OUTER JOIN payments_agg pa ON b.supplier_party_id = pa.supplier_party_id
            )
            SELECT c.supplier_party_id, par.name AS supplier_name,
                c.open_bills_total, c.unapplied_total,
                (c.open_bills_total - c.unapplied_total) AS net_balance
            FROM combined c
            JOIN parties par ON par.id = c.supplier_party_id AND par.tenant_id = :tenant_id
            WHERE (c.open_bills_total != 0 OR c.unapplied_total != 0)
            {$searchClause}
            ORDER BY par.name ASC, c.supplier_party_id ASC
            LIMIT :limit OFFSET :offset
        ";
        $rows = DB::select($rowsSql, $params);
        $hasMore = false;
        if (count($rows) > $limit) {
            $hasMore = true;
            $rows = array_slice($rows, 0, $limit);
        }
        $totalsSql = "
            WITH bills_agg AS (
                SELECT g.supplier_party_id, SUM(ar.amount - COALESCE(ga.allocated, 0)) AS open_bills_total
                FROM inv_grns g
                JOIN allocation_rows ar ON ar.posting_group_id = g.posting_group_id AND ar.tenant_id = g.tenant_id
                    AND ar.allocation_type = 'SUPPLIER_AP'
                LEFT JOIN (SELECT grn_id, SUM(amount) AS allocated FROM grn_payment_allocations
                    WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of
                    GROUP BY grn_id) ga ON ga.grn_id = g.id
                WHERE g.tenant_id = :tenant_id AND g.status = 'POSTED' AND g.posting_date <= :as_of
                  AND (ar.amount - COALESCE(ga.allocated, 0)) > 0
                GROUP BY g.supplier_party_id
            ),
            payments_agg AS (
                SELECT p.party_id AS supplier_party_id,
                    COALESCE(SUM(p.amount), 0) - COALESCE(SUM(alloc.allocated), 0) AS unapplied_total
                FROM payments p
                JOIN posting_groups pg ON pg.id = p.posting_group_id AND pg.tenant_id = p.tenant_id
                LEFT JOIN (SELECT payment_id, SUM(amount) AS allocated FROM grn_payment_allocations
                    WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of
                    GROUP BY payment_id) alloc ON alloc.payment_id = p.id
                WHERE p.tenant_id = :tenant_id AND p.direction = 'OUT' AND p.status = 'POSTED'
                  AND p.reversal_posting_group_id IS NULL AND p.reversed_at IS NULL
                  AND p.posting_group_id IS NOT NULL AND pg.posting_date <= :as_of
                GROUP BY p.party_id
            ),
            combined AS (
                SELECT COALESCE(b.supplier_party_id, pa.supplier_party_id) AS supplier_party_id,
                    COALESCE(b.open_bills_total, 0) AS open_bills_total,
                    COALESCE(pa.unapplied_total, 0) AS unapplied_total
                FROM bills_agg b FULL OUTER JOIN payments_agg pa ON b.supplier_party_id = pa.supplier_party_id
            )
            SELECT COALESCE(SUM(open_bills_total), 0) AS open_bills_total,
                   COALESCE(SUM(unapplied_total), 0) AS unapplied_total,
                   COALESCE(SUM(open_bills_total - unapplied_total), 0) AS net_balance
            FROM combined WHERE open_bills_total != 0 OR unapplied_total != 0
        ";
        $totalsRow = DB::selectOne($totalsSql, ['tenant_id' => $tenantId, 'as_of' => $asOf]);
        $totals = [
            'open_bills_total' => round((float) ($totalsRow->open_bills_total ?? 0), 2),
            'unapplied_total' => round((float) ($totalsRow->unapplied_total ?? 0), 2),
            'net_balance' => round((float) ($totalsRow->net_balance ?? 0), 2),
        ];
        $rowsList = array_map(function ($row) {
            return [
                'supplier_party_id' => $row->supplier_party_id,
                'supplier_name' => $row->supplier_name ?? '',
                'open_bills_total' => round((float) $row->open_bills_total, 2),
                'unapplied_total' => round((float) $row->unapplied_total, 2),
                'net_balance' => round((float) $row->net_balance, 2),
            ];
        }, $rows);
        return response()->json([
            'as_of' => $asOf,
            'rows' => $rowsList,
            'totals' => $totals,
            'generated_at' => now()->toIso8601String(),
            'pagination' => ['limit' => $limit, 'offset' => $offset, 'has_more' => $hasMore],
        ]);
    }

    /**
     * GET /api/reports/supplier-balance-detail
     * Drilldown: open_bills[], unapplied_instruments[] for one supplier.
     */
    public function supplierBalanceDetail(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'as_of' => ['required', 'date', 'date_format:Y-m-d'],
            'supplier_party_id' => ['required', 'uuid', 'exists:parties,id'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $asOf = $request->input('as_of');
        $supplierPartyId = $request->input('supplier_party_id');

        $openBills = DB::select("
            SELECT g.id AS grn_id, g.doc_no AS bill_number, g.supplier_party_id, par.name AS supplier_name,
                g.posting_date, g.posting_date AS due_date,
                ar.amount AS bill_total,
                COALESCE(ga.allocated, 0) AS allocated_to_as_of,
                (ar.amount - COALESCE(ga.allocated, 0)) AS open_balance_as_of
            FROM inv_grns g
            JOIN allocation_rows ar ON ar.posting_group_id = g.posting_group_id AND ar.tenant_id = g.tenant_id
                AND ar.allocation_type = 'SUPPLIER_AP'
            LEFT JOIN parties par ON par.id = g.supplier_party_id AND par.tenant_id = g.tenant_id
            LEFT JOIN (
                SELECT grn_id, SUM(amount) AS allocated
                FROM grn_payment_allocations
                WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of
                GROUP BY grn_id
            ) ga ON ga.grn_id = g.id
            WHERE g.tenant_id = :tenant_id AND g.supplier_party_id = :supplier_party_id
              AND g.status = 'POSTED' AND g.posting_date <= :as_of
              AND (ar.amount - COALESCE(ga.allocated, 0)) > 0
            ORDER BY g.posting_date ASC, g.id ASC
        ", ['tenant_id' => $tenantId, 'as_of' => $asOf, 'supplier_party_id' => $supplierPartyId]);

        $unappliedInstruments = DB::select("
            SELECT p.id AS payment_id, p.method AS payment_method, pg.posting_date, p.amount,
                COALESCE(alloc.allocated, 0) AS allocated_to_as_of,
                (p.amount - COALESCE(alloc.allocated, 0)) AS unapplied_as_of
            FROM payments p
            JOIN posting_groups pg ON pg.id = p.posting_group_id AND pg.tenant_id = p.tenant_id
            LEFT JOIN (
                SELECT payment_id, SUM(amount) AS allocated
                FROM grn_payment_allocations
                WHERE tenant_id = :tenant_id AND (COALESCE(status, 'ACTIVE') = 'ACTIVE') AND allocation_date <= :as_of
                GROUP BY payment_id
            ) alloc ON alloc.payment_id = p.id
            WHERE p.tenant_id = :tenant_id AND p.party_id = :supplier_party_id
              AND p.direction = 'OUT' AND p.status = 'POSTED'
              AND p.reversal_posting_group_id IS NULL AND p.reversed_at IS NULL
              AND p.posting_group_id IS NOT NULL AND pg.posting_date <= :as_of
              AND (p.amount - COALESCE(alloc.allocated, 0)) > 0
            ORDER BY pg.posting_date ASC, p.id ASC
        ", ['tenant_id' => $tenantId, 'as_of' => $asOf, 'supplier_party_id' => $supplierPartyId]);

        $openBillsList = array_map(function ($row) {
            return [
                'grn_id' => $row->grn_id,
                'bill_number' => $row->bill_number,
                'supplier_party_id' => $row->supplier_party_id,
                'supplier_name' => $row->supplier_name ?? '',
                'posting_date' => $row->posting_date,
                'due_date' => $row->due_date,
                'bill_total' => number_format((float) $row->bill_total, 2, '.', ''),
                'allocated_to_as_of' => number_format((float) $row->allocated_to_as_of, 2, '.', ''),
                'open_balance_as_of' => number_format((float) $row->open_balance_as_of, 2, '.', ''),
            ];
        }, $openBills);
        $unappliedList = array_map(function ($row) {
            return [
                'payment_id' => $row->payment_id,
                'payment_method' => $row->payment_method,
                'posting_date' => $row->posting_date,
                'amount' => number_format((float) $row->amount, 2, '.', ''),
                'allocated_to_as_of' => number_format((float) $row->allocated_to_as_of, 2, '.', ''),
                'unapplied_as_of' => number_format((float) $row->unapplied_as_of, 2, '.', ''),
            ];
        }, $unappliedInstruments);

        return response()->json([
            'as_of' => $asOf,
            'supplier_party_id' => $supplierPartyId,
            'open_bills' => $openBillsList,
            'unapplied_instruments' => $unappliedList,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param array<string, mixed> $details
     * @return array{key: string, title: string, status: string, summary: string, details: array<string, mixed>}
     */
    private function buildReconciliationCheck(string $key, string $title, string $status, string $summary, array $details): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'status' => $status,
            'summary' => $summary,
            'details' => $details,
        ];
    }
}

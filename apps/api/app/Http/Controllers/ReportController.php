<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use App\Services\SettlementService;
use App\Services\SaleARService;
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
    /**
     * GET /api/reports/trial-balance
     * Returns trial balance by account for a date range
     * Trial Balance is a global ledger report, not project-scoped
     */
    public function trialBalance(Request $request): JsonResponse
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
        
        // Use direct query with new column names
        // Trial Balance is global - ignore project_id entirely
        $query = "
            SELECT
                a.id AS account_id,
                a.code AS account_code,
                a.name AS account_name,
                a.type AS account_type,
                le.currency_code,
                COALESCE(SUM(le.debit_amount), 0) AS total_debit,
                COALESCE(SUM(le.credit_amount), 0) AS total_credit,
                COALESCE(SUM(le.debit_amount - le.credit_amount), 0) AS net
            FROM ledger_entries le
            JOIN accounts a ON a.id = le.account_id
            JOIN posting_groups pg ON pg.id = le.posting_group_id
            WHERE pg.tenant_id = :tenant_id
                AND pg.posting_date BETWEEN :from AND :to
            GROUP BY a.id, a.code, a.name, a.type, le.currency_code
            ORDER BY a.code
        ";
        
        $params = [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ];
        
        try {
            $results = DB::select($query, $params);
            
            // Convert stdClass to array for JSON response
            $rows = array_map(function ($row) {
                return [
                    'account_id' => $row->account_id,
                    'account_code' => $row->account_code,
                    'account_name' => $row->account_name,
                    'account_type' => $row->account_type,
                    'currency_code' => $row->currency_code,
                    'total_debit' => (string) $row->total_debit,
                    'total_credit' => (string) $row->total_credit,
                    'net' => (string) $row->net,
                ];
            }, $results);
            
            return response()->json($rows);
        } catch (\Exception $e) {
            // Return empty array on error instead of 500
            return response()->json([]);
        }
    }
    
    /**
     * GET /api/reports/general-ledger
     * Returns ledger line items (chronological) with pagination
     */
    public function generalLedger(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'account_id' => 'nullable|uuid',
            'project_id' => 'nullable|uuid',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:1000',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $from = $request->input('from');
        $to = $request->input('to');
        $accountId = $request->input('account_id');
        $projectId = $request->input('project_id');
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 50);
        
        // Use direct query with new column names
        $query = "
            SELECT
                pg.posting_date,
                pg.id AS posting_group_id,
                pg.source_type,
                pg.source_id,
                pg.reversal_of_posting_group_id,
                pg.correction_reason,
                le.id AS ledger_entry_id,
                a.id AS account_id,
                a.code AS account_code,
                a.name AS account_name,
                a.type AS account_type,
                le.currency_code,
                le.debit_amount AS debit,
                le.credit_amount AS credit,
                (le.debit_amount - le.credit_amount) AS net
            FROM ledger_entries le
            JOIN accounts a ON a.id = le.account_id
            JOIN posting_groups pg ON pg.id = le.posting_group_id
            WHERE le.tenant_id = :tenant_id
                AND pg.posting_date BETWEEN :from AND :to
        ";
        
        $params = [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ];
        
        if ($accountId) {
            $query .= " AND account_id = :account_id";
            $params['account_id'] = $accountId;
        }
        
        if ($projectId) {
            // Join with allocation_rows to filter by project
            $query .= " AND EXISTS (
                SELECT 1 FROM allocation_rows ar 
                WHERE ar.posting_group_id = pg.id 
                AND ar.project_id = :project_id
            )";
            $params['project_id'] = $projectId;
        }
        
        $query .= " ORDER BY pg.posting_date ASC, le.id ASC";
        
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) as total FROM ({$query}) AS subquery";
        $total = DB::selectOne($countQuery, $params)->total;
        
        // Apply pagination
        $offset = ($page - 1) * $perPage;
        $query .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $perPage;
        $params['offset'] = $offset;
        
        $results = DB::select($query, $params);
        
        $rows = array_map(function ($row) {
            return [
                'posting_date' => $row->posting_date,
                'posting_group_id' => $row->posting_group_id,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'reversal_of_posting_group_id' => $row->reversal_of_posting_group_id,
                'correction_reason' => $row->correction_reason,
                'ledger_entry_id' => $row->ledger_entry_id,
                'account_id' => $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'account_type' => $row->account_type,
                'currency_code' => $row->currency_code,
                'debit' => (string) $row->debit,
                'credit' => (string) $row->credit,
                'net' => (string) $row->net,
            ];
        }, $results);
        
        return response()->json([
            'data' => $rows,
            'pagination' => [
                'page' => (int) $page,
                'per_page' => (int) $perPage,
                'total' => (int) $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ]);
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
        
        // Use direct query with new column names
        $query = "
            SELECT
                ar.project_id,
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
                AND pg.posting_date BETWEEN :from AND :to
        ";
        
        $params = [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ];
        
        if ($projectId) {
            $query .= " AND project_id = :project_id";
            $params['project_id'] = $projectId;
        }
        
        $query .= " GROUP BY project_id, currency_code
                    ORDER BY project_id";
        
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
        
        // Use direct query with new column names
        $query = "
            SELECT
                cc.id AS crop_cycle_id,
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
            JOIN allocation_rows ar ON ar.posting_group_id = pg.id
            JOIN projects p ON p.id = ar.project_id
            JOIN crop_cycles cc ON cc.id = pg.crop_cycle_id
            WHERE le.tenant_id = :tenant_id
                AND pg.posting_date BETWEEN :from AND :to
        ";
        
        $params = [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ];
        
        if ($cropCycleId) {
            $query .= " AND cc.id = :crop_cycle_id";
            $params['crop_cycle_id'] = $cropCycleId;
        }
        
        $query .= " GROUP BY cc.id, cc.name, v.currency_code
                    ORDER BY cc.id";
        
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
}

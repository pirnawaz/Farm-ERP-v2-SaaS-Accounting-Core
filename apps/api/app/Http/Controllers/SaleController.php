<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Party;
use App\Models\Project;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\UpdateSaleRequest;
use App\Http\Requests\PostSaleRequest;
use App\Services\TenantContext;
use App\Services\SaleService;
use App\Services\SaleCOGSService;
use App\Models\SaleLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function __construct(
        private SaleService $saleService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $query = Sale::where('tenant_id', $tenantId)
            ->with(['buyerParty', 'project', 'cropCycle', 'postingGroup', 'lines.item', 'lines.store']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('buyer_party_id')) {
            $query->where('buyer_party_id', $request->buyer_party_id);
        }

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->has('date_from')) {
            $query->where('posting_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('posting_date', '<=', $request->date_to);
        }

        $sales = $query->orderBy('posting_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($sales);
    }

    public function store(StoreSaleRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        // Verify buyer party belongs to tenant
        Party::where('id', $request->buyer_party_id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Verify project belongs to tenant if provided
        if ($request->project_id) {
            Project::where('id', $request->project_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        // Set sale_date and due_date defaults
        $saleDate = $request->sale_date ?? $request->posting_date;
        $dueDate = $request->due_date ?? $saleDate;

        // Calculate amount from sale_lines if provided, otherwise use provided amount
        $amount = $request->amount;
        if ($request->has('sale_lines') && is_array($request->sale_lines) && count($request->sale_lines) > 0) {
            $amount = 0;
            foreach ($request->sale_lines as $line) {
                $lineTotal = (float) $line['quantity'] * (float) $line['unit_price'];
                $amount += $lineTotal;
            }
        }

        $saleNo = $request->filled('sale_no') ? trim($request->sale_no) : null;
        if ($saleNo === '') {
            $saleNo = null;
        }

        $sale = DB::transaction(function () use ($request, $tenantId, $saleDate, $dueDate, $amount, $saleNo) {
            if ($saleNo === null) {
                $saleNo = $this->generateSaleNo($tenantId);
            }
            $sale = Sale::create([
                'tenant_id' => $tenantId,
                'buyer_party_id' => $request->buyer_party_id,
                'project_id' => $request->project_id,
                'crop_cycle_id' => $request->crop_cycle_id,
                'amount' => $amount,
                'posting_date' => $request->posting_date,
                'sale_no' => $saleNo,
                'sale_date' => $saleDate,
                'due_date' => $dueDate,
                'notes' => $request->notes,
                'status' => 'DRAFT',
            ]);

            // Create sale lines if provided
            if ($request->has('sale_lines') && is_array($request->sale_lines)) {
                foreach ($request->sale_lines as $lineData) {
                    $lineTotal = (float) $lineData['quantity'] * (float) $lineData['unit_price'];
                    SaleLine::create([
                        'tenant_id' => $tenantId,
                        'sale_id' => $sale->id,
                        'inventory_item_id' => $lineData['inventory_item_id'],
                        'store_id' => $lineData['store_id'],
                        'quantity' => $lineData['quantity'],
                        'uom' => $lineData['uom'] ?? null,
                        'unit_price' => $lineData['unit_price'],
                        'line_total' => $lineTotal,
                    ]);
                }
            }

            return $sale;
        });

        return response()->json($sale->load(['buyerParty', 'project', 'cropCycle', 'lines.item', 'lines.store']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $sale = Sale::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['buyerParty', 'project', 'cropCycle', 'postingGroup', 'lines.item', 'lines.store', 'inventoryAllocations.item', 'reversalPostingGroup'])
            ->firstOrFail();

        return response()->json($sale);
    }

    public function update(UpdateSaleRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $sale = Sale::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'DRAFT')
            ->firstOrFail();

        // Verify buyer party belongs to tenant if changed
        if ($request->has('buyer_party_id')) {
            Party::where('id', $request->buyer_party_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        // Verify project belongs to tenant if changed
        if ($request->has('project_id') && $request->project_id) {
            Project::where('id', $request->project_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        $sale = DB::transaction(function () use ($sale, $request, $tenantId) {
            // Calculate amount from sale_lines if provided
            $amount = $sale->amount;
            if ($request->has('sale_lines') && is_array($request->sale_lines) && count($request->sale_lines) > 0) {
                $amount = 0;
                foreach ($request->sale_lines as $line) {
                    $lineTotal = (float) $line['quantity'] * (float) $line['unit_price'];
                    $amount += $lineTotal;
                }
            }

            // Update sale
            $updateData = $request->validated();
            $updateData['amount'] = $amount;
            $sale->update($updateData);

            // Update sale lines if provided
            if ($request->has('sale_lines') && is_array($request->sale_lines)) {
                // Delete existing lines
                $sale->lines()->delete();

                // Create new lines
                foreach ($request->sale_lines as $lineData) {
                    $lineTotal = (float) $lineData['quantity'] * (float) $lineData['unit_price'];
                    SaleLine::create([
                        'tenant_id' => $tenantId,
                        'sale_id' => $sale->id,
                        'inventory_item_id' => $lineData['inventory_item_id'],
                        'store_id' => $lineData['store_id'],
                        'quantity' => $lineData['quantity'],
                        'uom' => $lineData['uom'] ?? null,
                        'unit_price' => $lineData['unit_price'],
                        'line_total' => $lineTotal,
                    ]);
                }
            }

            return $sale->fresh();
        });

        return response()->json($sale->load(['buyerParty', 'project', 'cropCycle', 'lines.item', 'lines.store']));
    }

    private function generateSaleNo(string $tenantId): string
    {
        $last = Sale::where('tenant_id', $tenantId)
            ->whereNotNull('sale_no')
            ->where('sale_no', 'like', 'SALE-%')
            ->orderByRaw('LENGTH(sale_no) DESC, sale_no DESC')
            ->first();

        $next = 1;
        if ($last && preg_match('/^SALE-(\d+)$/', $last->sale_no, $m)) {
            $next = (int) $m[1] + 1;
        }

        return 'SALE-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $sale = Sale::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'DRAFT')
            ->firstOrFail();

        $sale->delete();

        return response()->json(null, 204);
    }

    public function post(PostSaleRequest $request, string $id)
    {
        $this->authorizePosting($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $userRole = $request->attributes->get('user_role');

        $postingGroup = $this->saleService->postSale(
            $id,
            $tenantId,
            $request->posting_date,
            $request->idempotency_key,
            $userRole
        );

        // Log audit event
        $this->logAudit($request, 'Sale', $id, 'POST', [
            'posting_date' => $request->posting_date,
            'posting_group_id' => $postingGroup->id,
        ]);

        return response()->json($postingGroup, 201);
    }

    public function reverse(Request $request, string $id)
    {
        $this->authorizeReversal($request);
        
        $tenantId = TenantContext::getTenantId($request);

        $sale = Sale::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'reversal_date' => ['required', 'date', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $sale = $this->cogsService->reverseSale(
                $sale,
                $request->reversal_date,
                $request->reason ?? ''
            );
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Log audit event
        $this->logAudit($request, 'Sale', $id, 'REVERSE', [
            'reversal_date' => $request->reversal_date,
            'reason' => $request->reason ?? '',
            'reversal_posting_group_id' => $sale->reversal_posting_group_id ?? null,
        ]);

        return response()->json($sale->load([
            'buyerParty',
            'project',
            'cropCycle',
            'postingGroup',
            'reversalPostingGroup',
            'lines.item',
            'lines.store',
            'inventoryAllocations'
        ]), 200);
    }
}

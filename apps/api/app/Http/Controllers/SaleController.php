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
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(
        private SaleService $saleService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $query = Sale::where('tenant_id', $tenantId)
            ->with(['buyerParty', 'project', 'cropCycle', 'postingGroup']);

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

        // Set sale_date and due_date defaults
        $saleDate = $request->sale_date ?? $request->posting_date;
        $dueDate = $request->due_date ?? $saleDate;

        $sale = Sale::create([
            'tenant_id' => $tenantId,
            'buyer_party_id' => $request->buyer_party_id,
            'project_id' => $request->project_id,
            'crop_cycle_id' => $request->crop_cycle_id,
            'amount' => $request->amount,
            'posting_date' => $request->posting_date,
            'sale_no' => $request->sale_no,
            'sale_date' => $saleDate,
            'due_date' => $dueDate,
            'notes' => $request->notes,
            'status' => 'DRAFT',
        ]);

        return response()->json($sale->load(['buyerParty', 'project', 'cropCycle']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $sale = Sale::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['buyerParty', 'project', 'cropCycle', 'postingGroup'])
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

        $sale->update($request->validated());

        return response()->json($sale->load(['buyerParty', 'project', 'cropCycle']));
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
        $tenantId = TenantContext::getTenantId($request);
        $userRole = $request->attributes->get('user_role');

        $postingGroup = $this->saleService->postSale(
            $id,
            $tenantId,
            $request->posting_date,
            $request->idempotency_key,
            $userRole
        );

        return response()->json($postingGroup, 201);
    }
}

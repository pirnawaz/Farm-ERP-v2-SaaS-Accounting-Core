<?php

namespace App\Http\Controllers\Machinery;

use App\Http\Controllers\Controller;
use App\Models\MachineryService;
use App\Models\Machine;
use App\Models\Project;
use App\Models\MachineRateCard;
use App\Services\Machinery\MachineryServicePostingService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MachineryServiceController extends Controller
{
    public function __construct(
        private MachineryServicePostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = MachineryService::where('tenant_id', $tenantId)
            ->with(['machine', 'project', 'rateCard', 'postingGroup', 'reversalPostingGroup', 'inKindItem', 'inKindStore', 'inKindInventoryIssue']);

        if ($request->filled('machine_id')) {
            $query->where('machine_id', $request->machine_id);
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from')) {
            $query->where('posting_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('posting_date', '<=', $request->to);
        }

        $items = $query->orderBy('created_at', 'desc')->get();
        return response()->json($items)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $service = MachineryService::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['machine', 'project', 'rateCard', 'postingGroup', 'reversalPostingGroup', 'inKindItem', 'inKindStore', 'inKindInventoryIssue'])
            ->firstOrFail();
        return response()->json($service);
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'machine_id' => ['required', 'uuid', 'exists:machines,id'],
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'rate_card_id' => ['required', 'uuid', 'exists:machine_rate_cards,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'allocation_scope' => ['required', Rule::in([MachineryService::ALLOCATION_SCOPE_SHARED, MachineryService::ALLOCATION_SCOPE_HARI_ONLY])],
            'in_kind_item_id' => ['nullable', 'uuid', 'exists:inv_items,id'],
            'in_kind_rate_per_unit' => ['nullable', 'numeric', 'gte:0', 'required_if:in_kind_item_id,*'],
            'in_kind_store_id' => ['nullable', 'uuid', 'exists:inv_stores,id', 'required_if:in_kind_item_id,*'],
        ]);

        Machine::where('id', $validated['machine_id'])->where('tenant_id', $tenantId)->firstOrFail();
        $project = Project::where('id', $validated['project_id'])->where('tenant_id', $tenantId)->firstOrFail();
        MachineRateCard::where('id', $validated['rate_card_id'])->where('tenant_id', $tenantId)->firstOrFail();

        $service = DB::transaction(function () use ($tenantId, $validated) {
            $attrs = [
                'tenant_id' => $tenantId,
                'machine_id' => $validated['machine_id'],
                'project_id' => $validated['project_id'],
                'rate_card_id' => $validated['rate_card_id'],
                'quantity' => (string) $validated['quantity'],
                'allocation_scope' => $validated['allocation_scope'],
                'status' => MachineryService::STATUS_DRAFT,
            ];
            if (!empty($validated['in_kind_item_id'])) {
                $attrs['in_kind_item_id'] = $validated['in_kind_item_id'];
                $attrs['in_kind_rate_per_unit'] = (string) ($validated['in_kind_rate_per_unit'] ?? 0);
                $attrs['in_kind_store_id'] = $validated['in_kind_store_id'] ?? null;
            }
            return MachineryService::create($attrs);
        });

        return response()->json($service->fresh(['machine', 'project', 'rateCard']), 201);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $service = MachineryService::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', MachineryService::STATUS_DRAFT)
            ->firstOrFail();

        $validated = $request->validate([
            'rate_card_id' => ['sometimes', 'uuid', 'exists:machine_rate_cards,id'],
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
            'allocation_scope' => ['sometimes', Rule::in([MachineryService::ALLOCATION_SCOPE_SHARED, MachineryService::ALLOCATION_SCOPE_HARI_ONLY])],
            'in_kind_item_id' => ['nullable', 'uuid', 'exists:inv_items,id'],
            'in_kind_rate_per_unit' => ['nullable', 'numeric', 'gte:0', 'required_if:in_kind_item_id,*'],
            'in_kind_store_id' => ['nullable', 'uuid', 'exists:inv_stores,id', 'required_if:in_kind_item_id,*'],
        ]);

        if (isset($validated['rate_card_id'])) {
            MachineRateCard::where('id', $validated['rate_card_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        if (array_key_exists('rate_card_id', $validated)) {
            $service->rate_card_id = $validated['rate_card_id'];
        }
        if (array_key_exists('quantity', $validated)) {
            $service->quantity = (string) $validated['quantity'];
        }
        if (array_key_exists('allocation_scope', $validated)) {
            $service->allocation_scope = $validated['allocation_scope'];
        }
        if (array_key_exists('in_kind_item_id', $validated)) {
            $service->in_kind_item_id = $validated['in_kind_item_id'];
            $service->in_kind_rate_per_unit = isset($validated['in_kind_rate_per_unit']) ? (string) $validated['in_kind_rate_per_unit'] : null;
            $service->in_kind_store_id = $validated['in_kind_store_id'] ?? null;
        }
        $service->save();

        return response()->json($service->fresh(['machine', 'project', 'rateCard']));
    }

    public function post(Request $request, string $id)
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');

        $validated = $request->validate([
            'posting_date' => ['required', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $pg = $this->postingService->postService(
            $id,
            $tenantId,
            $validated['posting_date'],
            $idempotencyKey
        );

        $service = MachineryService::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['machine', 'project', 'rateCard', 'postingGroup', 'reversalPostingGroup'])
            ->firstOrFail();

        return response()->json([
            'posting_group' => $pg,
            'machinery_service' => $service,
        ], 201);
    }

    public function reverse(Request $request, string $id)
    {
        $this->authorizeReversal($request);

        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'posting_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $pg = $this->postingService->reverseService(
            $id,
            $tenantId,
            $validated['posting_date'],
            $validated['reason'] ?? null
        );

        $service = MachineryService::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['machine', 'project', 'rateCard', 'postingGroup', 'reversalPostingGroup'])
            ->firstOrFail();

        return response()->json([
            'posting_group' => $pg,
            'machinery_service' => $service,
        ], 201);
    }
}

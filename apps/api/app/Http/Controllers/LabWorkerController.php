<?php

namespace App\Http\Controllers;

use App\Models\LabWorker;
use App\Models\LabWorkerBalance;
use App\Models\Party;
use App\Http\Requests\StoreLabWorkerRequest;
use App\Http\Requests\UpdateLabWorkerRequest;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class LabWorkerController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = LabWorker::where('tenant_id', $tenantId)->with(['party', 'balance']);

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('worker_type')) {
            $query->where('worker_type', $request->worker_type);
        }
        if ($request->filled('q')) {
            $query->where('name', 'ilike', '%' . $request->q . '%');
        }

        $workers = $query->orderBy('name')->get();
        return response()->json($workers);
    }

    public function store(StoreLabWorkerRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $partyId = null;
        if ($request->boolean('create_party')) {
            $party = Party::create([
                'tenant_id' => $tenantId,
                'name' => $request->name,
                'party_types' => ['HARI'],
            ]);
            $partyId = $party->id;
        }

        $worker = LabWorker::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'worker_no' => $request->worker_no,
            'worker_type' => $request->worker_type ?? 'HARI',
            'rate_basis' => $request->rate_basis ?? 'DAILY',
            'default_rate' => $request->default_rate,
            'phone' => $request->phone,
            'is_active' => $request->boolean('is_active', true),
            'party_id' => $partyId,
        ]);

        LabWorkerBalance::getOrCreate($tenantId, $worker->id);

        return response()->json($worker->load(['party', 'balance']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $worker = LabWorker::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['party', 'balance', 'workLogs'])
            ->firstOrFail();
        return response()->json($worker);
    }

    public function update(UpdateLabWorkerRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $worker = LabWorker::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $data = $request->only(['name', 'worker_no', 'worker_type', 'rate_basis', 'default_rate', 'phone', 'is_active']);
        $data = array_filter($data, fn ($v) => $v !== null);
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }
        $worker->update($data);

        return response()->json($worker->fresh(['party', 'balance']));
    }
}

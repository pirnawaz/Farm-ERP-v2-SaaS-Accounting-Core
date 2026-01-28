<?php

namespace App\Http\Controllers\Machinery;

use App\Http\Controllers\Controller;
use App\Models\MachineMaintenanceJob;
use App\Models\MachineMaintenanceJobLine;
use App\Models\Machine;
use App\Models\Party;
use App\Services\Machinery\MachineMaintenancePostingService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MachineMaintenanceJobController extends Controller
{
    private const PREFIX = 'MMJ-';
    private const PAD_LENGTH = 6;

    public function __construct(
        private MachineMaintenancePostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = MachineMaintenanceJob::where('tenant_id', $tenantId)
            ->with(['machine', 'maintenanceType', 'vendorParty', 'postingGroup', 'reversalPostingGroup']);

        if ($request->filled('machine_id')) {
            $query->where('machine_id', $request->machine_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from')) {
            $query->where('job_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('job_date', '<=', $request->to);
        }
        if ($request->filled('vendor_party_id')) {
            $query->where('vendor_party_id', $request->vendor_party_id);
        }

        $jobs = $query->orderBy('job_date', 'desc')->orderBy('created_at', 'desc')->get();
        return response()->json($jobs)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('Vary', 'X-Tenant-Id');
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $job = MachineMaintenanceJob::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with([
                'machine',
                'maintenanceType',
                'vendorParty',
                'lines',
                'postingGroup',
                'reversalPostingGroup'
            ])
            ->firstOrFail();
        return response()->json($job);
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'machine_id' => ['required', 'uuid', 'exists:machines,id'],
            'maintenance_type_id' => ['nullable', 'uuid', 'exists:machine_maintenance_types,id'],
            'vendor_party_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'job_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        // Verify machine belongs to tenant
        Machine::where('id', $validated['machine_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Verify vendor party if provided
        if (!empty($validated['vendor_party_id'])) {
            Party::where('id', $validated['vendor_party_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        return DB::transaction(function () use ($tenantId, $validated) {
            $jobNo = $this->generateJobNo($tenantId);
            $totalAmount = 0;

            foreach ($validated['lines'] as $lineData) {
                $totalAmount += (float) $lineData['amount'];
            }

            $job = MachineMaintenanceJob::create([
                'tenant_id' => $tenantId,
                'job_no' => $jobNo,
                'status' => MachineMaintenanceJob::STATUS_DRAFT,
                'machine_id' => $validated['machine_id'],
                'maintenance_type_id' => $validated['maintenance_type_id'] ?? null,
                'vendor_party_id' => $validated['vendor_party_id'] ?? null,
                'job_date' => $validated['job_date'],
                'notes' => $validated['notes'] ?? null,
                'total_amount' => $totalAmount,
            ]);

            foreach ($validated['lines'] as $lineData) {
                MachineMaintenanceJobLine::create([
                    'tenant_id' => $tenantId,
                    'job_id' => $job->id,
                    'description' => $lineData['description'] ?? null,
                    'amount' => (string) $lineData['amount'],
                ]);
            }

            return response()->json($job->fresh(['machine', 'maintenanceType', 'vendorParty', 'lines']), 201);
        });
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $job = MachineMaintenanceJob::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', MachineMaintenanceJob::STATUS_DRAFT)
            ->with('lines')
            ->firstOrFail();

        $validated = $request->validate([
            'maintenance_type_id' => ['nullable', 'uuid', 'exists:machine_maintenance_types,id'],
            'vendor_party_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'job_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'gt:0'],
        ]);

        return DB::transaction(function () use ($job, $tenantId, $validated) {
            // Update header fields
            if (isset($validated['job_date'])) {
                $job->job_date = $validated['job_date'];
            }
            if (isset($validated['maintenance_type_id'])) {
                $job->maintenance_type_id = $validated['maintenance_type_id'];
            }
            if (isset($validated['vendor_party_id'])) {
                // Verify party belongs to tenant
                Party::where('id', $validated['vendor_party_id'])
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();
                $job->vendor_party_id = $validated['vendor_party_id'];
            }
            if (array_key_exists('notes', $validated)) {
                $job->notes = $validated['notes'];
            }

            // Replace lines if provided
            if (isset($validated['lines'])) {
                MachineMaintenanceJobLine::where('job_id', $job->id)->delete();

                $totalAmount = 0;
                foreach ($validated['lines'] as $lineData) {
                    MachineMaintenanceJobLine::create([
                        'tenant_id' => $tenantId,
                        'job_id' => $job->id,
                        'description' => $lineData['description'] ?? null,
                        'amount' => (string) $lineData['amount'],
                    ]);
                    $totalAmount += (float) $lineData['amount'];
                }
                $job->total_amount = $totalAmount;
            }

            $job->save();

            return response()->json($job->fresh(['machine', 'maintenanceType', 'vendorParty', 'lines']));
        });
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $job = MachineMaintenanceJob::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', MachineMaintenanceJob::STATUS_DRAFT)
            ->firstOrFail();

        $job->delete();

        return response()->json(null, 204);
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

        $pg = $this->postingService->postJob(
            $id,
            $tenantId,
            $validated['posting_date'],
            $idempotencyKey
        );

        $job = MachineMaintenanceJob::where('id', $id)->where('tenant_id', $tenantId)
            ->with([
                'machine',
                'maintenanceType',
                'vendorParty',
                'lines',
                'postingGroup',
                'reversalPostingGroup'
            ])
            ->firstOrFail();

        return response()->json([
            'posting_group' => $pg,
            'job' => $job,
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

        $pg = $this->postingService->reverseJob(
            $id,
            $tenantId,
            $validated['posting_date'],
            $validated['reason'] ?? null
        );

        $job = MachineMaintenanceJob::where('id', $id)->where('tenant_id', $tenantId)
            ->with([
                'machine',
                'maintenanceType',
                'vendorParty',
                'lines',
                'postingGroup',
                'reversalPostingGroup'
            ])
            ->firstOrFail();

        return response()->json([
            'posting_group' => $pg,
            'job' => $job,
        ], 201);
    }

    /**
     * Generate unique job number for tenant.
     */
    private function generateJobNo(string $tenantId): string
    {
        $last = MachineMaintenanceJob::where('tenant_id', $tenantId)
            ->where('job_no', 'like', self::PREFIX . '%')
            ->orderByRaw('LENGTH(job_no) DESC, job_no DESC')
            ->first();

        $next = 1;
        if ($last && preg_match('/^' . preg_quote(self::PREFIX, '/') . '(\d+)$/', $last->job_no, $m)) {
            $next = (int) $m[1] + 1;
        }

        return self::PREFIX . str_pad((string) $next, self::PAD_LENGTH, '0', STR_PAD_LEFT);
    }
}

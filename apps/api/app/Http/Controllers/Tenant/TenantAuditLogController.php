<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\IdentityAuditLog;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantAuditLogController extends Controller
{
    /**
     * List identity audit logs for the current tenant only.
     * GET /api/tenant/audit-logs
     * Query: action, from, to, q, per_page, page
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $query = IdentityAuditLog::query()
            ->where('tenant_id', $tenantId)
            ->with(['actor:id,name,email'])
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to') . ' 23:59:59');
        }
        if ($request->filled('q')) {
            $q = '%' . $request->input('q') . '%';
            $query->whereRaw('metadata::text ILIKE ?', [$q]);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection()->map(fn (IdentityAuditLog $log) => [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'actor' => $log->actor ? [
                'id' => $log->actor->id,
                'email' => $log->actor->email,
                'name' => $log->actor->name,
            ] : null,
            'action' => $log->action,
            'metadata' => $log->metadata,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}

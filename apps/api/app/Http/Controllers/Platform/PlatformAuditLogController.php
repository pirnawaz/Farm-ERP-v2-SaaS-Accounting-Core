<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\IdentityAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAuditLogController extends Controller
{
    /**
     * List identity audit logs (platform_admin only). Cross-tenant with optional filters.
     * GET /api/platform/audit-logs
     * Query: tenant_id, action, from, to, q (search metadata), per_page, page
     */
    public function index(Request $request): JsonResponse
    {
        $query = IdentityAuditLog::query()
            ->with(['tenant:id,name', 'actor:id,name,email'])
            ->orderByDesc('created_at');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }
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
            $query->where(function ($qry) use ($q) {
                $qry->whereRaw("metadata::text ILIKE ?", [$q]);
            });
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
            'tenant_id' => $log->tenant_id,
            'tenant_name' => $log->tenant?->name,
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

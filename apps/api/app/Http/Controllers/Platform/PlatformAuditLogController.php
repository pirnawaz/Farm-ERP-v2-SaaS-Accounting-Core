<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAuditLogController extends Controller
{
    /**
     * List audit logs (platform_admin only). Cross-tenant with optional filters.
     * GET /api/platform/audit-logs
     * Query: tenant_id, actor_user_id (user_id), action, date_from, date_to, per_page, page
     */
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->with(['tenant:id,name', 'user:id,name,email'])
            ->orderByDesc('created_at');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }
        if ($request->filled('actor_user_id')) {
            $query->where('user_id', $request->input('actor_user_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection()->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'tenant_id' => $log->tenant_id,
            'tenant_name' => $log->tenant?->name,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'action' => $log->action,
            'user_id' => $log->user_id,
            'user_email' => $log->user_email,
            'actor_name' => $log->user?->name,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at?->toIso8601String(),
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

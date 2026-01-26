<?php

namespace App\Http\Controllers;

use App\Policies\PostingPolicy;
use App\Policies\ReversalPolicy;
use App\Services\AuditService;
use App\Services\TenantContext;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Authorize posting action.
     */
    protected function authorizePosting(Request $request): void
    {
        $userRole = $request->attributes->get('user_role') ?? $request->header('X-User-Role');
        
        if (!$userRole) {
            abort(401, 'Authentication required');
        }

        $policy = new PostingPolicy();
        $user = (object) ['role' => $userRole];
        
        if (!$policy->post($user)) {
            abort(403, 'Insufficient permissions to post documents');
        }
    }

    /**
     * Authorize reversal action.
     */
    protected function authorizeReversal(Request $request): void
    {
        $userRole = $request->attributes->get('user_role') ?? $request->header('X-User-Role');
        
        if (!$userRole) {
            abort(401, 'Authentication required');
        }

        $policy = new ReversalPolicy();
        $user = (object) ['role' => $userRole];
        
        if (!$policy->reverse($user)) {
            abort(403, 'Insufficient permissions to reverse documents');
        }
    }

    /**
     * Log an audit event.
     */
    protected function logAudit(
        Request $request,
        string $entityType,
        string $entityId,
        string $action,
        ?array $metadata = null
    ): void {
        $tenantId = TenantContext::getTenantId($request);
        $userId = $request->attributes->get('user_id') ?? $request->header('X-User-Id');
        
        if (!$tenantId || !$userId) {
            return; // Skip logging if tenant or user not available
        }

        $auditService = new AuditService();
        $auditService->log($tenantId, $entityType, $entityId, $action, $userId, $metadata);
    }
}

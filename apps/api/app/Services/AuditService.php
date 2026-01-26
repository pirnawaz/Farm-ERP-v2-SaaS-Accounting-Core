<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AuditService
{
    /**
     * Log an audit event.
     *
     * @param string $tenantId
     * @param string $entityType e.g., 'Sale', 'Payment', 'PostingGroup'
     * @param string $entityId
     * @param string $action 'POST', 'REVERSE', 'CREATE', 'UPDATE'
     * @param string $userId
     * @param array|null $metadata Optional metadata (reason, posting_date, etc.)
     * @return AuditLog
     */
    public function log(
        string $tenantId,
        string $entityType,
        string $entityId,
        string $action,
        string $userId,
        ?array $metadata = null
    ): AuditLog {
        // Get user email for denormalization
        $user = User::find($userId);
        $userEmail = $user ? $user->email : null;

        return AuditLog::create([
            'tenant_id' => $tenantId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'user_id' => $userId,
            'user_email' => $userEmail,
            'metadata' => $metadata,
        ]);
    }
}

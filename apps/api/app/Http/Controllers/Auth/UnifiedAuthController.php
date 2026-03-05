<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Security\Identity\UnifiedLoginService;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unified auth: single login (email + password) and select-tenant.
 * When X-Tenant-Id or X-Tenant-Slug is present, delegates to legacy tenant login (deprecated).
 */
class UnifiedAuthController extends Controller
{
    public function __construct(
        protected UnifiedLoginService $unifiedLogin,
        protected AuthController $legacyAuth
    ) {}

    /**
     * POST /api/auth/login
     * Body: { email, password }
     * - No tenant header: unified login (Identity + memberships). Returns mode: platform | tenant | select_tenant.
     * - With X-Tenant-Id or X-Tenant-Slug: legacy tenant login (deprecated).
     */
    public function login(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        if ($tenantId !== null) {
            // @deprecated Legacy tenant login (requires X-Tenant-Id or X-Tenant-Slug)
            return $this->legacyAuth->login($request);
        }
        return $this->unifiedLogin->login($request);
    }

    /**
     * POST /api/auth/select-tenant
     * Body: { tenant_id }
     * Requires authenticated identity session (from unified login). Sets active_tenant_id and returns tenant context.
     */
    public function selectTenant(Request $request): JsonResponse
    {
        return $this->unifiedLogin->selectTenant($request);
    }
}

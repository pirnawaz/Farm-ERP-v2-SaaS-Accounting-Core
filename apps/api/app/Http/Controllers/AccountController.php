<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * GET /api/accounts — list tenant accounts (for pickers, e.g. journal lines).
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $accounts = Account::where('tenant_id', $tenantId)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);
        return response()->json($accounts);
    }
}

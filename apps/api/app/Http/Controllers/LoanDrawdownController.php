<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Loans\LoanDrawdownPostingService;
use App\Http\Requests\PostLoanDrawdownRequest;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class LoanDrawdownController extends Controller
{
    public function __construct(
        private LoanDrawdownPostingService $loanDrawdownPostingService
    ) {}

    public function post(PostLoanDrawdownRequest $request, string $id): JsonResponse
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $userRole = $request->attributes->get('user_role');

        $postingGroup = $this->loanDrawdownPostingService->postDrawdown(
            $id,
            $tenantId,
            $request->posting_date,
            $request->funding_account,
            $request->idempotency_key,
            $userRole
        );

        return response()->json($postingGroup, 201);
    }
}

<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Loans\LoanRepaymentPostingService;
use App\Http\Requests\PostLoanRepaymentRequest;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class LoanRepaymentController extends Controller
{
    public function __construct(
        private LoanRepaymentPostingService $loanRepaymentPostingService
    ) {}

    public function post(PostLoanRepaymentRequest $request, string $id): JsonResponse
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $userRole = $request->attributes->get('user_role');

        $postingGroup = $this->loanRepaymentPostingService->postRepayment(
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

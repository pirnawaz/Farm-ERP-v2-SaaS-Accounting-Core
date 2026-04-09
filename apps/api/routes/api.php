<?php

use App\Domains\Operations\LandLease\LandLeaseController;
use App\Http\Controllers\AcceptInviteController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountingPeriodController;
use App\Http\Controllers\ActivityTypeController;
use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\Auth\UnifiedAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\CropActivityController;
use App\Http\Controllers\CropCycleController;
use App\Http\Controllers\CropItemController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FixedAssetController;
use App\Http\Controllers\FixedAssetDepreciationRunController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\FxRevaluationRunController;
use App\Http\Controllers\FixedAssetDisposalController;
use App\Http\Controllers\Dev\DevE2ESeedController;
use App\Http\Controllers\Dev\DevTenantController;
use App\Http\Controllers\HarvestController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Internal\FarmIntegrityController;
use App\Http\Controllers\InvAdjustmentController;
use App\Http\Controllers\InvGrnController;
use App\Http\Controllers\InvIssueController;
use App\Http\Controllers\InvItemCategoryController;
use App\Http\Controllers\InvItemController;
use App\Http\Controllers\InvStockController;
use App\Http\Controllers\InvStoreController;
use App\Http\Controllers\InvTransferController;
use App\Http\Controllers\InvUomController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\LabourReportController;
use App\Http\Controllers\LabWorkerController;
use App\Http\Controllers\LabWorkLogController;
use App\Http\Controllers\LandAllocationController;
use App\Http\Controllers\LandLeaseAccrualController;
use App\Http\Controllers\LandParcelController;
use App\Http\Controllers\LivestockEventController;
use App\Http\Controllers\LoanAgreementController;
use App\Http\Controllers\LoanDrawdownController;
use App\Http\Controllers\LoanRepaymentController;
use App\Http\Controllers\Machinery\MachineController;
use App\Http\Controllers\Machinery\MachineMaintenanceJobController;
use App\Http\Controllers\Machinery\MachineMaintenanceTypeController;
use App\Http\Controllers\Machinery\MachineRateCardController;
use App\Http\Controllers\Machinery\MachineryChargeController;
use App\Http\Controllers\Machinery\MachineryReportsController;
use App\Http\Controllers\Machinery\MachineryServiceController;
use App\Http\Controllers\Machinery\MachineWorkLogController;
use App\Http\Controllers\OperationalTransactionController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Platform\ImpersonationController;
use App\Http\Controllers\Platform\PlatformAuditLogController;
use App\Http\Controllers\Platform\PlatformAuthController;
use App\Http\Controllers\Platform\PlatformConfigHealthController;
use App\Http\Controllers\Platform\PlatformTenantInvitationController;
use App\Http\Controllers\Platform\PlatformTenantLifecycleController;
use App\Http\Controllers\Platform\PlatformTenantModulesController;
use App\Http\Controllers\Platform\PlatformTenantUserController;
use App\Http\Controllers\PlatformTenantController;
use App\Http\Controllers\PostingGroupController;
use App\Http\Controllers\ProductionUnitController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectRuleController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\SupplierInvoiceController;
use App\Http\Controllers\SettlementPackController;
use App\Http\Controllers\ShareRuleController;
use App\Http\Controllers\Tenant\TenantAddonModulesController;
use App\Http\Controllers\Tenant\TenantAuditLogController;
use App\Http\Controllers\TenantFarmProfileController;
use App\Http\Controllers\TenantInvitationController;
use App\Http\Controllers\TenantModuleController;
use App\Http\Controllers\TenantOnboardingController;
use App\Http\Controllers\TenantUserAdminController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

// Internal: farm integrity + daily admin review (read-only, tenant-scoped, tenant_admin only)
Route::prefix('internal')->middleware(['role:tenant_admin'])->group(function () {
    Route::get('farm-integrity', [FarmIntegrityController::class, 'index']);
    Route::get('daily-admin-review', [FarmIntegrityController::class, 'dailyAdminReview']);
});

// Dashboard, tenant modules list (read-only), and tenant addon modules (read-only; all tenant roles need module state for sidebar)
Route::middleware(['role:tenant_admin,accountant,operator'])->group(function () {
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('tenant/modules', [TenantModuleController::class, 'index']);
    Route::get('tenant/addon-modules', [TenantAddonModulesController::class, 'index']);
});

Route::post('/auth/login', [UnifiedAuthController::class, 'login'])->middleware('throttle:auth.tenant.login');
Route::post('/auth/select-tenant', [UnifiedAuthController::class, 'selectTenant']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
Route::get('/auth/me', [AuthController::class, 'me']);
Route::get('/auth/whoami', [AuthController::class, 'whoami']);
Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
Route::post('/auth/complete-first-login-password', [AuthController::class, 'completeFirstLoginPassword']);
Route::post('/auth/set-password-with-token', [AuthController::class, 'setPasswordWithToken']);
Route::post('/auth/accept-invite', AcceptInviteController::class)->middleware('throttle:auth.accept-invite');

// Platform auth: login does not require tenant or role; logout/me require platform_admin (cookie or headers)
Route::prefix('platform')->group(function () {
    Route::post('auth/login', [PlatformAuthController::class, 'login'])->middleware('throttle:auth.platform.login');
    // Impersonation status: callable by platform_admin OR when impersonation cookie is set (so tenant app can show banner)
    Route::get('impersonation/status', [ImpersonationController::class, 'statusForUi'])->middleware('platform_admin_or_impersonation');
    // Force-stop: clear impersonation cookies; callable by platform_admin OR when impersonation cookie present (so tenant app can force stop)
    Route::post('impersonation/force-stop', [ImpersonationController::class, 'forceStop'])->middleware('platform_admin_or_impersonation');
    Route::middleware(['role:platform_admin'])->group(function () {
        Route::post('auth/logout', [PlatformAuthController::class, 'logout']);
        Route::post('auth/logout-all', [PlatformAuthController::class, 'logoutAll']);
        Route::get('auth/me', [PlatformAuthController::class, 'me']);
        Route::post('auth/change-password', [PlatformAuthController::class, 'changePassword']);
        Route::get('tenants', [PlatformTenantController::class, 'index']);
        Route::post('tenants', [PlatformTenantController::class, 'store']);
        Route::get('tenants/{tenant}/users', [PlatformTenantController::class, 'users']);
        Route::post('tenants/{tenant}/invitations', [PlatformTenantInvitationController::class, 'store'])->middleware('throttle:auth.platform.invitations');
        Route::post('tenants/{tenant}/users', [PlatformTenantUserController::class, 'store'])->middleware('throttle:auth.manual_user_create');
        Route::patch('tenants/{tenant}/users/{user}', [PlatformTenantUserController::class, 'update'])->middleware('throttle:auth.platform_user_update');
        Route::get('tenants/{id}', [PlatformTenantController::class, 'show']);
        Route::put('tenants/{id}', [PlatformTenantController::class, 'update']);
        Route::post('tenants/{id}/reset-admin-password', [PlatformTenantLifecycleController::class, 'resetAdminPassword']);
        Route::post('tenants/{id}/archive', [PlatformTenantLifecycleController::class, 'archive']);
        Route::post('tenants/{id}/unarchive', [PlatformTenantLifecycleController::class, 'unarchive']);
        Route::get('tenants/{tenantId}/modules', [PlatformTenantModulesController::class, 'index']);
        Route::put('tenants/{tenantId}/modules', [PlatformTenantModulesController::class, 'update']);
        Route::get('impersonation', [ImpersonationController::class, 'status']);
        Route::post('impersonation/start', [ImpersonationController::class, 'start']);
        Route::post('impersonation/stop', [ImpersonationController::class, 'stop']);
        Route::post('tenants/{tenant}/impersonate', [ImpersonationController::class, 'impersonate']);
        Route::get('audit-logs', [PlatformAuditLogController::class, 'index']);
        Route::get('config-health', [PlatformConfigHealthController::class, '__invoke']);
    });
});

// Dev-only routes (disabled in production)
Route::prefix('dev')->middleware('dev')->group(function () {
    Route::get('tenants', [DevTenantController::class, 'index']);
    Route::post('tenants', [DevTenantController::class, 'store']);
    Route::post('tenants/{id}/activate', [DevTenantController::class, 'activate']);
    Route::post('tenants/{id}/bootstrap-accounts', [DevTenantController::class, 'bootstrapAccounts']);
    Route::delete('tenants/{id}', [DevTenantController::class, 'destroy']);
    // E2E deterministic seed (idempotent)
    Route::post('e2e/seed', [DevE2ESeedController::class, 'seed']);
    Route::get('e2e/seed-state', [DevE2ESeedController::class, 'seedState']);
    Route::post('e2e/auth-cookie', [DevE2ESeedController::class, 'authCookie']);
    Route::get('e2e/accounting-artifacts', [DevE2ESeedController::class, 'accountingArtifacts']);
});

// Users (tenant_admin only)
Route::middleware(['role:tenant_admin'])->group(function () {
    Route::apiResource('users', UserController::class);
});

// Parties (tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::apiResource('parties', PartyController::class);
    Route::get('parties/{id}/balances', [PartyController::class, 'balances']);
    Route::get('parties/{id}/statement', [PartyController::class, 'statement']);
    Route::get('parties/{id}/ar-statement', [PartyController::class, 'arStatement']);
    Route::get('parties/{id}/supplier-statement', [PartyController::class, 'supplierStatement']);
    Route::get('parties/{id}/receivables/open-sales', [PartyController::class, 'openSales']);
});

// Land Parcels — VIEW: tenant_admin, accountant, manager(operator); MUTATE: tenant_admin, accountant only
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:land'])->group(function () {
    Route::get('land-parcels', [LandParcelController::class, 'index']);
    Route::get('land-parcels/{id}', [LandParcelController::class, 'show']);
    Route::get('land-parcels/{id}/rotation-warnings', [LandParcelController::class, 'rotationWarnings']);
});
Route::middleware(['role:tenant_admin,accountant', 'require_module:land'])->group(function () {
    Route::post('land-parcels', [LandParcelController::class, 'store']);
    Route::patch('land-parcels/{id}', [LandParcelController::class, 'update']);
    Route::delete('land-parcels/{id}', [LandParcelController::class, 'destroy']);
    Route::get('land-parcels/{id}/audit', [LandParcelController::class, 'audit']);
});
if (config('features.land_documents_enabled')) {
    Route::middleware(['role:tenant_admin,accountant', 'require_module:land'])->group(function () {
        Route::get('land-parcels/{id}/documents', [LandParcelController::class, 'listDocuments']);
        Route::post('land-parcels/{id}/documents', [LandParcelController::class, 'storeDocument']);
    });
}

// Crop Items (tenant-facing crop list for crop cycles) — GET: operator+; POST/PATCH: tenant_admin, accountant
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:projects_crop_cycles'])->group(function () {
    Route::get('crop-items', [CropItemController::class, 'index']);
});
Route::middleware(['role:tenant_admin,accountant', 'require_module:projects_crop_cycles'])->group(function () {
    Route::post('crop-items', [CropItemController::class, 'store']);
    Route::patch('crop-items/{id}', [CropItemController::class, 'update']);
});

// Production Units (tenant_admin, accountant) — additive for long-duration crops
Route::middleware(['role:tenant_admin,accountant', 'require_module:projects_crop_cycles'])->group(function () {
    Route::get('production-units', [ProductionUnitController::class, 'index']);
    Route::post('production-units', [ProductionUnitController::class, 'store']);
    Route::get('production-units/{id}', [ProductionUnitController::class, 'show']);
    Route::patch('production-units/{id}', [ProductionUnitController::class, 'update']);
    Route::get('livestock-events', [LivestockEventController::class, 'index']);
    Route::post('livestock-events', [LivestockEventController::class, 'store']);
    Route::get('livestock-events/{id}', [LivestockEventController::class, 'show']);
    Route::patch('livestock-events/{id}', [LivestockEventController::class, 'update']);
    Route::delete('livestock-events/{id}', [LivestockEventController::class, 'destroy']);
});

// Crop Cycles (tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::apiResource('crop-cycles', CropCycleController::class);
    Route::get('crop-cycles/{id}/close-preview', [CropCycleController::class, 'closePreview']);
    Route::get('crop-cycles/{id}/close-run', [CropCycleController::class, 'closeRun']);
    Route::post('crop-cycles/{id}/close', [CropCycleController::class, 'close'])->middleware('role:tenant_admin');
    Route::post('crop-cycles/{id}/reopen', [CropCycleController::class, 'reopen'])->middleware('role:tenant_admin');
    Route::post('crop-cycles/{id}/open', [CropCycleController::class, 'open'])->middleware('role:tenant_admin');
    Route::post('crop-cycles/{id}/season-setup', [CropCycleController::class, 'seasonSetup']);
});

// Land Allocations (tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::apiResource('land-allocations', LandAllocationController::class);
});

// Land Leases / Maqada (tenant_admin only) — requires land_leases module
Route::middleware(['role:tenant_admin', 'require_module:land_leases'])->group(function () {
    Route::apiResource('land-leases', LandLeaseController::class);
    Route::get('land-lease-accruals', [LandLeaseAccrualController::class, 'index']);
    Route::post('land-lease-accruals', [LandLeaseAccrualController::class, 'store']);
    Route::get('land-lease-accruals/{id}', [LandLeaseAccrualController::class, 'show']);
    Route::put('land-lease-accruals/{id}', [LandLeaseAccrualController::class, 'update']);
    Route::delete('land-lease-accruals/{id}', [LandLeaseAccrualController::class, 'destroy']);
    Route::post('land-lease-accruals/{id}/post', [LandLeaseAccrualController::class, 'post']);
    Route::post('land-lease-accruals/{id}/reverse', [LandLeaseAccrualController::class, 'reverse']);
});

// Projects (tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::apiResource('projects', ProjectController::class);
    Route::post('projects/from-allocation', [ProjectController::class, 'fromAllocation']);
    Route::post('projects/{id}/close', [ProjectController::class, 'close']);
    Route::post('projects/{id}/reopen', [ProjectController::class, 'reopen']);
});

// Project Rules (tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::get('projects/{id}/rules', [ProjectRuleController::class, 'show']);
    Route::put('projects/{id}/rules', [ProjectRuleController::class, 'update']);
});

// Share Rules (tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::apiResource('share-rules', ShareRuleController::class);
});

// Operational Transactions (operator can create/edit own, accountant can do all)
// Note: Authorization for operator vs accountant should be handled in controller/policy
Route::middleware(['role:tenant_admin,accountant,operator'])->group(function () {
    Route::apiResource('operational-transactions', OperationalTransactionController::class);
    Route::post('operational-transactions/{id}/post', [OperationalTransactionController::class, 'post'])
        ->middleware('role:tenant_admin,accountant');
});

// Settlement (accountant, tenant_admin) — requires settlements module
Route::middleware(['role:tenant_admin,accountant', 'require_module:settlements'])->group(function () {
    // Settlement Pack (Governance Phase 1 + v3 PDF export + v4 approval workflow)
    Route::get('settlement-packs', [SettlementPackController::class, 'index']);
    Route::post('settlement-packs', [SettlementPackController::class, 'store']);
    Route::post('projects/{projectId}/settlement-pack', [SettlementPackController::class, 'generate']);
    Route::get('settlement-packs/{id}/register', [SettlementPackController::class, 'register']);
    Route::post('settlement-packs/{id}/generate-version', [SettlementPackController::class, 'generateVersion']);
    Route::get('settlement-packs/{id}/pdf', [SettlementPackController::class, 'downloadPdf']);
    Route::get('settlement-packs/{id}', [SettlementPackController::class, 'show']);
    Route::post('settlement-packs/{id}/finalize', [SettlementPackController::class, 'finalize']);
    Route::post('settlement-packs/{id}/submit-for-approval', [SettlementPackController::class, 'submitForApproval']);
    Route::post('settlement-packs/{id}/approve', [SettlementPackController::class, 'approve']);
    Route::post('settlement-packs/{id}/reject', [SettlementPackController::class, 'reject']);
    Route::post('settlement-packs/{id}/export/pdf', [SettlementPackController::class, 'exportPdf']);
    Route::get('settlement-packs/{id}/documents', [SettlementPackController::class, 'listDocuments']);
    Route::get('settlement-packs/{id}/documents/{version}', [SettlementPackController::class, 'getDocument']);
    // Project-based settlements (existing, for backward compatibility)
    Route::post('projects/{id}/settlement/preview', [SettlementController::class, 'preview']);
    Route::get('projects/{id}/settlement/offset-preview', [SettlementController::class, 'offsetPreview']);
    Route::post('projects/{id}/settlement/post', [SettlementController::class, 'post']);

    // Sales-based settlements (Phase 11)
    Route::get('settlements/preview', [SettlementController::class, 'previewSettlement']);
    Route::apiResource('settlements', SettlementController::class)->except(['update', 'destroy']);
    Route::post('settlements/{id}/post', [SettlementController::class, 'postSettlement']);
    Route::post('settlements/{id}/reverse', [SettlementController::class, 'reverse']);
    // Crop cycle settlements (ledger-based, one PostingGroup per cycle)
    Route::post('settlements/crop-cycles/{id}/preview', [SettlementController::class, 'cropCyclePreview']);
    Route::post('settlements/crop-cycles/{id}/post', [SettlementController::class, 'cropCyclePost']);
});

// Payments (tenant_admin, accountant, operator) — requires treasury_payments module
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:treasury_payments'])->group(function () {
    Route::apiResource('payments', PaymentController::class);
    Route::get('payments/allocation-preview', [PaymentController::class, 'allocationPreview']);
    Route::get('payments/{id}/apply-sales/preview', [PaymentController::class, 'applySalesPreview']);
    Route::post('payments/{id}/apply-sales', [PaymentController::class, 'applySales']);
    Route::post('payments/{id}/unapply-sales', [PaymentController::class, 'unapplySales']);
    Route::get('payments/{id}/apply-bills/preview', [PaymentController::class, 'applyBillsPreview']);
    Route::post('payments/{id}/apply-bills', [PaymentController::class, 'applyBills']);
    Route::post('payments/{id}/unapply-bills', [PaymentController::class, 'unapplyBills']);
    Route::get('payments/{id}/apply-supplier-invoices/preview', [PaymentController::class, 'applySupplierInvoicesPreview']);
    Route::post('payments/{id}/apply-supplier-invoices', [PaymentController::class, 'applySupplierInvoices']);
    Route::post('payments/{id}/unapply-supplier-invoices', [PaymentController::class, 'unapplySupplierInvoices']);
    Route::post('payments/{id}/post', [PaymentController::class, 'post'])
        ->middleware('role:tenant_admin,accountant');
    Route::post('payments/{id}/reverse', [PaymentController::class, 'reverse'])
        ->middleware('role:tenant_admin,accountant');
});

// Advances (tenant_admin, accountant, operator) — requires treasury_advances module
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:treasury_advances'])->group(function () {
    Route::apiResource('advances', AdvanceController::class);
    Route::post('advances/{id}/post', [AdvanceController::class, 'post'])
        ->middleware('role:tenant_admin,accountant');
});

// Loans — requires loans module (read: operator+; post: tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:loans'])->group(function () {
    Route::get('loan-agreements', [LoanAgreementController::class, 'index']);
    Route::get('loan-agreements/{id}', [LoanAgreementController::class, 'show']);
    Route::get('loan-agreements/{id}/statement', [LoanAgreementController::class, 'statement']);
});
Route::middleware(['role:tenant_admin,accountant', 'require_module:loans'])->group(function () {
    Route::post('loan-drawdowns/{id}/post', [LoanDrawdownController::class, 'post']);
    Route::post('loan-repayments/{id}/post', [LoanRepaymentController::class, 'post']);
});

// Sales (tenant_admin, accountant, operator) — requires ar_sales module
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:ar_sales'])->group(function () {
    Route::apiResource('sales', SaleController::class);
    Route::post('sales/{id}/post', [SaleController::class, 'post'])
        ->middleware('role:tenant_admin,accountant');
    Route::post('sales/{id}/reverse', [SaleController::class, 'reverse'])
        ->middleware('role:tenant_admin,accountant');
    Route::post('sales/{id}/apply-to-invoices', [SaleController::class, 'applyToInvoices'])
        ->middleware('role:tenant_admin,accountant');
});

// Inventory (tenant_admin, accountant, operator) — requires inventory module
// Routes under /api/v1/inventory
Route::prefix('v1')->middleware(['role:tenant_admin,accountant,operator', 'require_module:inventory'])->group(function () {
    Route::prefix('inventory')->group(function () {
        // Master
        Route::get('items', [InvItemController::class, 'index']);
        Route::post('items', [InvItemController::class, 'store']);
        Route::get('items/{id}', [InvItemController::class, 'show']);
        Route::patch('items/{id}', [InvItemController::class, 'update']);
        Route::post('items/{id}/deactivate', [InvItemController::class, 'deactivate']);
        Route::post('items/{id}/activate', [InvItemController::class, 'activate']);
        Route::delete('items/{id}', [InvItemController::class, 'destroy']);
        Route::get('stores', [InvStoreController::class, 'index']);
        Route::post('stores', [InvStoreController::class, 'store']);
        Route::get('stores/{id}', [InvStoreController::class, 'show']);
        Route::patch('stores/{id}', [InvStoreController::class, 'update']);
        Route::get('uoms', [InvUomController::class, 'index']);
        Route::post('uoms', [InvUomController::class, 'store']);
        Route::get('uoms/{id}', [InvUomController::class, 'show']);
        Route::patch('uoms/{id}', [InvUomController::class, 'update']);
        Route::get('categories', [InvItemCategoryController::class, 'index']);
        Route::post('categories', [InvItemCategoryController::class, 'store']);
        Route::get('categories/{id}', [InvItemCategoryController::class, 'show']);
        Route::patch('categories/{id}', [InvItemCategoryController::class, 'update']);
        // GRNs
        Route::get('grns', [InvGrnController::class, 'index']);
        Route::post('grns', [InvGrnController::class, 'store']);
        Route::get('grns/{id}', [InvGrnController::class, 'show']);
        Route::patch('grns/{id}', [InvGrnController::class, 'update']);
        Route::post('grns/{id}/post', [InvGrnController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('grns/{id}/reverse', [InvGrnController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
        // Issues
        Route::get('issues', [InvIssueController::class, 'index']);
        Route::post('issues', [InvIssueController::class, 'store']);
        Route::get('issues/{id}', [InvIssueController::class, 'show']);
        Route::patch('issues/{id}', [InvIssueController::class, 'update']);
        Route::post('issues/{id}/post', [InvIssueController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('issues/{id}/reverse', [InvIssueController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
        // Transfers
        Route::get('transfers', [InvTransferController::class, 'index']);
        Route::post('transfers', [InvTransferController::class, 'store']);
        Route::get('transfers/{id}', [InvTransferController::class, 'show']);
        Route::patch('transfers/{id}', [InvTransferController::class, 'update']);
        Route::post('transfers/{id}/post', [InvTransferController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('transfers/{id}/reverse', [InvTransferController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
        // Adjustments
        Route::get('adjustments', [InvAdjustmentController::class, 'index']);
        Route::post('adjustments', [InvAdjustmentController::class, 'store']);
        Route::get('adjustments/{id}', [InvAdjustmentController::class, 'show']);
        Route::patch('adjustments/{id}', [InvAdjustmentController::class, 'update']);
        Route::post('adjustments/{id}/post', [InvAdjustmentController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('adjustments/{id}/reverse', [InvAdjustmentController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
        // Stock reporting
        Route::get('stock/on-hand', [InvStockController::class, 'onHand']);
        Route::get('stock/movements', [InvStockController::class, 'movements']);
    });
});

// Labour (tenant_admin, accountant, operator) — requires labour module
// Routes under /api/v1/labour
Route::prefix('v1')->middleware(['role:tenant_admin,accountant,operator', 'require_module:labour'])->group(function () {
    Route::prefix('labour')->group(function () {
        Route::get('workers', [LabWorkerController::class, 'index']);
        Route::post('workers', [LabWorkerController::class, 'store']);
        Route::get('workers/{id}', [LabWorkerController::class, 'show']);
        Route::patch('workers/{id}', [LabWorkerController::class, 'update']);
        Route::get('work-logs', [LabWorkLogController::class, 'index']);
        Route::post('work-logs', [LabWorkLogController::class, 'store']);
        Route::get('work-logs/{id}', [LabWorkLogController::class, 'show']);
        Route::patch('work-logs/{id}', [LabWorkLogController::class, 'update']);
        Route::post('work-logs/{id}/post', [LabWorkLogController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('work-logs/{id}/reverse', [LabWorkLogController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
        Route::get('payables/outstanding', [LabourReportController::class, 'outstanding']);
    });
});

// Crop Ops (tenant_admin, accountant, operator) — requires crop_ops module
// Routes under /api/v1/crop-ops
Route::prefix('v1')->middleware(['role:tenant_admin,accountant,operator', 'require_module:crop_ops'])->group(function () {
    Route::prefix('crop-ops')->group(function () {
        Route::get('activity-types', [ActivityTypeController::class, 'index']);
        Route::post('activity-types', [ActivityTypeController::class, 'store']);
        Route::patch('activity-types/{id}', [ActivityTypeController::class, 'update']);
        Route::get('activities/timeline', [CropActivityController::class, 'timeline']);
        Route::get('activities', [CropActivityController::class, 'index']);
        Route::post('activities', [CropActivityController::class, 'store']);
        Route::get('activities/{id}', [CropActivityController::class, 'show']);
        Route::patch('activities/{id}', [CropActivityController::class, 'update']);
        Route::post('activities/{id}/post', [CropActivityController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('activities/{id}/reverse', [CropActivityController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
        // Harvests
        Route::get('harvests', [HarvestController::class, 'index']);
        Route::post('harvests', [HarvestController::class, 'store']);
        Route::get('harvests/{id}', [HarvestController::class, 'show']);
        Route::put('harvests/{id}', [HarvestController::class, 'update']);
        Route::post('harvests/{id}/lines', [HarvestController::class, 'addLine']);
        Route::put('harvests/{id}/lines/{lineId}', [HarvestController::class, 'updateLine']);
        Route::delete('harvests/{id}/lines/{lineId}', [HarvestController::class, 'deleteLine']);
        Route::post('harvests/{id}/post', [HarvestController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('harvests/{id}/reverse', [HarvestController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
    });
});

// Machinery (tenant_admin, accountant, operator) — requires machinery module
// Routes under /api/v1/machinery
Route::prefix('v1')->middleware(['role:tenant_admin,accountant,operator', 'require_module:machinery'])->group(function () {
    Route::prefix('machinery')->group(function () {
        // Machines
        Route::get('machines', [MachineController::class, 'index']);
        Route::post('machines', [MachineController::class, 'store']);
        Route::get('machines/{id}', [MachineController::class, 'show']);
        Route::put('machines/{id}', [MachineController::class, 'update']);
        // Maintenance Types
        Route::get('maintenance-types', [MachineMaintenanceTypeController::class, 'index']);
        Route::post('maintenance-types', [MachineMaintenanceTypeController::class, 'store']);
        Route::put('maintenance-types/{id}', [MachineMaintenanceTypeController::class, 'update']);
        // Work Logs
        Route::get('work-logs', [MachineWorkLogController::class, 'index']);
        Route::post('work-logs', [MachineWorkLogController::class, 'store']);
        Route::get('work-logs/{id}', [MachineWorkLogController::class, 'show']);
        Route::put('work-logs/{id}', [MachineWorkLogController::class, 'update']);
        Route::delete('work-logs/{id}', [MachineWorkLogController::class, 'destroy']);
        Route::post('work-logs/{id}/post', [MachineWorkLogController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('work-logs/{id}/reverse', [MachineWorkLogController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
        // Rate Cards
        Route::get('rate-cards', [MachineRateCardController::class, 'index']);
        Route::post('rate-cards', [MachineRateCardController::class, 'store']);
        Route::get('rate-cards/{id}', [MachineRateCardController::class, 'show']);
        Route::put('rate-cards/{id}', [MachineRateCardController::class, 'update']);
        // Charges
        Route::get('charges', [MachineryChargeController::class, 'index']);
        Route::get('charges/{id}', [MachineryChargeController::class, 'show']);
        Route::post('charges/generate', [MachineryChargeController::class, 'generate']);
        Route::put('charges/{id}', [MachineryChargeController::class, 'update']);
        Route::post('charges/{id}/post', [MachineryChargeController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('charges/{id}/reverse', [MachineryChargeController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
        // Internal machinery services (project settlement valuation, no AR/Sales)
        Route::get('machinery-services', [MachineryServiceController::class, 'index']);
        Route::post('machinery-services', [MachineryServiceController::class, 'store']);
        Route::get('machinery-services/{id}', [MachineryServiceController::class, 'show']);
        Route::put('machinery-services/{id}', [MachineryServiceController::class, 'update']);
        Route::post('machinery-services/{id}/post', [MachineryServiceController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('machinery-services/{id}/reverse', [MachineryServiceController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
        // Maintenance Jobs
        Route::get('maintenance-jobs', [MachineMaintenanceJobController::class, 'index']);
        Route::post('maintenance-jobs', [MachineMaintenanceJobController::class, 'store']);
        Route::get('maintenance-jobs/{id}', [MachineMaintenanceJobController::class, 'show']);
        Route::put('maintenance-jobs/{id}', [MachineMaintenanceJobController::class, 'update']);
        Route::delete('maintenance-jobs/{id}', [MachineMaintenanceJobController::class, 'destroy']);
        Route::post('maintenance-jobs/{id}/post', [MachineMaintenanceJobController::class, 'post'])->middleware('role:tenant_admin,accountant');
        Route::post('maintenance-jobs/{id}/reverse', [MachineMaintenanceJobController::class, 'reverse'])->middleware('role:tenant_admin,accountant');
        // Reports
        Route::get('reports/charges-by-machine', [MachineryReportsController::class, 'chargesByMachine']);
        Route::get('reports/costs-by-machine', [MachineryReportsController::class, 'costsByMachine']);
        Route::get('reports/profitability', [MachineryReportsController::class, 'profitability']);
    });
});

// Posting Groups (read-only access for viewing posted transactions)
Route::middleware(['role:tenant_admin,accountant,operator'])->group(function () {
    Route::get('posting-groups/{id}', [PostingGroupController::class, 'show']);
    Route::get('posting-groups/{id}/ledger-entries', [PostingGroupController::class, 'ledgerEntries']);
    Route::get('posting-groups/{id}/allocation-rows', [PostingGroupController::class, 'allocationRows']);
    Route::post('posting-groups/{id}/reverse', [PostingGroupController::class, 'reverse'])
        ->middleware('role:tenant_admin,accountant');
    Route::get('posting-groups/{id}/reversals', [PostingGroupController::class, 'reversals']);
});

// Crop reports (projects_crop_cycles only)
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:projects_crop_cycles'])->group(function () {
    Route::get('reports/crop-category-acres', [ReportController::class, 'cropCategoryAcres']);
    Route::get('reports/crop-costs', [ReportController::class, 'cropCosts']);
    Route::get('reports/crop-profitability', [ReportController::class, 'cropProfitability']);
    Route::get('reports/crop-profitability-trend', [ReportController::class, 'cropProfitabilityTrend']);
    Route::get('reports/production-units-profitability', [ReportController::class, 'productionUnitsProfitability']);
    Route::get('reports/production-unit-summary', [ReportController::class, 'productionUnitSummary']);
    Route::get('reports/livestock-unit-status', [ReportController::class, 'livestockUnitStatus']);
});

// Reports (tenant_admin, accountant, operator) — requires reports module
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:reports'])->group(function () {
    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance']);
    Route::get('reports/profit-loss/project', [ReportController::class, 'profitLossProject']);
    Route::get('reports/profit-loss/crop-cycle', [ReportController::class, 'profitLossCropCycle']);
    Route::get('reports/profit-loss', [ReportController::class, 'profitLoss']);
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet']);
    Route::get('reports/general-ledger', [ReportController::class, 'generalLedger']);
    Route::get('reports/project-statement', [ReportController::class, 'projectStatement']);
    Route::get('reports/project-pl', [ReportController::class, 'projectPL']);
    Route::get('reports/crop-cycle-pl', [ReportController::class, 'cropCyclePL']);
    Route::get('reports/account-balances', [ReportController::class, 'accountBalances']);
    Route::get('reports/cashbook', [ReportController::class, 'cashbook']);
    Route::get('reports/ar-ageing', [ReportController::class, 'arAgeing']);
    Route::get('ar/aging', [ReportController::class, 'arAging']);
    Route::get('reports/yield', [ReportController::class, 'yield']);
    Route::get('reports/cost-per-unit', [ReportController::class, 'costPerUnit']);
    Route::get('reports/sales-margin', [ReportController::class, 'salesMargin']);
    Route::get('reports/settlement-statement', [ReportController::class, 'settlementStatement']);
    Route::get('reports/party-ledger', [ReportController::class, 'partyLedger']);
    Route::get('reports/party-summary', [ReportController::class, 'partySummary']);
    Route::get('reports/landlord-statement', [ReportController::class, 'landlordStatement'])
        ->middleware('require_module:land_leases');
    Route::get('reports/role-ageing', [ReportController::class, 'roleAgeing']);
    Route::get('reports/crop-cycle-distribution', [ReportController::class, 'cropCycleDistribution']);
    Route::get('reports/reconciliation/project', [ReportController::class, 'reconciliationProject']);
    Route::get('reports/reconciliation/crop-cycle', [ReportController::class, 'reconciliationCropCycle']);
    Route::get('reports/reconciliation/supplier-ap', [ReportController::class, 'reconciliationSupplierAp']);
    Route::get('reports/ar-control-reconciliation', [ReportController::class, 'arControlReconciliation']);
    Route::get('reports/customer-balances', [ReportController::class, 'customerBalances']);
    Route::get('reports/customer-balance-detail', [ReportController::class, 'customerBalanceDetail']);
    Route::get('reports/ap-ageing', [ReportController::class, 'apAgeing']);
    Route::get('reports/ap-control-reconciliation', [ReportController::class, 'apControlReconciliation']);
    Route::get('reports/supplier-balances', [ReportController::class, 'supplierBalances']);
    Route::get('reports/supplier-balance-detail', [ReportController::class, 'supplierBalanceDetail']);
});

// Reconciliation (tenant_admin, accountant) — read-only audit/debugging endpoints
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::get('reconciliation/project/{id}', [ReconciliationController::class, 'projectReconciliation']);
    Route::get('reconciliation/supplier/{party_id}', [ReconciliationController::class, 'supplierAPReconciliation']);
});

// Bank reconciliation (tenant_admin, accountant) — metadata only, no ledger mutation
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::post('bank-reconciliations', [BankReconciliationController::class, 'store']);
    Route::get('bank-reconciliations', [BankReconciliationController::class, 'index']);
    Route::get('bank-reconciliations/{id}', [BankReconciliationController::class, 'show']);
    Route::post('bank-reconciliations/{id}/clear', [BankReconciliationController::class, 'clear']);
    Route::post('bank-reconciliations/{id}/unclear', [BankReconciliationController::class, 'unclear']);
    Route::post('bank-reconciliations/{id}/finalize', [BankReconciliationController::class, 'finalize']);
    // Statement lines (manual entry)
    Route::post('bank-reconciliations/{id}/statement-lines', [BankReconciliationController::class, 'addStatementLine']);
    Route::get('bank-reconciliations/{id}/statement-lines', [BankReconciliationController::class, 'listStatementLines']);
    Route::post('bank-reconciliations/{id}/statement-lines/{lineId}/void', [BankReconciliationController::class, 'voidStatementLine']);
    Route::post('bank-reconciliations/{id}/statement-lines/{lineId}/match', [BankReconciliationController::class, 'matchStatementLine']);
    Route::post('bank-reconciliations/{id}/statement-lines/{lineId}/unmatch', [BankReconciliationController::class, 'unmatchStatementLine']);
});

// Accounts list (for pickers; tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::get('accounts', [AccountController::class, 'index']);
});

// Accounting periods (tenant_admin, accountant) — period locking
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::get('accounting-periods', [AccountingPeriodController::class, 'index']);
    Route::post('accounting-periods', [AccountingPeriodController::class, 'store']);
    Route::post('accounting-periods/{id}/close', [AccountingPeriodController::class, 'close']);
    Route::post('accounting-periods/{id}/reopen', [AccountingPeriodController::class, 'reopen']);
    Route::get('accounting-periods/{id}/events', [AccountingPeriodController::class, 'events']);
});

// Fixed assets (list/show: operator+; create/activate: tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant,operator'])->group(function () {
    Route::get('fixed-assets', [FixedAssetController::class, 'index']);
    Route::get('fixed-assets/{id}', [FixedAssetController::class, 'show']);
    Route::get('fixed-asset-depreciation-runs', [FixedAssetDepreciationRunController::class, 'index']);
    Route::get('fixed-asset-depreciation-runs/{id}', [FixedAssetDepreciationRunController::class, 'show']);
    Route::get('fixed-asset-disposals/{id}', [FixedAssetDisposalController::class, 'show']);
});
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::post('fixed-assets', [FixedAssetController::class, 'store']);
    Route::post('fixed-assets/{id}/activate', [FixedAssetController::class, 'activate']);
    Route::post('fixed-assets/{fixed_asset_id}/disposals', [FixedAssetDisposalController::class, 'store']);
    Route::post('fixed-asset-depreciation-runs', [FixedAssetDepreciationRunController::class, 'store']);
    Route::post('fixed-asset-depreciation-runs/{id}/post', [FixedAssetDepreciationRunController::class, 'post']);
    Route::get('exchange-rates', [ExchangeRateController::class, 'index']);
    Route::post('exchange-rates', [ExchangeRateController::class, 'store']);
    Route::get('fx-revaluation-runs', [FxRevaluationRunController::class, 'index']);
    Route::get('fx-revaluation-runs/{id}', [FxRevaluationRunController::class, 'show']);
    Route::post('fx-revaluation-runs', [FxRevaluationRunController::class, 'store']);
    Route::post('fx-revaluation-runs/{id}/refresh', [FxRevaluationRunController::class, 'refresh']);
    Route::post('fx-revaluation-runs/{id}/post', [FxRevaluationRunController::class, 'post']);
    Route::post('fixed-asset-disposals/{id}/post', [FixedAssetDisposalController::class, 'post']);
});

// General Journal (tenant_admin, accountant) — manual GL entries
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::get('supplier-invoices', [SupplierInvoiceController::class, 'index']);
    Route::get('supplier-invoices/{id}', [SupplierInvoiceController::class, 'show']);
    Route::post('supplier-invoices/{id}/post', [SupplierInvoiceController::class, 'post']);
    Route::post('journals', [JournalEntryController::class, 'store']);
    Route::get('journals', [JournalEntryController::class, 'index']);
    Route::get('journals/{id}', [JournalEntryController::class, 'show']);
    Route::put('journals/{id}', [JournalEntryController::class, 'update']);
    Route::post('journals/{id}/post', [JournalEntryController::class, 'post']);
    Route::post('journals/{id}/reverse', [JournalEntryController::class, 'reverse']);
});

// Settings (all authenticated users can view, tenant_admin can update)
Route::middleware(['role:tenant_admin,accountant,operator'])->group(function () {
    Route::get('settings/tenant', [SettingsController::class, 'show']);
});

// tenant_admin only: settings update, tenant modules update, farm profile, users, addon modules (accountant/operator cannot manage users or toggle modules).
Route::middleware(['role:tenant_admin'])->group(function () {
    Route::put('settings/tenant', [SettingsController::class, 'update']);
    Route::put('tenant/modules', [TenantModuleController::class, 'update']);
    Route::patch('tenant/addon-modules/{module_key}', [TenantAddonModulesController::class, 'update']);
    Route::get('tenant/onboarding', [TenantOnboardingController::class, 'show']);
    Route::put('tenant/onboarding', [TenantOnboardingController::class, 'update']);
    Route::get('tenant/farm-profile', [TenantFarmProfileController::class, 'show']);
    Route::post('tenant/farm-profile', [TenantFarmProfileController::class, 'store']);
    Route::put('tenant/farm-profile', [TenantFarmProfileController::class, 'update']);
    Route::get('tenant/users', [TenantUserAdminController::class, 'index']);
    Route::post('tenant/users', [TenantUserAdminController::class, 'store'])->middleware('throttle:auth.manual_user_create');
    Route::get('tenant/audit-logs', [TenantAuditLogController::class, 'index']);
    Route::post('tenant/invitations', [TenantInvitationController::class, 'store'])->middleware('throttle:auth.invitations');
    Route::put('tenant/users/{id}', [TenantUserAdminController::class, 'update']);
    Route::post('tenant/users/{id}/reset-password', [TenantUserAdminController::class, 'resetPassword']);
    Route::delete('tenant/users/{id}', [TenantUserAdminController::class, 'destroy']);
});

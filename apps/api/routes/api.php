<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\LandParcelController;
use App\Http\Controllers\CropCycleController;
use App\Http\Controllers\LandAllocationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectRuleController;
use App\Http\Controllers\OperationalTransactionController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\PostingGroupController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TenantModuleController;
use App\Http\Controllers\PlatformTenantController;
use App\Http\Controllers\TenantFarmProfileController;
use App\Http\Controllers\TenantUserAdminController;
use App\Http\Controllers\Dev\DevTenantController;
use App\Http\Controllers\InvItemController;
use App\Http\Controllers\InvStoreController;
use App\Http\Controllers\InvUomController;
use App\Http\Controllers\InvItemCategoryController;
use App\Http\Controllers\InvGrnController;
use App\Http\Controllers\InvIssueController;
use App\Http\Controllers\InvStockController;
use App\Http\Controllers\InvTransferController;
use App\Http\Controllers\InvAdjustmentController;
use App\Http\Controllers\LabWorkerController;
use App\Http\Controllers\LabWorkLogController;
use App\Http\Controllers\LabourReportController;
use App\Http\Controllers\ActivityTypeController;
use App\Http\Controllers\CropActivityController;
use App\Http\Controllers\HarvestController;
use App\Http\Controllers\ShareRuleController;

Route::get('/health', [HealthController::class, 'index']);

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me']);

// Platform admin (no X-Tenant-Id; ResolveTenant skips api/platform/*)
Route::prefix('platform')->middleware(['role:platform_admin'])->group(function () {
    Route::get('tenants', [PlatformTenantController::class, 'index']);
    Route::post('tenants', [PlatformTenantController::class, 'store']);
    Route::get('tenants/{id}', [PlatformTenantController::class, 'show']);
    Route::put('tenants/{id}', [PlatformTenantController::class, 'update']);
});

// Dev-only routes (disabled in production)
Route::prefix('dev')->middleware('dev')->group(function () {
    Route::get('tenants', [DevTenantController::class, 'index']);
    Route::post('tenants', [DevTenantController::class, 'store']);
    Route::post('tenants/{id}/activate', [DevTenantController::class, 'activate']);
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
    Route::get('parties/{id}/receivables/open-sales', [PartyController::class, 'openSales']);
});

// Land Parcels (tenant_admin, accountant) — requires land module
Route::middleware(['role:tenant_admin,accountant', 'require_module:land'])->group(function () {
    Route::apiResource('land-parcels', LandParcelController::class);
    Route::post('land-parcels/{id}/documents', [LandParcelController::class, 'storeDocument']);
    Route::get('land-parcels/{id}/documents', [LandParcelController::class, 'listDocuments']);
});

// Crop Cycles (tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::apiResource('crop-cycles', CropCycleController::class);
    Route::post('crop-cycles/{id}/close', [CropCycleController::class, 'close'])->middleware('role:tenant_admin');
    Route::post('crop-cycles/{id}/open', [CropCycleController::class, 'open'])->middleware('role:tenant_admin');
});

// Land Allocations (tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::apiResource('land-allocations', LandAllocationController::class);
});

// Projects (tenant_admin, accountant)
Route::middleware(['role:tenant_admin,accountant'])->group(function () {
    Route::apiResource('projects', ProjectController::class);
    Route::post('projects/from-allocation', [ProjectController::class, 'fromAllocation']);
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
    // Project-based settlements (existing, for backward compatibility)
    Route::post('projects/{id}/settlement/preview', [SettlementController::class, 'preview']);
    Route::get('projects/{id}/settlement/offset-preview', [SettlementController::class, 'offsetPreview']);
    Route::post('projects/{id}/settlement/post', [SettlementController::class, 'post']);
    
    // Sales-based settlements (Phase 11)
    Route::get('settlements/preview', [SettlementController::class, 'previewSettlement']);
    Route::apiResource('settlements', SettlementController::class)->except(['update', 'destroy']);
    Route::post('settlements/{id}/post', [SettlementController::class, 'postSettlement']);
    Route::post('settlements/{id}/reverse', [SettlementController::class, 'reverse']);
});

// Payments (tenant_admin, accountant, operator) — requires treasury_payments module
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:treasury_payments'])->group(function () {
    Route::apiResource('payments', PaymentController::class);
    Route::get('payments/allocation-preview', [PaymentController::class, 'allocationPreview']);
    Route::post('payments/{id}/post', [PaymentController::class, 'post'])
        ->middleware('role:tenant_admin,accountant');
});

// Advances (tenant_admin, accountant, operator) — requires treasury_advances module
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:treasury_advances'])->group(function () {
    Route::apiResource('advances', AdvanceController::class);
    Route::post('advances/{id}/post', [AdvanceController::class, 'post'])
        ->middleware('role:tenant_admin,accountant');
});

// Sales (tenant_admin, accountant, operator) — requires ar_sales module
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:ar_sales'])->group(function () {
    Route::apiResource('sales', SaleController::class);
    Route::post('sales/{id}/post', [SaleController::class, 'post'])
        ->middleware('role:tenant_admin,accountant');
    Route::post('sales/{id}/reverse', [SaleController::class, 'reverse'])
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

// Posting Groups (read-only access for viewing posted transactions)
Route::middleware(['role:tenant_admin,accountant,operator'])->group(function () {
    Route::get('posting-groups/{id}', [PostingGroupController::class, 'show']);
    Route::get('posting-groups/{id}/ledger-entries', [PostingGroupController::class, 'ledgerEntries']);
    Route::get('posting-groups/{id}/allocation-rows', [PostingGroupController::class, 'allocationRows']);
    Route::post('posting-groups/{id}/reverse', [PostingGroupController::class, 'reverse'])
        ->middleware('role:tenant_admin,accountant');
    Route::get('posting-groups/{id}/reversals', [PostingGroupController::class, 'reversals']);
});

// Reports (tenant_admin, accountant, operator) — requires reports module
Route::middleware(['role:tenant_admin,accountant,operator', 'require_module:reports'])->group(function () {
    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance']);
    Route::get('reports/general-ledger', [ReportController::class, 'generalLedger']);
    Route::get('reports/project-statement', [ReportController::class, 'projectStatement']);
    Route::get('reports/project-pl', [ReportController::class, 'projectPL']);
    Route::get('reports/crop-cycle-pl', [ReportController::class, 'cropCyclePL']);
    Route::get('reports/account-balances', [ReportController::class, 'accountBalances']);
    Route::get('reports/cashbook', [ReportController::class, 'cashbook']);
    Route::get('reports/ar-ageing', [ReportController::class, 'arAgeing']);
    Route::get('reports/yield', [ReportController::class, 'yield']);
    Route::get('reports/cost-per-unit', [ReportController::class, 'costPerUnit']);
    Route::get('reports/sales-margin', [ReportController::class, 'salesMargin']);
    Route::get('reports/settlement-statement', [ReportController::class, 'settlementStatement']);
    Route::get('reports/crop-cycle-distribution', [ReportController::class, 'cropCycleDistribution']);
});

// Settings (all authenticated users can view, tenant_admin can update)
Route::middleware(['role:tenant_admin,accountant,operator'])->group(function () {
    Route::get('settings/tenant', [SettingsController::class, 'show']);
});

// tenant_admin only: settings update, tenant modules, farm profile, users
Route::middleware(['role:tenant_admin'])->group(function () {
    Route::put('settings/tenant', [SettingsController::class, 'update']);
    Route::get('tenant/modules', [TenantModuleController::class, 'index']);
    Route::put('tenant/modules', [TenantModuleController::class, 'update']);
    Route::get('tenant/farm-profile', [TenantFarmProfileController::class, 'show']);
    Route::post('tenant/farm-profile', [TenantFarmProfileController::class, 'store']);
    Route::put('tenant/farm-profile', [TenantFarmProfileController::class, 'update']);
    Route::get('tenant/users', [TenantUserAdminController::class, 'index']);
    Route::post('tenant/users', [TenantUserAdminController::class, 'store']);
    Route::put('tenant/users/{id}', [TenantUserAdminController::class, 'update']);
    Route::delete('tenant/users/{id}', [TenantUserAdminController::class, 'destroy']);
});

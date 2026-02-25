<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CropActivity;
use App\Models\Harvest;
use App\Models\InvStockBalance;
use App\Models\ProductionUnit;
use App\Models\Sale;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only farm integrity metrics for internal validation.
 * Tenant-scoped, tenant_admin only. Does not affect posting or ledger.
 */
class FarmIntegrityController extends Controller
{
    /**
     * GET /api/internal/farm-integrity
     * Returns counts for validation signals. Queries are efficient (counts/subqueries).
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $activitiesMissingProductionUnit = CropActivity::where('tenant_id', $tenantId)
            ->whereNull('production_unit_id')
            ->count();

        $harvestWithoutSale = Harvest::where('harvests.tenant_id', $tenantId)
            ->where('harvests.status', 'POSTED')
            ->whereNotIn('harvests.crop_cycle_id', function ($q) use ($tenantId) {
                $q->select('crop_cycle_id')
                    ->from('sales')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'POSTED')
                    ->whereNotNull('crop_cycle_id');
            })
            ->whereNotNull('harvests.crop_cycle_id')
            ->count();

        $salesOverdueNoPayment = (int) DB::table('sales')
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.status', 'POSTED')
            ->where('sales.due_date', '<', now()->subDays(30))
            ->whereRaw('sales.amount > COALESCE((SELECT SUM(amount) FROM sale_payment_allocations WHERE sale_payment_allocations.sale_id = sales.id), 0)')
            ->count();

        $negativeInventoryItems = InvStockBalance::where('tenant_id', $tenantId)
            ->where('qty_on_hand', '<', 0)
            ->count();

        $thirtyDaysAgo = now()->subDays(30)->toDateString();
        $productionUnitsNoActivityLast30Days = ProductionUnit::where('production_units.tenant_id', $tenantId)
            ->whereNotExists(function ($q) use ($thirtyDaysAgo) {
                $q->select(DB::raw(1))
                    ->from('crop_activities')
                    ->whereColumn('crop_activities.production_unit_id', 'production_units.id')
                    ->where('crop_activities.activity_date', '>=', $thirtyDaysAgo);
            })
            ->count();

        $livestockUnitsNegativeHeadcount = (int) DB::table('production_units')
            ->where('production_units.tenant_id', $tenantId)
            ->where('production_units.category', ProductionUnit::CATEGORY_LIVESTOCK)
            ->whereRaw('(production_units.herd_start_count + COALESCE((SELECT SUM(quantity) FROM livestock_events WHERE livestock_events.production_unit_id = production_units.id), 0)) < 0')
            ->count();

        return response()->json([
            'activities_missing_production_unit' => $activitiesMissingProductionUnit,
            'harvest_without_sale' => $harvestWithoutSale,
            'sales_overdue_no_payment' => $salesOverdueNoPayment,
            'negative_inventory_items' => $negativeInventoryItems,
            'production_units_no_activity_last_30_days' => $productionUnitsNoActivityLast30Days,
            'livestock_units_negative_headcount' => $livestockUnitsNegativeHeadcount,
        ]);
    }

    /**
     * GET /api/internal/daily-admin-review
     * Records created/edited today (from tenant audit_log). Deletes not logged — skipped.
     */
    public function dailyAdminReview(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (! $tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $createdToday = AuditLog::where('tenant_id', $tenantId)
            ->where('action', 'CREATE')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        $editedToday = AuditLog::where('tenant_id', $tenantId)
            ->where('action', 'UPDATE')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        return response()->json([
            'records_created_today' => $createdToday,
            'records_edited_today' => $editedToday,
        ]);
    }
}

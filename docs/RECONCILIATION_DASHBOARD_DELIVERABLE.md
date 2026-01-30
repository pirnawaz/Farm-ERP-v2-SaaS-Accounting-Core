# Reconciliation Dashboard – Deliverable Summary

## Implemented (per plan)

### Backend
- **Routes** ([apps/api/routes/api.php](apps/api/routes/api.php)): Added 3 GET routes under the reports middleware group:
  - `GET /api/reports/reconciliation/project?project_id=...&from=...&to=...`
  - `GET /api/reports/reconciliation/crop-cycle?crop_cycle_id=...&from=...&to=...`
  - `GET /api/reports/reconciliation/supplier-ap?party_id=...&from=...&to=...`
- **ReportController** ([apps/api/app/Http/Controllers/ReportController.php](apps/api/app/Http/Controllers/ReportController.php)): Injected `ReconciliationService` and `SettlementService`; added `reconciliationProject`, `reconciliationCropCycle`, `reconciliationSupplierAp` and private `buildReconciliationCheck`. All read-only; from/to required (YYYY-MM-DD); per-check try/catch returns FAIL with error message on exception.
- **ReconciliationService** ([apps/api/app/Services/ReconciliationService.php](apps/api/app/Services/ReconciliationService.php)): Added `reconcileCropCycleSettlementVsOT` and `reconcileCropCycleLedgerIncomeExpense` for crop-cycle aggregation. Fixed ledger double-counting in `reconcileProjectLedgerIncomeExpense` (CTE with DISTINCT posting_group_id). Fixed ambiguous `tenant_id` in `reconcileSupplierAP` (qualified as `allocation_rows.tenant_id`).
- **Feature tests** ([apps/api/tests/Feature/ReconciliationReportEndpointTest.php](apps/api/tests/Feature/ReconciliationReportEndpointTest.php)): New file with 6 tests – validation (project, crop-cycle, supplier-ap), project happy path (PASS when in sync), project fail path (FAIL when OT mismatch), crop-cycle and supplier-ap response shape.

### Frontend
- **Types** ([packages/shared/src/types.ts](packages/shared/src/types.ts)): `ReconciliationCheck`, `ReconciliationCheckStatus`, `ReconciliationResponse`.
- **API client** ([packages/shared/src/api-client.ts](packages/shared/src/api-client.ts)): `reconcileProject`, `reconcileCropCycle`, `reconcileSupplierAp`.
- **Reports API** ([apps/web/src/api/reports.ts](apps/web/src/api/reports.ts)): Wrappers `reconcileProject`, `reconcileCropCycle`, `reconcileSupplierAp` delegating to shared client.
- **Page** ([apps/web/src/pages/ReconciliationDashboardPage.tsx](apps/web/src/pages/ReconciliationDashboardPage.tsx)): Tabs (Project | Crop Cycle | Supplier AP), filters (project/crop cycle/party + From/To), “Run Checks” button, result cards (title, status badge PASS/WARN/FAIL, summary, expandable details), generated_at and “Last run”, EmptyState when no run, PrintableReport for print, CSV export (key, title, status, summary + detail keys).
- **Route** ([apps/web/src/App.tsx](apps/web/src/App.tsx)): `reports/reconciliation-dashboard` with `ModuleProtectedRoute requiredModule="reports"`.
- **Nav** ([apps/web/src/components/AppLayout.tsx](apps/web/src/components/AppLayout.tsx)): “Reconciliation Dashboard” under Reports.

### Other fixes (enabling tests)
- **ReconciliationConfidenceTest** ([apps/api/tests/Feature/ReconciliationConfidenceTest.php](apps/api/tests/Feature/ReconciliationConfidenceTest.php)): `postSaleWithCOGS` now called with `$sale` (model) and `$sale->refresh()` before call, to match `SaleCOGSService::postSaleWithCOGS(Sale $sale, ...)`.

## Files changed/added

| Area        | File                                                                 | Change |
|------------|----------------------------------------------------------------------|--------|
| Backend    | apps/api/routes/api.php                                              | +3 GET routes under reports group |
| Backend    | apps/api/app/Http/Controllers/ReportController.php                  | Constructor + ReconciliationService/SettlementService; +3 methods + buildReconciliationCheck |
| Backend    | apps/api/app/Services/ReconciliationService.php                     | +reconcileCropCycleSettlementVsOT, reconcileCropCycleLedgerIncomeExpense; CTE fix project ledger; qualified tenant_id in supplier AP |
| Backend    | apps/api/tests/Feature/ReconciliationReportEndpointTest.php        | New: 6 feature tests |
| Backend    | apps/api/tests/Feature/ReconciliationConfidenceTest.php             | postSaleWithCOGS($sale, ...) and $sale->refresh() (2 call sites) |
| Shared     | packages/shared/src/types.ts                                        | +ReconciliationCheck, ReconciliationCheckStatus, ReconciliationResponse |
| Shared     | packages/shared/src/api-client.ts                                   | +reconcileProject, reconcileCropCycle, reconcileSupplierAp |
| Web        | apps/web/src/api/reports.ts                                         | +reconcileProject, reconcileCropCycle, reconcileSupplierAp |
| Web        | apps/web/src/pages/ReconciliationDashboardPage.tsx                  | New: full page (tabs, filters, run, cards, print, CSV) |
| Web        | apps/web/src/App.tsx                                                | Import + route reports/reconciliation-dashboard |
| Web        | apps/web/src/components/AppLayout.tsx                               | +“Reconciliation Dashboard” nav item |

## Tests

- **ReconciliationReportEndpointTest** (new): **6 passed** (50 assertions).
  - Validation: project (from/to/project_id), crop-cycle (crop_cycle_id), supplier-ap (party_id).
  - Project: response shape and at least one PASS when data in sync (expenses-only seed); FAIL when OT amount corrupted.
  - Crop-cycle: response shape.
  - Supplier-ap: response shape and validation.
- **ReconciliationConfidenceTest** (existing): Supplier AP test passes. Two tests still fail (settlement total_expenses vs OT) due to COGS in ledger vs OT scope; unchanged by this implementation.

Run new reconciliation report tests:

```bash
cd apps/api
php artisan test tests/Feature/ReconciliationReportEndpointTest.php
```

## Constraints verified

- Endpoints are read-only (GET only; no mutations).
- Uses posting_date and same from/to semantics as other reports.
- No legacy accounts; uses existing ReconciliationService/ReportController patterns.
- Crop-cycle and project ledger use CTE/DISTINCT to avoid N+1 and double-counting.

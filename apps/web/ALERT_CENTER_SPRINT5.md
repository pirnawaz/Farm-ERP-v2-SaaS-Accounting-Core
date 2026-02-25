# Sprint 5: Alert Center – Implementation Summary

## New files

| File | Purpose |
|------|--------|
| `src/types/alerts.ts` | `AlertType`, `AlertSeverity`, `Alert` interface |
| `src/utils/alerts.ts` | Helpers to build/sort alerts from raw counts (no API calls) |
| `src/hooks/useAlerts.ts` | Hook that loads existing APIs/hooks and computes alert list + total count |
| `src/pages/AlertsPage.tsx` | Alert Center page at `/app/alerts` – cards, severity styling, mobile-first |

## Modified files

| File | Change |
|------|--------|
| `src/hooks/index.ts` | Export `useAlerts` |
| `src/App.tsx` | Import `AlertsPage`, add route `path="alerts"` → `<AlertsPage />` |
| `src/components/AppLayout.tsx` | Add “Alerts” under Farm group (after Today), roles: tenant_admin, accountant, operator |
| `src/pages/FarmPulsePage.tsx` | Alerts section below Cash & Dues: top 3 alerts + “View all alerts” |
| `src/pages/TodayPage.tsx` | Alerts preview at top: count + link to `/app/alerts` when `totalCount > 0`; fixed Payment display (use `payment_date` and `reference`) |

## Data sources used per alert

| Alert rule | Data source | Notes |
|------------|-------------|--------|
| **A) Pending review** | `useOperationalTransactions({ status: 'DRAFT' })` | Count of draft transactions |
| **B) Overdue customers** | `GET /api/reports/ar-ageing?as_of=...` (same as ARAgeingPage) | Count of rows where `bucket_31_60` or `bucket_61_90` or `bucket_90_plus` > 0; only when `ar_sales` module enabled |
| **C) Unpaid labour** | `usePayablesOutstanding()` → labour payables API | Count of workers with `payable_balance > 0`; only when `labour` module enabled |
| **D) Low stock** | No min_level in API | When `inventory` module enabled: single **info** alert “Low stock alerts – coming soon” with CTA to Stock on hand |
| **E) Negative margin fields** | `apiClient.getProjectPL({ from, to })` (same date range as Farm Pulse active cycle) | Count of projects in active cycle with `net_profit < 0`; only when `reports` and `projects_crop_cycles` enabled |

## TypeScript

- `tsc` (run as part of `npm run build`) completes with no type errors.  
- The only build failure observed was during `vite build` (esbuild spawn in environment), not in type-checking.

## Sidebar

- **Alerts** is under the **Farm** group, after **Today**, visible for `tenant_admin`, `accountant`, `operator` (no module gate).

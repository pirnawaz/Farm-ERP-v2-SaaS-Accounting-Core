# Sprint 6: Alert drilldowns and preferences – Summary

## New files

| File | Purpose |
|------|--------|
| `src/types/alertPreferences.ts` | `AlertPreferences`, `OverdueBucket`; `enabled`, `overdueBucket`, `negativeMarginThreshold`, `showComingSoon` |
| `src/utils/alertOverdueBucket.ts` | `isOverdueInBucketOrWorse(row, bucket)` for AR ageing rows |
| `src/hooks/useAlertPreferences.ts` | Read/write preferences to `terrava.alerts.<tenantId>` in localStorage; update helpers; sync on tenant change |
| `src/pages/alerts/OverdueCustomersAlertPage.tsx` | Drilldown: AR ageing data, filter by preference bucket; bucket totals; link to full AR Ageing report |
| `src/pages/alerts/NegativeMarginFieldsAlertPage.tsx` | Drilldown: project P&L for active cycle; list projects below threshold; cost/revenue/margin; CTAs to project detail and crop profitability |
| `src/pages/alerts/UnpaidLabourAlertPage.tsx` | Drilldown: `usePayablesOutstanding`; list workers with balance > 0; Pay CTA (same flow as LabourOwedPage) |
| `src/pages/alerts/AlertSettingsPage.tsx` | Settings at `/app/alerts/settings`: toggles per type, overdue bucket, threshold, show coming soon; mobile-first |

## Modified files

| File | Change |
|------|--------|
| `src/utils/alerts.ts` | CTA hrefs: OVERDUE_CUSTOMERS → `/app/alerts/overdue-customers`, UNPAID_LABOUR → `/app/alerts/unpaid-labour`, NEGATIVE_MARGIN_FIELDS → `/app/alerts/negative-margin`; labels updated |
| `src/hooks/useAlerts.ts` | Uses `useAlertPreferences`; filter by `enabled`; overdue count by `overdueBucket` (bucket or worse); negative margin by `negativeMarginThreshold`; LOW_STOCK coming soon only if `showComingSoon`; uses `isOverdueInBucketOrWorse` from utils |
| `src/pages/AlertsPage.tsx` | Link to “Alert settings” at bottom |
| `src/App.tsx` | Routes: `alerts/overdue-customers`, `alerts/negative-margin`, `alerts/unpaid-labour`, `alerts/settings` |

## Default preferences

- **enabled:** all `true` except `LOW_STOCK` (false).
- **overdueBucket:** `'31_60'`.
- **negativeMarginThreshold:** `0`.
- **showComingSoon:** `false`.

## How preferences affect alert counts

1. **enabled (per type)**  
   If an alert type is disabled, that alert is not added to the list and does not contribute to the total count. Other logic (e.g. overdue bucket) still runs for that type, but the result is not shown.

2. **overdueBucket**  
   Overdue customers are counted only when their overdue amount is in the chosen bucket **or worse**:
   - `31_60`: count if 31–60 or 61–90 or 90+ > 0.
   - `61_90`: count if 61–90 or 90+ > 0.
   - `90_plus`: count if 90+ > 0 only.

3. **negativeMarginThreshold**  
   Negative-margin alert count = number of projects (in active cycle) with `net_profit < negativeMarginThreshold`. Default `0` means “strictly negative”. A positive threshold (e.g. 100) would include projects with small positive profit below that value.

4. **showComingSoon**  
   The “Low stock – coming soon” info alert is included only when both `enabled.LOW_STOCK` and `showComingSoon` are true. It has no count and does not affect the numeric total.

## TypeScript

- `tsc` (run as part of `npm run build`) completes successfully. The only observed failure is during `vite build` (esbuild spawn), not type-checking.

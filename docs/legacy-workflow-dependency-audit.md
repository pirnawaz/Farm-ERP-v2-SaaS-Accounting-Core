# Legacy workflow dependency audit (Phase 3)

**Date:** 2026-04-14  
**Scope:** Crop Ops Activities / Field Work Logs, Labour Work Logs, Machinery Work Logs (Machine Usage), Inventory Issues (manual stock-used path).  
**Product context:** Phases 1–2B completed — legacy/manual flows are not promoted in normal UI; legacy **create/store** paths return **403** where implemented; routes and read paths remain for direct URL and history.

This document maps **concrete dependencies**, **removal risk**, and **reversibility**. It does not change runtime behaviour.

---

## Executive summary

| Legacy module | UI status | API store | Removal risk | Keep / flag / delete later |
|---------------|-----------|-----------|--------------|----------------------------|
| Crop activities | Hidden from main nav; list/detail via URL | Manual create **403** | **High** if removed entirely (postings, stock, payables, integrity, tests) | **Keep** read-only + post/reverse; **@deprecated** on controller |
| Labour work logs | Hidden; list/detail via URL | Manual create **403** | **High** (wages payable, harvest links, duplicate guard, tests) | **Keep** read-only + payroll context; **@deprecated** |
| Machinery work logs | Hidden; list/detail via URL | Manual create **403** | **High** (charges, field job `source_work_log_id`, traceability, tests) | **Keep** read-only; **@deprecated** |
| Inventory issues | Hidden; list/detail via URL | Manual create **403** (phase) | **High** — **not** “historical only”: machinery **in-kind** creates issues programmatically | **Keep** permanently as document type; de-emphasise manual UI only |

**Feature flags:** No dormant scaffold added in code. See [§5 Feature flags](#5-feature-flags-and-reversibility-global).

---

## 1. Crop Ops Activities / Field Work Logs

### A. Module name

Legacy **Crop Activity** documents (`CropActivity` and related inputs/labour lines), exposed as “Field Work Logs” in UI.

### B. Current status

**Mixed:** not promoted in main UI; **manual create blocked (403)**; **list, detail, edit (draft), post/reverse** and reporting queries remain.

### C. Frontend dependencies

| Type | Location | Notes |
|------|----------|--------|
| Routes | `apps/web/src/App.tsx` | `crop-ops/activities`, `…/new`, `…/:id` |
| List / table | `pages/cropOps/ActivitiesPage.tsx` | `useActivities`; row → detail |
| Detail | `pages/cropOps/ActivityDetailPage.tsx` | Breadcrumb to list |
| Form | `pages/cropOps/ActivityFormPage.tsx` | Create/edit; store blocked at API |
| Hooks / API client | `hooks/useCropOps.ts`, `api/cropOps.ts` | CRUD + `activities/timeline` |
| Labour / inventory forms | `pages/labour/WorkLogFormPage.tsx`, `pages/inventory/InvIssueFormPage.tsx` | `useActivities` for linking / context |
| Integrity (web) | `pages/internal/FarmIntegrityPage.tsx` | Metric title references activities; drill-in aimed at primary workflow in Phase 2B |
| Tests | `pages/__tests__/createPathGating.test.tsx`, `softEnforcementLegacyWarnings.test.tsx`, `manualOverrideNavigation.test.tsx` | List pages, warnings |
| Config / policy tests | `config/__tests__/cropCycleScopePolicy.test.ts`, `nav.test.ts` | Path classification |
| E2E | `playwright/specs/33_crop_ops.spec.ts` | Navigates list/new |
| **Dead / low use** | `useActivitiesTimeline` in `useCropOps.ts` | **No importer** in `apps/web/src` except definition; `api/cropOps.ts` still exposes `timeline` → `GET …/activities/timeline` |

**Farm activity timeline (`/app/farm-activity`):** `FarmActivityTimelineController` + `FarmActivityTimeline` use **Field Jobs**, harvests, sales — **not** legacy activities.

### D. Backend dependencies

| Type | Location |
|------|----------|
| Controller | `Http/Controllers/CropActivityController.php` — index, show, update, post, reverse; **store** returns 403 early |
| Posting | `Services/CropActivityPostingService.php` |
| Routes | `routes/api.php` — `crop-ops/activities`, `activities/timeline`, post/reverse |
| Models | `CropActivity`, `CropActivityInput`, `CropActivityLabour`, links from `MachineWorkLog.activity_id` |
| Field Jobs | `CropActivityType` still used by **FieldJob** (work type taxonomy) — **not legacy** |
| Integrity | `Internal/FarmIntegrityController.php` — counts `CropActivity` missing `production_unit_id`; subquery on `crop_activities` for production units “no activity” |
| Traceability | Operational traceability / allocation payloads may reference activity lineage (see traceability service usage in machinery/field job domains) |
| Tests | `ActivityDraftCreateAndPostConsumesStockAndAccruesWagesTest`, `ActivityPostFailsWhenInsufficientStockTest`, `ActivityPostIdempotencyTest`, `ActivityReverseRestoresStockAndPayablesTest`, `CropOpsModuleDisabledReturns403Test`, `FieldJobFoundationTest` (creates activities for duplicate/idempotency scenarios) |

### E. Business necessity

- **Historical and accounting:** Posted activities remain part of ledger, stock, and payables history.
- **Ongoing taxonomy:** `CropActivityType` is shared with Field Jobs — must not be conflated with “delete activities.”
- **Integrity:** Backend metrics still assume `crop_activities` table for some signals.

**Assessment:** **Still required** for valid **read, reverse, and reporting** scenarios; **not** historical-only.

### F. Removal risk

**High.** Removing models or APIs would break: posted document history, reversals, stock/wage side-effects tied to existing rows, machinery `activity_id` linkage, integrity queries, and a large Feature test suite.

### G. Recommendation

- **Keep** as **read-only compatibility** + post/reverse for existing drafts/history.
- **@deprecated** on controller (done in Phase 3).
- **Candidate for future deletion:** only redundant UI and **unused** `activities/timeline` consumer wiring — not the domain layer.

### H. Reversibility

- Re-enable manual create by removing403 early return in `CropActivityController::store` and restoring UI entry points (Phase 2B reversal).
- Restore promotions in `nav.ts` / quick actions if desired.

---

## 2. Labour Work Logs

### A. Module name

**Lab Work Log** (`LabWorkLog`) — standalone labour documents.

### B. Current status

**Mixed:** hidden from main nav; **manual create blocked**; list/detail/post/reverse and payables remain.

### C. Frontend dependencies

| Type | Location |
|------|----------|
| Routes | `App.tsx` — `labour/work-logs`, `…/new`, `…/:id` |
| List | `pages/labour/WorkLogsPage.tsx` |
| Detail / form | `WorkLogDetailPage.tsx`, `WorkLogFormPage.tsx` (`useActivities` for crop context) |
| Dashboard | `pages/labour/LabourDashboardPage.tsx` — `useWorkLogs()` for KPI-style usage |
| Alerts | `pages/alerts/UnpaidLabourAlertPage.tsx` — copy references work logs; link to `labour/work-logs` list |
| Farm Pulse | `pages/farmPulse/LabourOwedPage.tsx` — `labour/work-logs` |
| Tests | Same create-path / soft-enforcement tests as other legacy lists |

### D. Backend dependencies

| Type | Location |
|------|----------|
| Controller | `Http/Controllers/LabWorkLogController.php` |
| Posting | `Services/LabourPostingService.php` |
| Routes | `routes/api.php` — `labour/work-logs` CRUD + post/reverse |
| Models | `LabWorkLog`, relations to worker, project, machine |
| Harvest | `HarvestService` / share lines — `source_lab_work_log_id`; `HarvestShareLine::sourceLabWorkLog()` |
| Duplicate guard | `DuplicateWorkflowGuard` — labour overlap rules with field jobs / harvest |
| Tests | `LabourWorkLogPostingTest`, `WorkLogDraftCreateAndPostCreatesPayableTest`, `WorkLogPostIdempotencyTest`, `WorkLogReverseTest`, `WagePaymentClearsPayableTest`, `FieldJobFoundationTest`, `Phase4f1DuplicateWorkflowSystemTest`, module403 tests |

### E. Business necessity

- **Outstanding payables** and wage accruals may still be explained via legacy logs.
- **Harvest share lines** can reference a lab work log.

**Assessment:** **Mixed** — still **operationally relevant** for tenants with historical labour logs and payables; primary **new** capture is Field Jobs.

### F. Removal risk

**High** if domain removed: payables, harvest traceability, duplicate-workflow enforcement, and many tests.

### G. Recommendation

- **Keep** read-only + settlement-related behaviour.
- **@deprecated** on controller (Phase 3).
- **Product follow-up:** Decide whether dashboards/alerts should reference **field job labour** only for “new” semantics.

### H. Reversibility

- Same pattern as activities: restore `store` + UI CTAs.

---

## 3. Machinery Work Logs / Machine Usage

### A. Module name

**Machine Work Log** (`MachineWorkLog`) — machine usage documents.

### B. Current status

**Mixed:** hidden from main nav; **manual create blocked**; list/detail/edit/post/reverse remain.

### C. Frontend dependencies

| Type | Location |
|------|----------|
| Routes | `App.tsx` — `machinery/work-logs`, `…/new`, `…/:id`, `…/:id/edit` |
| List / summary | `pages/machinery/WorkLogsPage.tsx`, `UsageSummary` |
| Detail / form | `WorkLogDetailPage.tsx`, `WorkLogFormPage.tsx` (“Machine Usage” breadcrumbs) |
| Overview | `pages/machinery/MachineryOverviewPage.tsx` — `useWorkLogsQuery`, links to detail |
| Project | `pages/ProjectDetailPage.tsx` — **Work Logs** quick link with `project_id` |
| Traceability | `components/traceability/TraceabilityPanel.tsx` — links to `machinery/work-logs/:id` |
| Machinery service | `pages/machinery/MachineryServiceDetailPage.tsx` — may link to related `inventory/issues` (in-kind), not always work log |
| Tests | `createPathGating`, `softEnforcementLegacyWarnings` |
| E2E | `playwright/specs/34_machinery.spec.ts` |

### D. Backend dependencies

| Type | Location |
|------|----------|
| Controller | `Http/Controllers/Machinery/MachineWorkLogController.php` |
| Posting | `Services/Machinery/MachineryPostingService.php` |
| Traceability | `OperationalTraceabilityService` — `summarizeForMachineWorkLog`, summaries |
| Field Jobs | `FieldJobService` — validates `source_work_log_id` on machine lines; `DuplicateWorkflowGuard` ties charges / field jobs / logs |
| Models | `MachineWorkLog`, `MachineWorkLogCostLine`; optional link `CropActivity` |
| Charges | Machinery charge generation/posting tests use work logs |
| Tests | `MachineryWorkLogPostingTest`, `FieldJobFoundationTest`, `Phase4f1DuplicateWorkflowSystemTest`, etc. |

### E. Business necessity

- **Field job machine lines** may **reference** `source_work_log_id`.
- **Machinery charges** and duplicate-workflow rules assume work logs exist for historical and transitional flows.

**Assessment:** **Still required** for history and **cross-workflow integrity**; not historical-only.

### F. Removal risk

**High** for full removal. **Traceability UI** and field job validation would break for rows pointing at logs.

### G. Recommendation

- **Keep** read-only + charge integration paths.
- **@deprecated** on controller (Phase 3).

### H. Reversibility

- Restore manual `store` and UI “new usage” CTAs.

---

## 4. Inventory Issues / Stock Used (manual path)

### A. Module name

**Inventory Issue** (`InvIssue` / `InvIssueLine`) — stock withdrawal documents.

### B. Current status

**Mixed:** manual UI de-emphasised; manual **create may be 403**; list/detail/post/reverse remain; **system still creates issues**.

### C. Frontend dependencies

| Type | Location |
|------|----------|
| Routes | `App.tsx` — `inventory/issues`, `…/new`, `…/:id` |
| List | `pages/inventory/InvIssuesPage.tsx` |
| Detail / form | `InvIssueDetailPage.tsx`, `InvIssueFormPage.tsx` (`useActivities`) |
| Today | `pages/TodayPage.tsx` — today’s **posted** issues in feed (`/inventory/issues/:id`) |
| Stock movements | `pages/inventory/StockMovementsPage.tsx` — drill-in to issue detail |
| Tests | `cropCycleScopePolicy.test.ts` (path rules) |
| E2E | `playwright/specs/31_inventory.spec.ts` |

### D. Backend dependencies

| Type | Location |
|------|----------|
| Controller | `Http/Controllers/InvIssueController.php` |
| Posting | `Services/InventoryPostingService.php` |
| **Machinery in-kind** | `Services/Machinery/MachineryServicePostingService.php` — **`InvIssue::create`** + `postIssue` for in-kind settlement |
| Routes | `routes/api.php` — `inventory/issues` |
| Models | `InvIssue`, `InvIssueLine`; `MachineryService.in_kind_inventory_issue_id` |
| Tests | `InvIssuePostReducesStockAndCreatesAllocationsTest`, `InvIssueRequiresCropCycleAndProjectTest`, `InventoryIssueAllocationTest`, `MachineryServiceInKindAndSettlementTest`, `ProjectSettlementLedgerTruthTest`, `HarvestIncomeSettlementLoopTest`, phase-4 duplicate tests |

### E. Business necessity

- **Manual “stock used”**: de-emphasised in favour of **field job inputs** for new field work.
- **`InvIssue` entity**: **still mandatory** for machinery in-kind and all historical issue-backed allocations.

**Assessment:** **Cannot treat as historical-only.**

### F. Removal risk

**High** for domain removal. **Critical:** removing `InvIssue` breaks machinery in-kind posting and inventory history.

### G. Recommendation

- **Keep permanently** as a document type and API surface.
- Controller **@deprecated** documents **manual** entry de-emphasis only — **not** the inventory issue domain.
- **Do not** gate `MachineryServicePostingService` issue creation behind a “legacy manual only” flag.

### H. Reversibility

- Re-enable manual `POST /inventory/issues` + UI `/new` if product reverses Phase 1–2 blocks.

---

## 5. Feature flags and reversibility (global)

### Current state

- No shared `enable_legacy_manual_flows` (or similar) exists under `apps/api/config` or `apps/web/src/config`.

### Recommendation

- **Document-only for Phase 3:** Add a flag **only** when product needs a **runtime** toggle (ops / pilot / rollback without deploy).
- **Suggested future shape:** `config('features.enable_legacy_manual_document_store', false)` evaluated **only** in legacy `store()` methods — **excluding** programmatic `InvIssue::create` from machinery.
- **Tenant-level** variant could mirror module flags in `Tenant` if multi-tenant reversibility is required.

**Scaffold:** Not added in code — avoids touching bootstrap and guard ordering without a product owner.

---

## 6. Dependency classification key

| Tag | Meaning |
|-----|---------|
| **create path** | `POST …/store` or UI `/new` |
| **list/detail/history** | Index, show, edit draft |
| **reporting/query** | SQL counts, settlement, integrity |
| **traceability** | `TraceabilityPanel`, operational summaries, allocation snapshots |
| **compatibility/test-only** | Feature / Playwright / demo seed |
| **unused / candidate** | `useActivitiesTimeline` + client `timeline` API — no in-app consumer located |

---

## 7. Top follow-up actions

1. **Product:** Clarify whether **labour payables** UI should eventually pivot copy/links from “work logs” to **field job labour** only.
2. **Integrity:** Consider metrics that use **field jobs** (e.g. production unit activity in last 30 days) alongside `crop_activities`.
3. **Dead API:** Remove or document `GET …/crop-ops/activities/timeline` vs unified `farm-activity/timeline`.
4. **E2E:** Align Playwright specs with403-on-new expectations or gate skips.
5. **Traceability:** Keep machine/lab log links in `TraceabilityPanel` until field job lineage fully replaces display needs.

---

*End of audit.*

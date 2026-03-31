# Tier 1 UI baseline

This document describes the **completed Tier 1 UI standardization** for the Terrava web app (`apps/web`). It reflects conventions implemented in the repo as of this baseline—not a forward-looking spec for unbuilt features.

---

## 1. Status

**Tier 1 UI baseline is complete** for the scoped modules and flows listed below. New and refactored screens should align with these patterns unless an entry under [Intentional exceptions](#6-intentional-exceptions) applies.

---

## 2. Covered modules

| Area | Scope (examples) |
|------|------------------|
| **Inventory** | Lists (items, stores, categories, UoMs, GRNs, issues, transfers, adjustments, stock on hand, movements), dashboards, form/detail pages aligned in prior passes |
| **Crop Ops** | Activity types, activities, activity form/detail, crop ops dashboard |
| **Crop Cycles** | Season setup wizard and related Tier 1–aligned pages |
| **Accounting** | General journal list/detail/form, accounting periods |
| **Reports-adjacent flows** | Report leaf pages under `/app/reports/...` (e.g. profit & loss, balance sheet, crop profitability), general ledger (`GeneralLedgerPage`), posting group detail, links from operational detail screens to posting groups |

---

## 3. Enforced UI patterns

These patterns are the default for Tier 1 pages unless an [exception](#6-intentional-exceptions) says otherwise.

- **Page spacing rhythm**  
  - Outer wrapper typically **`space-y-6`**.  
  - List pages: filters + table grouped in **`space-y-4`**; filter row **`flex flex-wrap gap-4 items-end`**.

- **Responsive forms**  
  - Modals and form sections: **`grid grid-cols-1 md:grid-cols-2 gap-4`**; full-width fields use **`md:col-span-2`** where needed.

- **Responsive action rows**  
  - Primary/cancel clusters: **`flex flex-col-reverse sm:flex-row sm:justify-end gap-3`**.  
  - Buttons: **`w-full sm:w-auto`** so CTAs stack safely on narrow viewports.

- **Responsive filter rows**  
  - Same as list rhythm: wrapping, consistent gaps, **`items-end`** alignment.

- **Table overflow**  
  - Wide tables wrapped in **`overflow-x-auto`** (and similar) where horizontal scroll is required.

- **PageHeader (leaf / detail / internal drill-ins)**  
  - Use **`PageHeader`** with **`backTo`**, optional **`breadcrumbs`**, and optional **`right`** actions—not a one-off custom back/title bar—for internal pages that are not module roots.

- **Report leaf pages**  
  - **`PageHeader`** includes **`backTo="/app/reports"`** where the screen is a child of the reports area (e.g. profit & loss, balance sheet, crop profitability, general ledger).

---

## 4. Route policy

- **Client routes** must live under the authenticated app shell: paths MUST use the **`/app/...`** prefix (e.g. `/app/inventory`, `/app/accounting/journals`, `/app/reports/general-ledger`).

- **Posting group** links in the UI MUST use **`/app/posting-groups/:id`** (never bare `/posting-groups/...`).

- **Bare routes** (no `/app` prefix) are not used for in-app navigation unless explicitly intentional and documented (there should be no accidental bare links for shell routes).

---

## 5. Breadcrumb policy

- **Farm hub**  
  - First crumb: **`Farm`** → **`/app/dashboard`** on Tier 1 flows that use this convention (e.g. inventory lists, season setup wizard).

- **Reporting / accounting journal flows**  
  - First crumb: **`Profit & Reports`** → **`/app/reports`**; deeper crumbs point at the relevant report or journal list routes.

Exact crumb chains may vary by feature, but the **first crumb target** should follow one of the above patterns consistently for new work.

---

## 6. Intentional exceptions

- **Reports hub** (`ReportsPage` at `/app/reports`): uses a **plain `h1`** and tab navigation—**not** `PageHeader`—consistent with `PageHeader` guidance for module-style landing pages.

- **Module dashboards** (e.g. inventory, crop ops): may use a **simplified header** (plain title) rather than `PageHeader`.

- **Daily-book React pages**: **`DailyBookEntriesPage`** / **`DailyBookEntryFormPage`** exist in the codebase but are **not registered** in `App.tsx`; they are not part of the active routed UX.

---

## 7. Known legacy debt

- **Daily-book UI** pages are **orphaned** (not routed); comments in those files note legacy path assumptions. **`PostModal`** may still call **daily-book APIs**; that is separate from SPA routing.

- **Breadcrumbs**: some modules may still use **`/app/farm-pulse`** or **`term('navFarm')`** as the first crumb where Farm → dashboard was not yet aligned.

- **`PageHeader`** includes bottom margin; combined with parent **`space-y-6`**, vertical spacing may feel **slightly loose** in places—acceptable debt unless tightened globally later.

---

## 8. Rule for future work

**All new or refactored Tier 1–style pages MUST follow this baseline**: spacing rhythm, responsive forms and actions, filter rows, table overflow, `/app` routes and posting-group links, breadcrumb conventions, and `PageHeader` vs plain hub headers as described above—unless there is a documented, intentional exception.

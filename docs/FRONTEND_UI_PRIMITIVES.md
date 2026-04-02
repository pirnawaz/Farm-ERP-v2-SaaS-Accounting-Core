# Terrava frontend UI primitives (internal note)

Small, implementation-oriented guide for keeping `apps/web` UI consistent.

This is a **presentation-only** convention note. Do not change business logic, routing, permissions, API semantics, or accounting rules to “make the UI nicer”.

---

## Page width and outer layout

### `PageContainer` (`apps/web/src/components/PageContainer.tsx`)

- **Use it for**: page-level wrappers to keep content widths consistent across the app.
  - `width="standard"`: dashboards, lists, detail pages, drilldowns, reports (inherits shell width).
  - `width="form"`: primary create/edit flows (centered, readable form column).
  - `width="narrow"`: rare, intentionally constrained screens.
- **Don’t use it for**: ad hoc `max-w-* mx-auto` wrappers on random pages. Treat those as an exception with a clear UX reason.

---

## Reports: page composition and states

### Report layout primitives (`apps/web/src/components/report/ReportLayout.tsx`)

- **Use them for**: consistent structure and spacing on report leaf pages.
  - `ReportPage`: standard report page rhythm (`space-y-6`).
  - `ReportFilterCard`: the filter/controls container on a report.
  - `ReportSectionCard`: card framing for main report content blocks (tables/sections).
  - `ReportKindBadge`: explicit “Accounting report” vs “Operational analytics” label.
- **Don’t use them for**: module hub pages (e.g. `/app/reports` landing) that intentionally use a different header pattern.

### Report metadata + states (`apps/web/src/components/report/*`)

- **Use `ReportMetadataBlock` for**: currency/timezone/locale + “Generated at”, plus optional period/as-of labels.
- **Use `ReportLoadingState` / `ReportErrorState` / `ReportEmptyStateCard` for**: consistent loading/error/empty framing on report pages.
- **Don’t use them for**: non-report pages unless the UI is truly “report-like” (period-scoped analytics screen).

---

## Empty states (non-report)

### `EmptyState` (`apps/web/src/components/EmptyState.tsx`)

- **Use it for**: “no records yet”, “not found”, and other calm empty situations that need a title + optional description + optional single action.
- **Don’t use it for**:
  - Loading states (use the existing spinners/loading components).
  - Error states that should show an error banner/message (don’t disguise failures as “no data”).

---

## Tables: lists and reports

### List tables: `DataTable` (`apps/web/src/components/DataTable.tsx`)

- **Use it for**: standard list pages where rows are clickable and you need a simple column mapping.
- **Conventions**:
  - Numeric columns: set `numeric: true` on the column (right align + `tabular-nums`).
  - Prefer `emptyMessage` for simple list empties; use `EmptyState` when you need a richer “what to do next”.
- **Don’t use it for**: complex report totals/subtotals or print-aligned accounting tables.

### Report tables: `ReportTable` primitives (`apps/web/src/components/report/ReportTable.tsx`)

- **Use them for**: accounting-style report tables and tables that need consistent alignment/totals/empty rows.
  - Use `ReportTableCell numeric align="right"` for money/amount columns.
  - Use `ReportTotalsRow` for subtotal/grand total styling.
  - Use `ReportEmptyState` for “no rows” inside a table body.
- **Don’t use them for**: small, one-off layout tables inside unrelated pages unless the semantics are “report table”.

---

## Filter bars

### `FilterBar` primitives (`apps/web/src/components/FilterBar.tsx`)

- **Use them for**: consistent filter layouts across reports and list/drilldown pages.
  - `FilterBar`: vertical rhythm for a filter area.
  - `FilterGrid`: standard responsive grid for filter controls (override columns via `className` if needed).
  - `FilterField`: label + consistent input/select styling wrapper.
  - `FilterCheckboxField`: consistent checkbox alignment.
- **Don’t use them for**: full form pages (use `FormField` / form layout primitives instead).

---

## KPI / stat tiles

### `KpiCard` / `KpiGrid` (`apps/web/src/components/KpiCard.tsx`)

- **Use them for**: small metric tiles (dashboards, detail-page summaries, report-adjacent KPIs).
  - Keep values `tabular-nums` for money/count readability.
  - Use `tone` and `emphasized` for subtle “primary metric” emphasis (e.g. Margin).
  - Use `padding="none"` when embedding KPIs inside another card without adding nested padding.
- **Don’t use them for**: large interactive cards or complex drilldown widgets (those can remain custom components).

---

## Forms: sections and actions

### Form layout primitives (`apps/web/src/components/FormLayout.tsx`)

- **Use them for**: consistent form-page structure on create/edit flows.
  - `FormCard`: the main white form container.
  - `FormSection`: section heading + optional description + consistent spacing.
  - `FormActions`: consistent “Cancel / Save” action row (responsive stack).
- **Don’t use them for**:
  - Non-form pages.
  - Complex wizard layouts where the container and actions are intentionally different (document exceptions if needed).

### `FormField` (`apps/web/src/components/FormField.tsx`)

- **Use it for**: label + error + consistent input/select focus styling.
- **Don’t use it for**: filter bars (prefer `FilterField`).

---

## Badges / chips / pills

### `Badge` (`apps/web/src/components/Badge.tsx`)

- **Use it for**: status/severity/category pills in tables, cards, and detail metadata.
  - Map semantics consistently: `success` / `warning` / `danger` / `info` / `neutral`.
  - Keep the text value unchanged (do not change status enums/casing to “look better”).
- **Don’t use it for**: large banners or alert callouts (those should remain card/banner components).


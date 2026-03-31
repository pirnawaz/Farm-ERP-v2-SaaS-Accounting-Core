# Terrava Presentation Standards v1

This document describes how Terrava formats values for **people** (UI and browser print/PDF) versus **spreadsheets** (CSV/Excel exports). It complements the implementation in `apps/web/src/config/presentation.ts`, `apps/web/src/utils/formatting.ts`, and `apps/web/src/utils/exportFormatting.ts`.

## Principles

- **Accounting data is canonical on the backend** — we never change amounts, dates, or posting rules in the client.
- **Tenant localisation** (`locale`, `timezone`, `currency_code` from tenant settings) drives human-readable display.
- **One system** — shared labels (`REPORT_LABELS`), missing-value glyph (`DISPLAY_MISSING`), and helpers avoid ad hoc `toFixed` / raw ISO in product code.

## Human-facing display (UI + print)

| Concern | Helper | Notes |
|--------|--------|--------|
| Calendar date | `formatDate` | Locale + timezone; variants `short` / `medium` / `long`. |
| Range | `formatDateRange` | Separator ` – ` (en dash). |
| Timestamp | `formatDateTime` | Medium date + short time in tenant TZ. |
| Money | `formatMoney` | **2** decimals, currency symbol, **accounting-style negatives** (parentheses) by default via `currencySign: 'accounting'`. |
| Counts / metrics | `formatNumber` | Grouping per locale. |
| Ratio → % | `formatPercent` | Input is a **ratio** (e.g. `0.25` → 25%). |
| Missing | `DISPLAY_MISSING` (`—`) | Use `formatNullableValue` when wrapping custom formatters. |

React code should use **`useFormatting()`** so tenant settings apply automatically. For locale/currency without formatting, use **`useLocalisation()`**.

### Print / PDF (browser)

Reports use `PrintableReport`, `PrintHeader`, and `PrintFooter`. Headers use **`REPORT_LABELS.generatedAt`** plus `formatDateTime(new Date())`. The right column defaults to that string; override `metaRight` only when you need a full custom line.

## Exports (CSV / Excel-oriented)

**Policy:** exports are **machine-friendly**, not copies of the screen.

| Field type | Rule |
|------------|------|
| Money | Use `exportAmountForSpreadsheet` → fixed decimals, **no** symbol or grouping. Keep a separate `currency_code` column when amounts are monetary. |
| Dates | Prefer `exportDateIsoYmd` → `YYYY-MM-DD` unless the API field is already that shape. |
| Text | `exportNullableString` for optional strings (empty cell if missing). |

Optional **metadata rows** at the top of CSV (see `exportToCSV` in `csvExport.ts`) document scope; keys should stay **stable** (e.g. `reporting_period_start`).

UTF-8 **BOM** is prepended so Excel opens UTF-8 reliably.

## Adding a new report or export

1. **Screen + print:** Use `useFormatting()`; build `PrintableReport` `metaLeft` with **`metaReportingPeriodLabel(formatDateRange(from, to))`** from `utils/reportPresentation.ts`, or **`metaAsOfLabel(formatDate(ymd))`** for balance-sheet-style “as of” reports.
2. **CSV:** Map API rows to plain numeric strings with `exportAmountForSpreadsheet`; pass explicit column order; add `metadataRows` if the export needs context — prefer **`terravaBaseExportMetadataRows`** in `utils/reportPageMetadata.ts` (uses `base_currency`, `locale`, `timezone` from **`useLocalisation()`**) and merge domain-specific rows at the call site.
3. **Labels:** Import copy from `config/presentation.ts` (`REPORT_LABELS`, `EMPTY_COPY`, `DOCUMENT_LABELS`) instead of new strings when the meaning matches.
4. **Empty tables:** Prefer **`ReportEmptyState`** from `components/report/ReportTable.tsx` (or `EMPTY_COPY` text) instead of bespoke sentences.

## Report table primitives

Reusable building blocks live in `apps/web/src/components/report/ReportTable.tsx` (also exported from `components/report/index.ts`):

| Component | Role |
|-----------|------|
| `ReportTable` | Outer scroll + `<table>` shell |
| `ReportTableHead` / `ReportTableBody` / `ReportTableFoot` | Semantic sections |
| `ReportTableRow` | Row |
| `ReportTableHeaderCell` / `ReportTableCell` | Cells; use `align="right"` and `numeric` for amounts |
| `ReportTotalsRow` | Subtotal / grand total styling (`variant` + `label` + trailing `<td>` children) |
| `ReportEmptyState` | Single empty row with default `EMPTY_COPY.noDataForPeriod` |

**Account Balances** is a reference implementation using these primitives for both screen and print tables.

## Invoices & generated documents

- **`DOCUMENT_LABELS`** — invoice/statement field labels (invoice number, dates, bill-to, line columns, notes).
- **`REPORT_LABELS`** — shared concepts (`total`, `project`, `cropCycle`) on documents where they match reports.
- Browser print for invoices/statements uses `.print-document`; CSS in `index.css` ensures these regions are visible when printing (alongside `#terrava-print-root` reports).

## Migrating an older report page

1. Replace `From … to …` strings with `metaReportingPeriodLabel(formatDateRange(from, to))` (or `metaAsOfLabel`).
2. Replace raw print table amounts with `formatMoney` where the API sends numeric/string money.
3. Map CSV exports through `exportFormatting` helpers and add `metadataRows` where policy applies.
4. Optionally refactor the main table to `ReportTable` primitives for alignment consistency.

## Files

| Area | Location |
|------|----------|
| Constants & copy | `apps/web/src/config/presentation.ts` |
| Display formatters | `apps/web/src/utils/formatting.ts`, `apps/web/src/hooks/useFormatting.ts` |
| Export value shaping | `apps/web/src/utils/exportFormatting.ts`, `apps/web/src/utils/csvExport.ts` |
| Print shell | `apps/web/src/components/print/*` |
| On-screen report metadata strip | `apps/web/src/components/report/ReportMetadataBlock.tsx` |
| Report table / totals / empty row | `apps/web/src/components/report/ReportTable.tsx` |
| Meta line helpers | `apps/web/src/utils/reportPresentation.ts` |
| Report page metadata types | `apps/web/src/config/reportPageMetadata.ts` |
| Report metadata + CSV row helper | `apps/web/src/utils/reportPageMetadata.ts` |
| Reports hub tab config | `apps/web/src/config/reportsHub.ts` |
| Printable document primitives | `apps/web/src/components/document/*` |

See **`docs/REPORTS_AND_DOCUMENTS.md`** for report metadata, document components, and Excel-oriented export checklists.

## Versioning

Bump **`PRESENTATION_V1.version`** in `presentation.ts` when conventions change in a breaking way for contributors or export consumers.

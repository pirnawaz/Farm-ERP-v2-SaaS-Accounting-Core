# Reports, printable documents, and exports

Practical guide for contributors. Accounting rules and API payloads are unchanged here — this covers **frontend presentation** only.

## Page / report metadata

**Config shape:** `ReportPageMetadata` in `apps/web/src/config/reportPageMetadata.ts`.

Use it to answer: report name, period mode (`range` | `asOf` | `none`), scope hint, whether export/print exist, and preferred `EMPTY_COPY` key for empty states.

**Hub tabs:** `REPORT_HUB_METADATA` in `apps/web/src/config/reportsHub.ts` describes the in-app Reports hub (`ReportsPage`) tabs so they stay aligned with standalone routes.

**Wiring helpers:** `apps/web/src/utils/reportPageMetadata.ts`

| Helper | Use |
|--------|-----|
| `getReportMetadataBlockPeriodProps(periodMode, params, formatters)` | Props for `ReportMetadataBlock` (reporting period or as-of). |
| `getPrintableReportMetaLeft(...)` | Single meta line for `PrintableReport` / print headers. |
| `terravaBaseExportMetadataRows({ reportExportName, baseCurrency, period, locale?, timezone? })` | Standard CSV metadata rows; merge with domain-specific rows at the export call site. |

**Period styles:**

- **Range** — trial balance, general ledger: `from` / `to` ISO dates.
- **As-of** — balances, statements: one `asOf` date.
- **None** — rare; metadata helpers return empty period fields.

Always pair UI copy with **`metaReportingPeriodLabel` / `metaAsOfLabel`** from `utils/reportPresentation.ts` where you need a single string (already composed inside the helpers above).

## Printable document framework

Shared layout pieces live under `apps/web/src/components/document/`:

| Component | Role |
|-----------|------|
| `DocumentPrintShell` | Root wrapper with `print-document` class for print CSS. |
| `DocumentMetaGrid` | Label/value pairs (document no., dates, period). |
| `DocumentLineItemsTable` | Line items with optional columns. |
| `DocumentTotalsBlock` | Labelled total lines. |
| `DocumentNotesBlock` | Notes / payment text. |

**Reference:** invoice print on `SaleDetailPage` composes these with `DOCUMENT_LABELS` / `REPORT_LABELS` and existing `PrintHeader` where applicable.

## Shared report UI

- **`ReportMetadataBlock`** — tenant currency, timezone, locale, generated timestamp, plus period/crop cycle when passed in.
- **`ReportTable` primitives** — `components/report/ReportTable.tsx` (see `PRESENTATION_STANDARDS.md`).
- **`EMPTY_COPY`** — consistent empty-state titles/descriptions.

## Exports (CSV / Excel)

**Policy:** UI = human-formatted; CSV = **structured** for spreadsheets.

1. Amounts: `exportAmountForSpreadsheet` (no symbols; fixed decimals).
2. Dates: `exportDateIsoYmd` or raw `YYYY-MM-DD` from API when already canonical.
3. Include a **`currency_code` column** when rows are monetary multi-currency.
4. **Metadata rows:** use `terravaBaseExportMetadataRows` for `export`, `base_currency`, optional `locale` / `timezone`, and period keys. Add party/project IDs etc. as extra rows next to this helper.
5. **UTF-8 BOM:** `exportToCSV` in `csvExport.ts` prepends `\uFEFF` so Excel recognises UTF-8.

**Representative pages** using the base metadata helper: Trial Balance, General Ledger, Account Balances.

## Choosing display vs export formatting

| Context | Use |
|---------|-----|
| Screen, browser print, PDF-style preview | `useFormatting()` (`formatMoney`, `formatDate`, …) |
| CSV download | `exportFormatting` helpers + explicit headers + metadata rows |

Do not put locale-formatted money strings (with symbols) in CSV amount columns unless the export is explicitly “presentation CSV” (not the default for Terrava reports).

## File map

| Topic | Path |
|-------|------|
| Metadata types | `apps/web/src/config/reportPageMetadata.ts` |
| Hub tab metadata | `apps/web/src/config/reportsHub.ts` |
| Metadata + CSV helpers | `apps/web/src/utils/reportPageMetadata.ts` |
| Labels | `apps/web/src/config/presentation.ts` |
| CSV + BOM | `apps/web/src/utils/csvExport.ts` |
| Document primitives | `apps/web/src/components/document/` |

See also **`docs/PRESENTATION_STANDARDS.md`** for the full v1 standards table.

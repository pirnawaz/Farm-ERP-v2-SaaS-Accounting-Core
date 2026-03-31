/**
 * Terrava Presentation Standards v1 — single source of truth for UI, print/PDF, and export *policy*.
 * Does not change accounting rules; display and export conventions only.
 */

/** Shown for null, undefined, invalid numbers/dates, or blank strings (unless a control needs empty). */
export const DISPLAY_MISSING = '—';

/** Separator between start and end in human-readable date ranges (en dash + spaces). */
export const DATE_RANGE_SEPARATOR = ' – ';

/**
 * Standard copy for empty tables, report sections, and exports (use consistently; override when domain-specific).
 */
export const EMPTY_COPY = {
  noDataForPeriod: 'No data for this period.',
  noTransactions: 'No transactions found.',
  noActivity: 'No activity recorded.',
  noRecords: 'No records found.',
  generic: 'No data found.',
} as const;

/**
 * Metadata and column labels shared across UI and print layouts.
 * Prefer these over ad hoc strings so reports feel like one system.
 */
export const REPORT_LABELS = {
  /** Timestamp when the view/PDF was produced (includes time where shown). */
  generatedAt: 'Generated at',
  /** Calendar date only variant for lightweight headers. */
  generatedOn: 'Generated on',
  reportingPeriod: 'Reporting period',
  /** Single-date balance / ageing reports (as-of date). */
  asOf: 'As of',
  cropCycle: 'Crop cycle',
  currency: 'Currency',
  timezone: 'Timezone',
  locale: 'Locale',
  project: 'Project',
  total: 'Total',
  subtotal: 'Subtotal',
  grandTotal: 'Grand total',
  preparedBy: 'Prepared in Terrava',
} as const;

/**
 * Labels for printed invoices and account-style documents (aligned with reports metadata discipline).
 */
export const DOCUMENT_LABELS = {
  invoice: 'Invoice',
  invoiceNo: 'Invoice No.',
  invoiceDate: 'Invoice date',
  dueDate: 'Due date',
  billTo: 'Bill to',
  notes: 'Notes',
  accountStatement: 'Account statement',
  party: 'Party',
  partyTypes: 'Party types',
  closingPayable: 'Closing payable',
  closingReceivable: 'Closing receivable',
  quantity: 'Quantity',
  unitPrice: 'Unit price',
  lineTotal: 'Line total',
  item: 'Item',
  store: 'Store',
} as const;

/**
 * Negative money (UI + print): accounting-style parentheses, e.g. (Rs 22,050.00).
 * Implemented via Intl `currencySign: 'accounting'` in formatMoney (see utils/formatting).
 */
export const NEGATIVE_MONEY_STYLE = 'accounting' as const;

/**
 * Raw vs formatted export policy (CSV/Excel-oriented).
 * - UI and browser print/PDF: always use formatMoney, formatDate, etc.
 * - Machine-friendly exports: numeric amounts without symbols or grouping; dates as ISO YYYY-MM-DD; include currency_code column when amounts are monetary.
 */
export const EXPORT_POLICY = {
  /** Amount fields: fixed decimal string without thousands separators, suitable for spreadsheet SUM(). */
  amountDecimalPlaces: 2,
  /** Date fields: ISO calendar date in exports unless a report explicitly needs datetime. */
  dateFormat: 'YYYY-MM-DD' as const,
  /** Optional first rows: key-value context (see csvExport metadataRows). */
  includeMetadataRows: true,
} as const;

export const PRESENTATION_V1 = {
  version: 1 as const,
  displayMissing: DISPLAY_MISSING,
  dateRangeSeparator: DATE_RANGE_SEPARATOR,
  emptyCopy: EMPTY_COPY,
  reportLabels: REPORT_LABELS,
  documentLabels: DOCUMENT_LABELS,
  exportPolicy: EXPORT_POLICY,
  negativeMoneyStyle: NEGATIVE_MONEY_STYLE,
} as const;

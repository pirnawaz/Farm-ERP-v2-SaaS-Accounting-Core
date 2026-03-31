/**
 * Lightweight standard for report-like pages (hub reports, embedded tabs, full routes).
 * Use with helpers in `utils/reportPageMetadata.ts` — not a runtime framework.
 */

import { EMPTY_COPY } from './presentation';

export type EmptyCopyKey = keyof typeof EMPTY_COPY;

/** How the report is scoped in the product sense (informational; drives docs/UX copy only). */
export type ReportScopeType =
  | 'tenantWide'
  | 'project'
  | 'cropCycle'
  | 'party'
  | 'mixed';

/**
 * Period behaviour for filters and metadata lines.
 * - `range` — from/to inclusive
 * - `asOf` — balances / statements “as at” one date
 * - `none` — no date filter (rare)
 */
export type ReportPeriodMode = 'range' | 'asOf' | 'none';

/** Document/report identifier for analytics or contributor docs (optional). */
export type ReportKind =
  | 'trial_balance'
  | 'general_ledger'
  | 'project_statement'
  | 'account_balances'
  | 'cashbook'
  | 'custom';

/**
 * Declarative description of a report surface. Pages construct this once and pass
 * pieces to metadata helpers / `ReportMetadataBlock` / print titles.
 */
export interface ReportPageMetadata {
  /** Browser tab / hub section title */
  pageTitle: string;
  /** Human report name (may match pageTitle) */
  reportTitle: string;
  subtitle?: string;
  periodMode: ReportPeriodMode;
  scopeType?: ReportScopeType;
  /** Stable id for docs and optional telemetry */
  reportKind?: ReportKind | string;
  exportAvailable?: boolean;
  printAvailable?: boolean;
  /** Prefer EMPTY_COPY keys for consistency */
  emptyStateKey?: EmptyCopyKey;
}

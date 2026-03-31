/**
 * Helpers wiring ReportPageMetadata + period fields → print lines and ReportMetadataBlock props.
 */

import { REPORT_LABELS } from '../config/presentation';
import type { ReportPeriodMode } from '../config/reportPageMetadata';
import { metaAsOfLabel, metaReportingPeriodLabel } from './reportPresentation';

export type ReportDateFormatters = {
  formatDate: (d: string | Date | number | null | undefined) => string;
  formatDateRange: (from: string, to: string) => string;
};

export type PeriodParams =
  | { mode: 'range'; from: string; to: string }
  | { mode: 'asOf'; asOf: string }
  | { mode: 'none' };

/**
 * Single line for `PrintableReport` `metaLeft` (reporting period or as-of).
 */
export function getPrintableReportMetaLeft(
  periodMode: ReportPeriodMode,
  params: PeriodParams,
  fmt: ReportDateFormatters,
): string | undefined {
  if (periodMode === 'none' || params.mode === 'none') {
    return undefined;
  }
  if (periodMode === 'asOf' && params.mode === 'asOf') {
    return metaAsOfLabel(fmt.formatDate(params.asOf));
  }
  if (periodMode === 'range' && params.mode === 'range') {
    return metaReportingPeriodLabel(fmt.formatDateRange(params.from, params.to));
  }
  return undefined;
}

/**
 * Props subset for `ReportMetadataBlock` from period parameters.
 */
export function getReportMetadataBlockPeriodProps(
  periodMode: ReportPeriodMode,
  params: PeriodParams,
  fmt: ReportDateFormatters,
): { reportingPeriodRange?: string; asOfDate?: string } {
  if (periodMode === 'none' || params.mode === 'none') {
    return {};
  }
  if (periodMode === 'asOf' && params.mode === 'asOf') {
    return { asOfDate: fmt.formatDate(params.asOf) };
  }
  if (periodMode === 'range' && params.mode === 'range') {
    return { reportingPeriodRange: fmt.formatDateRange(params.from, params.to) };
  }
  return {};
}

/**
 * Standard CSV metadata rows (keys snake_case for Excel review / machine grep).
 * Does not replace domain-specific rows (party_id, etc.) — merge at call site.
 */
export function terravaBaseExportMetadataRows(opts: {
  reportExportName: string;
  baseCurrency: string;
  period: PeriodParams;
  /** Optional tenant display context */
  locale?: string;
  timezone?: string;
}): string[][] {
  const rows: string[][] = [['export', opts.reportExportName], ['base_currency', opts.baseCurrency]];
  if (opts.locale) {
    rows.push(['locale', opts.locale]);
  }
  if (opts.timezone) {
    rows.push(['timezone', opts.timezone]);
  }
  if (opts.period.mode === 'range') {
    rows.push(['reporting_period_start', opts.period.from], ['reporting_period_end', opts.period.to]);
  } else if (opts.period.mode === 'asOf') {
    rows.push(['as_of', opts.period.asOf]);
  }
  return rows;
}

export { REPORT_LABELS };

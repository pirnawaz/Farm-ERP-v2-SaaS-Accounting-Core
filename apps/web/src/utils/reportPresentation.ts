import { REPORT_LABELS } from '../config/presentation';

/** Use with `formatDateRange(from, to)` output for print/screen metadata lines. */
export function metaReportingPeriodLabel(formattedRange: string): string {
  return `${REPORT_LABELS.reportingPeriod}: ${formattedRange}`;
}

/** Use with `formatDate(ymd)` for as-of reports. */
export function metaAsOfLabel(formattedDate: string): string {
  return `${REPORT_LABELS.asOf}: ${formattedDate}`;
}

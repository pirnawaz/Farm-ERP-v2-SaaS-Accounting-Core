import { REPORT_LABELS } from '../../config/presentation';
import { useFormatting } from '../../hooks/useFormatting';
import { useLocalisation } from '../../hooks/useLocalisation';

type ReportMetadataBlockProps = {
  /** Human-readable range, e.g. from formatDateRange(from, to) */
  reportingPeriodRange?: string;
  /** Single as-of date display, e.g. from formatDate(ymd) */
  asOfDate?: string;
  cropCycleLabel?: string;
  className?: string;
};

/**
 * Shared metadata strip for report-like screens (not print-only — complements PrintableReport headers).
 */
export function ReportMetadataBlock({
  reportingPeriodRange,
  asOfDate,
  cropCycleLabel,
  className = '',
}: ReportMetadataBlockProps) {
  const { formatDateTime } = useFormatting();
  const { currency_code, timezone, locale } = useLocalisation();

  return (
    <div
      className={`rounded-lg border border-gray-200 bg-gray-50/80 px-4 py-3 text-xs text-gray-600 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 ${className}`}
    >
      {reportingPeriodRange && (
        <div>
          <span className="font-medium text-gray-700">{REPORT_LABELS.reportingPeriod}: </span>
          {reportingPeriodRange}
        </div>
      )}
      {asOfDate && (
        <div>
          <span className="font-medium text-gray-700">{REPORT_LABELS.asOf}: </span>
          {asOfDate}
        </div>
      )}
      {cropCycleLabel && (
        <div>
          <span className="font-medium text-gray-700">{REPORT_LABELS.cropCycle}: </span>
          {cropCycleLabel}
        </div>
      )}
      <div>
        <span className="font-medium text-gray-700">{REPORT_LABELS.currency}: </span>
        {currency_code}
      </div>
      <div>
        <span className="font-medium text-gray-700">{REPORT_LABELS.timezone}: </span>
        {timezone}
      </div>
      <div className="sm:col-span-2">
        <span className="font-medium text-gray-700">{REPORT_LABELS.locale}: </span>
        {locale}
      </div>
      <div className="sm:col-span-2">
        <span className="font-medium text-gray-700">{REPORT_LABELS.generatedAt}: </span>
        {formatDateTime(new Date())}
      </div>
    </div>
  );
}

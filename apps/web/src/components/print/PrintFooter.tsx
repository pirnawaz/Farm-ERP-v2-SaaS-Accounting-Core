import { REPORT_LABELS } from '../../config/presentation';
import { useFormatting } from '../../hooks/useFormatting';

/**
 * Print-only footer — matches Terrava Presentation Standards (timestamp in tenant locale/timezone).
 */
export function PrintFooter() {
  const { formatDateTime } = useFormatting();

  return (
    <div className="print-footer">
      <div className="text-xs text-gray-500">
        {REPORT_LABELS.preparedBy} · {REPORT_LABELS.generatedAt}: {formatDateTime(new Date())}
      </div>
    </div>
  );
}

import { REPORT_LABELS } from '../../config/presentation';
import { useFormatting } from '../../hooks/useFormatting';

interface PrintHeaderProps {
  title: string;
  subtitle?: string;
  /** Left column (e.g. reporting period). */
  metaLeft?: string;
  /**
   * Overrides the entire right column. Default: `${REPORT_LABELS.generatedAt}: <tenant-local datetime>`.
   */
  metaRight?: string;
  showLogo?: boolean;
}

export function PrintHeader({
  title,
  subtitle,
  metaLeft,
  metaRight,
  showLogo = true,
}: PrintHeaderProps) {
  const { formatDateTime } = useFormatting();
  const rightText =
    metaRight ?? `${REPORT_LABELS.generatedAt}: ${formatDateTime(new Date())}`;

  return (
    <div className="print-header hidden">
      <div className="print-header-content">
        <div className="print-header-top">
          {showLogo && (
            <div className="print-logo">
              <img
                src="/brand/terrava_logo_clean.png"
                alt="Terrava"
                className="h-8 w-auto"
                onError={(e) => {
                  (e.target as HTMLImageElement).style.display = 'none';
                }}
              />
            </div>
          )}
        </div>
        <div className="print-title-section">
          <h1 className="print-title">{title}</h1>
          {subtitle && <div className="print-subtitle">{subtitle}</div>}
        </div>
        <div className="print-meta-row">
          <div className="print-meta-left">{metaLeft ?? ''}</div>
          <div className="print-meta-right">{rightText}</div>
        </div>
        <div className="print-header-divider"></div>
      </div>
    </div>
  );
}

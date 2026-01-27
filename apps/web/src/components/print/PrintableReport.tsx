import { ReactNode } from 'react';
import { PrintHeader } from './PrintHeader';
import { PrintFooter } from './PrintFooter';

interface PrintableReportProps {
  title: string;
  subtitle?: string;
  metaLeft?: string;
  metaRight?: string;
  showLogo?: boolean;
  children: ReactNode;
}

/**
 * Wrapper component for printable reports.
 * This component creates a print-only container that is the ONLY thing visible when printing.
 * The content inside should be the report table/content WITHOUT overflow wrappers.
 */
export function PrintableReport({
  title,
  subtitle,
  metaLeft,
  metaRight,
  showLogo = true,
  children,
}: PrintableReportProps) {
  return (
    <div id="terrava-print-root" className="hidden print-only">
      <PrintHeader
        title={title}
        subtitle={subtitle}
        metaLeft={metaLeft}
        metaRight={metaRight}
        showLogo={showLogo}
      />
      <div className="print-content">
        {children}
      </div>
      <PrintFooter />
    </div>
  );
}

import { ReactNode } from 'react';
import { EMPTY_COPY } from '../../config/presentation';

/**
 * Terrava report table shell — matches accounting report styling used across Trial Balance–style pages.
 * Adopt incrementally; children supply rows and cells.
 */

export function ReportTable({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <div className="overflow-x-auto">
      <table className={`min-w-full divide-y divide-gray-200 ${className}`}>{children}</table>
    </div>
  );
}

export function ReportTableHead({ children }: { children: ReactNode }) {
  return <thead className="bg-[#E6ECEA]">{children}</thead>;
}

export function ReportTableBody({ children }: { children: ReactNode }) {
  return <tbody className="bg-white divide-y divide-gray-200">{children}</tbody>;
}

export function ReportTableFoot({ children }: { children: ReactNode }) {
  return <tfoot>{children}</tfoot>;
}

export function ReportTableRow({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <tr className={className}>{children}</tr>;
}

type Align = 'left' | 'right' | 'center';

export function ReportTableHeaderCell({
  children,
  align = 'left',
  className = '',
}: {
  children: ReactNode;
  align?: Align;
  className?: string;
}) {
  const alignClass = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';
  return (
    <th
      className={`px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider ${alignClass} ${className}`}
    >
      {children}
    </th>
  );
}

export function ReportTableCell({
  children,
  align = 'left',
  numeric = false,
  muted = false,
  className = '',
}: {
  children: ReactNode;
  align?: Align;
  /** Tabular numerals for amounts/counts */
  numeric?: boolean;
  /** Muted body text (e.g. descriptions) */
  muted?: boolean;
  className?: string;
}) {
  const alignClass = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';
  const tone = muted ? 'text-gray-500' : 'text-gray-900';
  const weight = muted ? 'font-normal' : 'font-medium';
  return (
    <td
      className={`px-6 py-4 whitespace-nowrap text-sm ${tone} ${weight} ${alignClass} ${numeric ? 'tabular-nums' : ''} ${className}`}
    >
      {children}
    </td>
  );
}

type TotalsVariant = 'subtotal' | 'grand';

export function ReportTotalsRow({
  variant = 'subtotal',
  label,
  labelColSpan,
  children,
}: {
  variant?: TotalsVariant;
  label: string;
  labelColSpan: number;
  /** Trailing `<td>` cells (amount columns). */
  children: ReactNode;
}) {
  const rowClass =
    variant === 'grand'
      ? 'totals-row print-total-row bg-gray-50 font-semibold border-t-2 border-gray-300 print-avoid-break'
      : 'bg-gray-50 font-semibold border-t border-gray-200';
  return (
    <tr className={rowClass}>
      <td colSpan={labelColSpan} className="px-6 py-4 text-sm text-gray-900">
        {label}
      </td>
      {children}
    </tr>
  );
}

export function ReportEmptyState({
  colSpan,
  message = EMPTY_COPY.noDataForPeriod,
}: {
  colSpan: number;
  message?: string;
}) {
  return (
    <tr>
      <td colSpan={colSpan} className="px-6 py-4 text-center text-gray-500">
        {message}
      </td>
    </tr>
  );
}

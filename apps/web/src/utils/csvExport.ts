/**
 * CSV download helpers. Filenames follow Terrava conventions; value shaping follows
 * EXPORT_POLICY in config/presentation (spreadsheet-friendly numbers, ISO dates).
 */

import { EXPORT_POLICY } from '../config/presentation';

/**
 * Format a date string (YYYY-MM-DD) to YYYYMMDD for filenames
 */
function formatDateForFilename(date: string): string {
  return date.replace(/-/g, '');
}

/**
 * Generate Terrava-branded CSV filename
 * Format: Terrava_<ReportName>_<FromDate>-<ToDate>_<YYYYMMDD>.csv
 * Or: Terrava_<ReportName>_AsOf<Date>_<YYYYMMDD>.csv for single date reports
 */
function generateTerravaFilename(
  reportName: string,
  fromDate?: string,
  toDate?: string,
  asOfDate?: string,
): string {
  const today = new Date().toISOString().split('T')[0];
  const todayFormatted = formatDateForFilename(today);

  let filename = `Terrava_${reportName}`;

  if (asOfDate) {
    const asOfFormatted = formatDateForFilename(asOfDate);
    filename += `_AsOf${asOfFormatted}`;
  } else if (fromDate && toDate) {
    const fromFormatted = formatDateForFilename(fromDate);
    const toFormatted = formatDateForFilename(toDate);
    filename += `_${fromFormatted}-${toFormatted}`;
  }

  filename += `_${todayFormatted}.csv`;

  return filename;
}

export type ExportToCSVOptions = {
  reportName?: string;
  fromDate?: string;
  toDate?: string;
  asOfDate?: string;
  /**
   * Optional leading rows (e.g. key-value context). Each inner array is one row.
   * Not parsed as data columns — use for human context only.
   */
  metadataRows?: string[][];
};

/**
 * Helper function to export data to CSV
 * @param data - Array of objects to export
 * @param filename - Filename (or use Terrava format if reportName provided)
 * @param headers - Optional array of header names (object keys)
 * @param options - Terrava filename + optional metadata rows
 */
export function exportToCSV<T extends object>(
  data: T[],
  filename: string,
  headers?: string[],
  options?: ExportToCSVOptions,
): void {
  if (data.length === 0) {
    alert('No data to export');
    return;
  }

  const finalFilename = options?.reportName
    ? generateTerravaFilename(options.reportName, options.fromDate, options.toDate, options.asOfDate)
    : filename;

  const csvHeaders = headers || (Object.keys(data[0] as object) as string[]);
  if (csvHeaders.length === 0) {
    alert('No data to export');
    return;
  }

  const csvRows: string[] = [];

  if (EXPORT_POLICY.includeMetadataRows && options?.metadataRows?.length) {
    for (const row of options.metadataRows) {
      csvRows.push(row.map((cell) => escapeCSV(cell)).join(','));
    }
  }

  csvRows.push(csvHeaders.map((h) => escapeCSV(h)).join(','));

  data.forEach((row) => {
    const values = csvHeaders.map((header) => {
      const value = (row as Record<string, unknown>)[header];
      return escapeCSV(value != null ? String(value) : '');
    });
    csvRows.push(values.join(','));
  });

  const csvContent = csvRows.join('\n');
  const blob = new Blob([`\uFEFF${csvContent}`], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  const url = URL.createObjectURL(blob);

  link.setAttribute('href', url);
  link.setAttribute('download', finalFilename);
  link.style.visibility = 'hidden';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

function escapeCSV(value: string): string {
  if (value.includes(',') || value.includes('\n') || value.includes('"')) {
    return `"${value.replace(/"/g, '""')}"`;
  }
  return value;
}

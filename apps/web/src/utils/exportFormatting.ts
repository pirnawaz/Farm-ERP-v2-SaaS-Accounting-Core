/**
 * Spreadsheet-oriented value helpers (CSV/Excel export policy).
 * Use for machine-friendly columns — not for on-screen display (use formatMoney/formatDate there).
 */

import { EXPORT_POLICY } from '../config/presentation';

/** Fixed decimal string without grouping, e.g. "22050.00" — suitable for numeric cells. */
export function exportAmountForSpreadsheet(amount: number | string): string {
  const n = typeof amount === 'string' ? parseFloat(amount) : amount;
  if (typeof n !== 'number' || Number.isNaN(n)) {
    return '';
  }
  return n.toFixed(EXPORT_POLICY.amountDecimalPlaces);
}

/**
 * Normalise API date strings to YYYY-MM-DD for export columns.
 * Accepts ISO datetimes and plain Y-M-D; returns empty string if unparseable.
 */
export function exportDateIsoYmd(value: string | Date | null | undefined): string {
  if (value === null || value === undefined) {
    return '';
  }
  if (value instanceof Date) {
    if (Number.isNaN(value.getTime())) {
      return '';
    }
    return value.toISOString().slice(0, 10);
  }
  const s = String(value).trim();
  if (!s) {
    return '';
  }
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
    return s;
  }
  const d = new Date(s);
  if (Number.isNaN(d.getTime())) {
    return '';
  }
  return d.toISOString().slice(0, 10);
}

/** Empty cell for optional text fields in exports. */
export function exportNullableString(value: string | null | undefined): string {
  if (value === null || value === undefined) {
    return '';
  }
  return String(value);
}

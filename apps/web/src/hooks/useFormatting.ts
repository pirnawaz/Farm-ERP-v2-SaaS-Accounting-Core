import { useTenantSettings } from './useTenantSettings';
import {
  formatMoney as formatMoneyUtil,
  formatDate as formatDateUtil,
  formatDateTime as formatDateTimeUtil,
  formatDateRange as formatDateRangeUtil,
  formatNumber as formatNumberUtil,
  formatPercent as formatPercentUtil,
  formatNullableValue as formatNullableValueUtil,
  type FormatDateOptions,
  type FormatDateRangeOptions,
  type FormatDateTimeOptions,
  type FormatMoneyOptions,
  type FormatNumberOptions,
  type FormatPercentOptions,
} from '../utils/formatting';

/**
 * Formatting helpers bound to tenant locale, timezone, and currency.
 */
export function useFormatting() {
  const { settings } = useTenantSettings();
  const s = settings || undefined;

  return {
    formatMoney: (amount: number | string, options?: FormatMoneyOptions) =>
      formatMoneyUtil(amount, { ...options, settings: s }),

    formatDate: (
      date: string | Date | number | null | undefined,
      options?: FormatDateOptions,
    ) => formatDateUtil(date, { ...options, settings: s }),

    formatDateRange: (
      start: string | Date | number | null | undefined,
      end: string | Date | number | null | undefined,
      options?: FormatDateRangeOptions,
    ) => formatDateRangeUtil(start, end, { ...options, settings: s }),

    formatDateTime: (date: string | Date | number, options?: FormatDateTimeOptions) =>
      formatDateTimeUtil(date, { ...options, settings: s }),

    formatNumber: (value: number, options?: FormatNumberOptions) =>
      formatNumberUtil(value, { ...options, settings: s }),

    formatPercent: (value: number, options?: FormatPercentOptions) =>
      formatPercentUtil(value, { ...options, settings: s }),

    formatNullableValue: <T,>(
      value: T | null | undefined,
      format: (v: NonNullable<T>) => string,
    ) => formatNullableValueUtil(value, format),
  };
}

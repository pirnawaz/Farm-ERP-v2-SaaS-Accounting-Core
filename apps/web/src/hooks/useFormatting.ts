import { useTenantSettings } from './useTenantSettings';
import { formatMoney as formatMoneyUtil, formatDate as formatDateUtil, formatDateTime as formatDateTimeUtil } from '../utils/formatting';

/**
 * Hook to format money using tenant settings
 * Use this in components to get formatting functions that automatically use tenant settings
 */
export function useFormatting() {
  const { settings } = useTenantSettings();

  return {
    formatMoney: (amount: number | string, options?: { currencyCode?: string; locale?: string }) =>
      formatMoneyUtil(amount, { ...options, settings: settings || undefined }),
    formatDate: (date: string | Date | number, options?: { locale?: string; timezone?: string; format?: 'short' | 'medium' | 'long' | 'full' }) =>
      formatDateUtil(date, { ...options, settings: settings || undefined }),
    formatDateTime: (date: string | Date | number, options?: { locale?: string; timezone?: string }) =>
      formatDateTimeUtil(date, { ...options, settings: settings || undefined }),
  };
}

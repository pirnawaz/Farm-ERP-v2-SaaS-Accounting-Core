/**
 * Single import surface for display formatting utilities (pure; no React).
 */
export {
  DEFAULT_LOCALISATION,
  normalizeLocaleTag,
  formatDate,
  formatDateRange,
  formatDateTime,
  formatMoney,
  formatNumber,
  formatPercent,
  formatNullableValue,
  type LocalisationSettings,
  type DateFormatVariant,
  type FormatDateOptions,
  type FormatDateRangeOptions,
  type FormatMoneyOptions,
  type FormatNumberOptions,
  type FormatDateTimeOptions,
  type FormatPercentOptions,
} from '../utils/formatting';

export { DISPLAY_MISSING } from '../config/presentation';

/**
 * Unified display formatting (tenant locale, timezone, currency). Pure functions; backend stays ISO/numbers.
 * Prefer useFormatting() in components so tenant settings apply automatically.
 */

import { DISPLAY_MISSING } from '../config/presentation';

export type LocalisationSettings = {
  locale: string;
  timezone: string;
  currency_code: string;
};

export const DEFAULT_LOCALISATION: LocalisationSettings = {
  locale: 'en-PK',
  timezone: 'Asia/Karachi',
  currency_code: 'PKR',
};

/** Normalise BCP 47 tags (e.g. en-gb → en-GB) for reliable Intl behaviour. */
export function normalizeLocaleTag(locale: string): string {
  const trimmed = locale.trim();
  if (!trimmed) {
    return trimmed;
  }
  const parts = trimmed.split('-').filter(Boolean);
  if (parts.length === 0) {
    return trimmed;
  }
  const lang = parts[0].toLowerCase();
  const rest = parts.slice(1).map((seg) => (/^[A-Za-z]+$/.test(seg) ? seg.toUpperCase() : seg));
  return rest.length ? `${lang}-${rest.join('-')}` : lang;
}

function resolveSettings(
  options?: { locale?: string; timezone?: string; currencyCode?: string; settings?: Partial<LocalisationSettings> },
): LocalisationSettings {
  return {
    locale: normalizeLocaleTag(
      options?.locale ?? options?.settings?.locale ?? DEFAULT_LOCALISATION.locale,
    ),
    timezone: options?.timezone ?? options?.settings?.timezone ?? DEFAULT_LOCALISATION.timezone,
    currency_code:
      options?.currencyCode ?? options?.settings?.currency_code ?? DEFAULT_LOCALISATION.currency_code,
  };
}

export type DateFormatVariant = 'short' | 'medium' | 'long';

export type FormatDateOptions = {
  variant?: DateFormatVariant;
  /** @deprecated use variant */
  format?: 'short' | 'medium' | 'long' | 'full';
  locale?: string;
  timezone?: string;
  settings?: Partial<LocalisationSettings>;
};

function variantFromOptions(options?: FormatDateOptions): DateFormatVariant {
  if (options?.variant) {
    return options.variant;
  }
  const f = options?.format;
  if (f === 'short') return 'short';
  if (f === 'full' || f === 'long') return 'long';
  return 'medium';
}

function intlOptionsForVariant(
  variant: DateFormatVariant,
  timeZone: string,
): Intl.DateTimeFormatOptions {
  const base = { timeZone };
  switch (variant) {
    case 'short':
      return { ...base, day: '2-digit', month: '2-digit', year: 'numeric' };
    case 'long':
      return { ...base, weekday: 'long', day: '2-digit', month: 'short', year: 'numeric' };
    case 'medium':
    default:
      return { ...base, day: '2-digit', month: 'short', year: 'numeric' };
  }
}

const dateFormatters = new Map<string, Intl.DateTimeFormat>();

function getDateFormatter(locale: string, variant: DateFormatVariant, timeZone: string): Intl.DateTimeFormat {
  const key = `${locale}|${timeZone}|${variant}`;
  let f = dateFormatters.get(key);
  if (!f) {
    f = new Intl.DateTimeFormat(locale, intlOptionsForVariant(variant, timeZone));
    dateFormatters.set(key, f);
  }
  return f;
}

function parseToDate(date: string | Date | number): Date | null {
  if (date === null || date === undefined) {
    return null;
  }
  if (typeof date === 'string' && date.trim() === '') {
    return null;
  }
  const d = date instanceof Date ? date : new Date(date);
  return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * Format a calendar date for display (tenant locale + timezone).
 * - short → locale numeric date (e.g. 05/01/2025 or 01/05/2025 per locale)
 * - medium → 01 May 2025 (default)
 * - long → Thursday, 1 May 2025 (weekday + date; order varies by locale)
 */
export function formatDate(
  date: string | Date | number | null | undefined,
  options?: FormatDateOptions,
): string {
  if (date === null || date === undefined || (typeof date === 'string' && date.trim() === '')) {
    return DISPLAY_MISSING;
  }
  const dateObj = parseToDate(date);
  if (!dateObj) {
    return DISPLAY_MISSING;
  }

  const { locale, timezone } = resolveSettings(options);
  const variant = variantFromOptions(options);

  try {
    return getDateFormatter(locale, variant, timezone).format(dateObj);
  } catch {
    const day = String(dateObj.getDate()).padStart(2, '0');
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = monthNames[dateObj.getMonth()];
    const year = dateObj.getFullYear();
    return `${day} ${month} ${year}`;
  }
}

export type FormatDateRangeOptions = FormatDateOptions & {
  /** Between start and end; default en dash. */
  separator?: string;
};

/**
 * Format two dates as a range, e.g. "01 May 2025 – 30 Mar 2026".
 */
export function formatDateRange(
  start: string | Date | number | null | undefined,
  end: string | Date | number | null | undefined,
  options?: FormatDateRangeOptions,
): string {
  const sep = options?.separator ?? ' – ';
  const v = variantFromOptions(options);
  const a = formatDate(start, { ...options, variant: v });
  const b = formatDate(end, { ...options, variant: v });
  if (a === DISPLAY_MISSING && b === DISPLAY_MISSING) {
    return DISPLAY_MISSING;
  }
  if (a === DISPLAY_MISSING) {
    return b;
  }
  if (b === DISPLAY_MISSING) {
    return a;
  }
  return `${a}${sep}${b}`;
}

export type FormatMoneyOptions = {
  currencyCode?: string;
  locale?: string;
  settings?: Partial<LocalisationSettings>;
  /**
   * 'accounting' uses parentheses for negative amounts where the locale supports it (Terrava default for financial clarity).
   * 'standard' uses a leading minus.
   */
  currencySign?: 'standard' | 'accounting';
};

const moneyFormatters = new Map<string, Intl.NumberFormat>();

function getMoneyFormatter(
  locale: string,
  currencyCode: string,
  currencySign: 'standard' | 'accounting' = 'accounting',
): Intl.NumberFormat {
  const key = `${locale}|${currencyCode}|currency|${currencySign}`;
  let f = moneyFormatters.get(key);
  if (!f) {
    f = new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: currencyCode,
      currencySign,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
    moneyFormatters.set(key, f);
  }
  return f;
}

/**
 * Money with currency symbol, 2 decimal places, locale grouping.
 * Defaults to accounting-style negatives (parentheses) per Terrava Presentation Standards v1.
 */
export function formatMoney(amount: number | string, options?: FormatMoneyOptions): string {
  const numAmount = typeof amount === 'string' ? parseFloat(amount) : amount;
  if (isNaN(numAmount)) {
    return DISPLAY_MISSING;
  }
  const { locale, currency_code } = resolveSettings(options);
  const currencySign = options?.currencySign ?? 'accounting';
  try {
    return getMoneyFormatter(locale, currency_code, currencySign).format(numAmount);
  } catch {
    const abs = Math.abs(numAmount).toFixed(2);
    const sym = currency_code;
    if (numAmount < 0 && currencySign === 'accounting') {
      return `(${sym} ${abs})`;
    }
    const sign = numAmount < 0 ? '-' : '';
    return `${sign}${sym} ${abs}`;
  }
}

export type FormatNumberOptions = {
  locale?: string;
  settings?: Partial<LocalisationSettings>;
  minimumFractionDigits?: number;
  maximumFractionDigits?: number;
};

const numberFormatters = new Map<string, Intl.NumberFormat>();

function getNumberFormatter(locale: string, min: number, max: number): Intl.NumberFormat {
  const key = `${locale}|n|${min}|${max}`;
  let f = numberFormatters.get(key);
  if (!f) {
    f = new Intl.NumberFormat(locale, {
      minimumFractionDigits: min,
      maximumFractionDigits: max,
    });
    numberFormatters.set(key, f);
  }
  return f;
}

/**
 * Locale-aware integers/decimals for counts, quantities, metrics (not currency).
 */
export function formatNumber(value: number, options?: FormatNumberOptions): string {
  if (typeof value !== 'number' || isNaN(value)) {
    return DISPLAY_MISSING;
  }
  const locale = resolveSettings(options).locale;
  const min = options?.minimumFractionDigits ?? 0;
  const max = options?.maximumFractionDigits ?? min;
  try {
    return getNumberFormatter(locale, min, max).format(value);
  } catch {
    return String(value);
  }
}

export type FormatDateTimeOptions = {
  locale?: string;
  timezone?: string;
  settings?: Partial<LocalisationSettings>;
};

const dateTimeFormatters = new Map<string, Intl.DateTimeFormat>();

function getDateTimeFormatter(locale: string, timeZone: string): Intl.DateTimeFormat {
  const key = `${locale}|${timeZone}|dt`;
  let f = dateTimeFormatters.get(key);
  if (!f) {
    f = new Intl.DateTimeFormat(locale, {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone,
    });
    dateTimeFormatters.set(key, f);
  }
  return f;
}

export function formatDateTime(
  date: string | Date | number,
  options?: FormatDateTimeOptions,
): string {
  const dateObj = parseToDate(date);
  if (!dateObj) {
    return DISPLAY_MISSING;
  }
  const { locale, timezone } = resolveSettings(options);
  try {
    return getDateTimeFormatter(locale, timezone).format(dateObj);
  } catch {
    return dateObj.toLocaleString();
  }
}

export type FormatPercentOptions = {
  locale?: string;
  settings?: Partial<LocalisationSettings>;
  minimumFractionDigits?: number;
  maximumFractionDigits?: number;
};

const percentFormatters = new Map<string, Intl.NumberFormat>();

function getPercentFormatter(locale: string, min: number, max: number): Intl.NumberFormat {
  const key = `${locale}|pct|${min}|${max}`;
  let f = percentFormatters.get(key);
  if (!f) {
    f = new Intl.NumberFormat(locale, {
      style: 'percent',
      minimumFractionDigits: min,
      maximumFractionDigits: max,
    });
    percentFormatters.set(key, f);
  }
  return f;
}

/**
 * Percent for display. `value` is a ratio (e.g. 0.255 for 25.5%), matching Intl percent style.
 */
export function formatPercent(value: number, options?: FormatPercentOptions): string {
  if (typeof value !== 'number' || isNaN(value)) {
    return DISPLAY_MISSING;
  }
  const locale = resolveSettings(options).locale;
  const min = options?.minimumFractionDigits ?? 0;
  const max = options?.maximumFractionDigits ?? 2;
  try {
    return getPercentFormatter(locale, min, max).format(value);
  } catch {
    return `${(value * 100).toFixed(max)}%`;
  }
}

/**
 * Map missing/blank inputs to the standard em dash; otherwise format with the provided function.
 */
export function formatNullableValue<T>(
  value: T | null | undefined,
  format: (v: NonNullable<T>) => string,
): string {
  if (value === null || value === undefined) {
    return DISPLAY_MISSING;
  }
  if (typeof value === 'string' && value.trim() === '') {
    return DISPLAY_MISSING;
  }
  return format(value as NonNullable<T>);
}

/**
 * Format a monetary amount using provided settings
 * @param amount - The amount to format (number or string)
 * @param options - Currency code, locale, and optional settings
 * @returns Formatted currency string (e.g., "PKR 1,250.50" or "£1,250.50")
 */
export function formatMoney(
  amount: number | string,
  options?: { 
    currencyCode?: string; 
    locale?: string;
    settings?: { currency_code: string; locale: string };
  }
): string {
  const numAmount = typeof amount === 'string' ? parseFloat(amount) : amount;
  
  if (isNaN(numAmount)) {
    return '0.00';
  }

  // Use provided options, then settings, then defaults
  const currencyCode = options?.currencyCode || options?.settings?.currency_code || 'GBP';
  const locale = options?.locale || options?.settings?.locale || 'en-GB';

  try {
    const formatter = new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: currencyCode,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
    return formatter.format(numAmount);
  } catch (error) {
    // Fallback if Intl.NumberFormat fails
    return `${currencyCode} ${numAmount.toFixed(2)}`;
  }
}

/**
 * Format a date using provided settings
 * @param date - The date to format (string, Date, or timestamp)
 * @param options - Optional overrides for locale, timezone, format, and settings
 * @returns Formatted date string
 */
export function formatDate(
  date: string | Date | number,
  options?: { 
    locale?: string; 
    timezone?: string; 
    format?: 'short' | 'medium' | 'long' | 'full';
    settings?: { locale: string; timezone: string };
  }
): string {
  let dateObj: Date;
  
  if (typeof date === 'string') {
    dateObj = new Date(date);
  } else if (typeof date === 'number') {
    dateObj = new Date(date);
  } else {
    dateObj = date;
  }

  if (isNaN(dateObj.getTime())) {
    return 'Invalid Date';
  }

  // Use provided options, then settings, then defaults
  const locale = options?.locale || options?.settings?.locale || 'en-GB';
  const timezone = options?.timezone || options?.settings?.timezone || 'Europe/London';
  const format = options?.format || 'medium';

  const formatOptions: Intl.DateTimeFormatOptions = {
    timeZone: timezone,
  };

  switch (format) {
    case 'short':
      formatOptions.dateStyle = 'short';
      break;
    case 'medium':
      formatOptions.dateStyle = 'medium';
      break;
    case 'long':
      formatOptions.dateStyle = 'long';
      break;
    case 'full':
      formatOptions.dateStyle = 'full';
      break;
  }

  try {
    const formatter = new Intl.DateTimeFormat(locale, formatOptions);
    return formatter.format(dateObj);
  } catch (error) {
    // Fallback if Intl.DateTimeFormat fails
    return dateObj.toLocaleDateString();
  }
}

/**
 * Format a date and time using provided settings
 * @param date - The date to format
 * @param options - Optional overrides for locale, timezone, and settings
 * @returns Formatted date and time string
 */
export function formatDateTime(
  date: string | Date | number,
  options?: { 
    locale?: string; 
    timezone?: string;
    settings?: { locale: string; timezone: string };
  }
): string {
  let dateObj: Date;
  
  if (typeof date === 'string') {
    dateObj = new Date(date);
  } else if (typeof date === 'number') {
    dateObj = new Date(date);
  } else {
    dateObj = date;
  }

  if (isNaN(dateObj.getTime())) {
    return 'Invalid Date';
  }

  // Use provided options, then settings, then defaults
  const locale = options?.locale || options?.settings?.locale || 'en-GB';
  const timezone = options?.timezone || options?.settings?.timezone || 'Europe/London';

  try {
    const formatter = new Intl.DateTimeFormat(locale, {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: timezone,
    });
    return formatter.format(dateObj);
  } catch (error) {
    return dateObj.toLocaleString();
  }
}

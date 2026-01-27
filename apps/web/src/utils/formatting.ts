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
 * Format a date to "dd MMM yyyy" format (e.g., "22 Jan 2026")
 * @param date - The date to format (string, Date, number, null, or undefined)
 * @param options - Optional overrides for locale, timezone, format, and settings (format option is deprecated, always uses "dd MMM yyyy")
 * @returns Formatted date string or "—" for null/undefined/empty
 */
export function formatDate(
  date: string | Date | number | null | undefined,
  options?: { 
    locale?: string; 
    timezone?: string; 
    format?: 'short' | 'medium' | 'long' | 'full';
    settings?: { locale: string; timezone: string };
  }
): string {
  // Handle null, undefined, or empty string
  if (date === null || date === undefined || (typeof date === 'string' && date.trim() === '')) {
    return '—';
  }

  let dateObj: Date;
  
  if (typeof date === 'string') {
    dateObj = new Date(date);
  } else if (typeof date === 'number') {
    dateObj = new Date(date);
  } else {
    dateObj = date;
  }

  if (isNaN(dateObj.getTime())) {
    return '—';
  }

  // Use provided options, then settings, then defaults
  const locale = options?.locale || options?.settings?.locale || 'en-GB';
  const timezone = options?.timezone || options?.settings?.timezone || 'Europe/London';

  try {
    // Format as "dd MMM yyyy" (e.g., "22 Jan 2026")
    const formatter = new Intl.DateTimeFormat(locale, {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      timeZone: timezone,
    });
    return formatter.format(dateObj);
  } catch (error) {
    // Fallback if Intl.DateTimeFormat fails
    const day = String(dateObj.getDate()).padStart(2, '0');
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = monthNames[dateObj.getMonth()];
    const year = dateObj.getFullYear();
    return `${day} ${month} ${year}`;
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

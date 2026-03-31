import { describe, expect, it } from 'vitest';
import { DISPLAY_MISSING } from '../config/presentation';
import {
  DEFAULT_LOCALISATION,
  formatDate,
  formatDateRange,
  formatMoney,
  formatNullableValue,
  formatNumber,
  formatPercent,
  normalizeLocaleTag,
} from './formatting';

const pk: Partial<typeof DEFAULT_LOCALISATION> = {
  locale: 'en-PK',
  timezone: 'Asia/Karachi',
  currency_code: 'PKR',
};

const us: Partial<typeof DEFAULT_LOCALISATION> = {
  locale: 'en-US',
  timezone: 'America/New_York',
  currency_code: 'USD',
};

describe('normalizeLocaleTag', () => {
  it('normalises language-region casing', () => {
    expect(normalizeLocaleTag('en-gb')).toBe('en-GB');
    expect(normalizeLocaleTag('EN-us')).toBe('en-US');
  });
});

describe('formatDate', () => {
  it('returns em dash for empty input', () => {
    expect(formatDate('')).toBe('—');
    expect(formatDate(null)).toBe('—');
  });

  it('formats medium variant with tenant settings', () => {
    const s = formatDate('2025-05-01T00:00:00.000Z', { settings: pk, variant: 'medium' });
    expect(s).toMatch(/May/);
    expect(s).toMatch(/2025/);
  });

  it('supports short and long variants', () => {
    const short = formatDate('2025-05-01', { settings: pk, variant: 'short' });
    expect(short.length).toBeGreaterThan(0);
    const long = formatDate('2025-05-01', { settings: pk, variant: 'long' });
    expect(long.toLowerCase()).toMatch(/may/);
  });
});

describe('formatDateRange', () => {
  it('joins two dates with en dash', () => {
    const r = formatDateRange('2025-05-01', '2026-03-30', { settings: pk });
    expect(r).toContain('–');
    expect(r).not.toContain('T');
  });
});

describe('formatMoney', () => {
  it('uses currency and two decimals', () => {
    const m = formatMoney(22050, { settings: pk });
    expect(m).toMatch(/22/);
    expect(m).toMatch(/050|50/);
  });

  it('returns em dash for NaN', () => {
    expect(formatMoney(Number.NaN, { settings: pk })).toBe(DISPLAY_MISSING);
  });

  it('uses accounting-style negatives by default (en-US)', () => {
    const m = formatMoney(-1234.56, { settings: us, currencyCode: 'USD' });
    expect(m.startsWith('(') || m.includes('(')).toBe(true);
  });

  it('can use standard minus sign', () => {
    const m = formatMoney(-10, { settings: us, currencyCode: 'USD', currencySign: 'standard' });
    expect(m).toMatch(/^-/);
  });
});

describe('formatPercent', () => {
  it('formats ratio as percent', () => {
    expect(formatPercent(0.255, { settings: pk, maximumFractionDigits: 1 })).toMatch(/25/);
  });
});

describe('formatNullableValue', () => {
  it('uses em dash for null and blank string', () => {
    expect(formatNullableValue(null, (x) => String(x))).toBe(DISPLAY_MISSING);
    expect(formatNullableValue('  ', (x) => x)).toBe(DISPLAY_MISSING);
  });

  it('formats non-null with callback', () => {
    expect(formatNullableValue(3, (x) => String(x))).toBe('3');
  });
});

describe('formatNumber', () => {
  it('groups large integers per locale', () => {
    const n = formatNumber(1234567, { settings: pk });
    expect(n).toMatch(/1/);
    expect(n).toMatch(/234/);
  });
});

import { describe, expect, it } from 'vitest';
import { exportAmountForSpreadsheet, exportDateIsoYmd, exportNullableString } from './exportFormatting';

describe('exportAmountForSpreadsheet', () => {
  it('outputs two decimal places without grouping', () => {
    expect(exportAmountForSpreadsheet(22050)).toBe('22050.00');
    expect(exportAmountForSpreadsheet('1234.5')).toBe('1234.50');
  });

  it('returns empty for invalid', () => {
    expect(exportAmountForSpreadsheet(Number.NaN)).toBe('');
  });
});

describe('exportDateIsoYmd', () => {
  it('passes through Y-M-D', () => {
    expect(exportDateIsoYmd('2025-03-01')).toBe('2025-03-01');
  });

  it('normalises ISO datetime to date', () => {
    expect(exportDateIsoYmd('2025-03-01T12:00:00.000Z').startsWith('2025-03')).toBe(true);
  });
});

describe('exportNullableString', () => {
  it('returns empty for nullish', () => {
    expect(exportNullableString(null)).toBe('');
    expect(exportNullableString(undefined)).toBe('');
  });
});

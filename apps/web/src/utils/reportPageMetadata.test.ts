import { describe, expect, it } from 'vitest';
import {
  getPrintableReportMetaLeft,
  getReportMetadataBlockPeriodProps,
  terravaBaseExportMetadataRows,
  type ReportDateFormatters,
} from './reportPageMetadata';

const fmt: ReportDateFormatters = {
  formatDate: (d) => `D(${String(d)})`,
  formatDateRange: (from, to) => `${from}–${to}`,
};

describe('getPrintableReportMetaLeft', () => {
  it('returns range line for range mode', () => {
    expect(
      getPrintableReportMetaLeft(
        'range',
        { mode: 'range', from: '2026-01-01', to: '2026-03-30' },
        fmt,
      ),
    ).toContain('2026-01-01');
  });

  it('returns as-of line for asOf mode', () => {
    expect(
      getPrintableReportMetaLeft('asOf', { mode: 'asOf', asOf: '2026-03-30' }, fmt),
    ).toContain('D(2026-03-30)');
  });

  it('returns undefined for none', () => {
    expect(getPrintableReportMetaLeft('none', { mode: 'none' }, fmt)).toBeUndefined();
  });
});

describe('getReportMetadataBlockPeriodProps', () => {
  it('maps range to reportingPeriodRange', () => {
    expect(
      getReportMetadataBlockPeriodProps(
        'range',
        { mode: 'range', from: 'a', to: 'b' },
        fmt,
      ),
    ).toEqual({ reportingPeriodRange: 'a–b' });
  });

  it('maps asOf to asOfDate', () => {
    expect(getReportMetadataBlockPeriodProps('asOf', { mode: 'asOf', asOf: '2026-01-01' }, fmt)).toEqual({
      asOfDate: 'D(2026-01-01)',
    });
  });
});

describe('terravaBaseExportMetadataRows', () => {
  it('includes export, base_currency, locale, timezone, and range period', () => {
    const rows = terravaBaseExportMetadataRows({
      reportExportName: 'Test',
      baseCurrency: 'PKR',
      period: { mode: 'range', from: '2026-01-01', to: '2026-01-31' },
      locale: 'en-PK',
      timezone: 'Asia/Karachi',
    });
    expect(rows).toEqual([
      ['export', 'Test'],
      ['base_currency', 'PKR'],
      ['locale', 'en-PK'],
      ['timezone', 'Asia/Karachi'],
      ['reporting_period_start', '2026-01-01'],
      ['reporting_period_end', '2026-01-31'],
    ]);
  });

  it('includes as_of for asOf period', () => {
    const rows = terravaBaseExportMetadataRows({
      reportExportName: 'Balances',
      baseCurrency: 'USD',
      period: { mode: 'asOf', asOf: '2026-03-15' },
    });
    expect(rows).toContainEqual(['as_of', '2026-03-15']);
    expect(rows.find((r) => r[0] === 'locale')).toBeUndefined();
  });
});

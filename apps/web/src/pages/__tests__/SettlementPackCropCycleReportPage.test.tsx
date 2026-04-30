import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import SettlementPackCropCycleReportPage from '../reports/SettlementPackCropCycleReportPage';

vi.mock('../../api/settlementPackPhase1', async () => {
  const actual: any = await vi.importActual('../../api/settlementPackPhase1');
  return {
    ...actual,
    settlementPackPhase1Api: {
      getCropCycle: vi.fn(async () => ({
        data: {
          scope: { tenant_id: 't1', kind: 'crop_cycle', crop_cycle_id: 'cc1', project_ids: ['p1', 'p2'] },
          period: { from: '2026-01-01', to: '2026-01-31', posting_date_axis: 'posting_groups.posting_date', bucket: 'total' },
          currency_code: 'GBP',
          totals: {
            harvest_production: { qty: '50.000', value: '80.00' },
            ledger_revenue: { sales: '0.00', machinery_income: '0.00', in_kind_income: '0.00', total: '0.00' },
            costs: { inputs: '30.00', labour: '0.00', machinery: '0.00', credit_premium: '12.00', other: '0.00', total: '42.00' },
            advances: { advances: null, recoveries: null, net: null },
            net: { net_ledger_result: '-42.00', net_harvest_production_result: '38.00' },
          },
          register: { allocation_rows: { rows: [], page: 1, per_page: 200, total_rows: 0, capped: false } },
          exports: {
            csv: {
              summary_url: '/api/reports/settlement-pack/crop-cycle/export/summary.csv?a=1',
              allocation_register_url: '/api/reports/settlement-pack/crop-cycle/export/allocation-register.csv?a=1',
              ledger_audit_register_url: '/api/reports/settlement-pack/crop-cycle/export/ledger-audit-register.csv?a=1',
            },
            pdf: { url: '/api/reports/settlement-pack/crop-cycle/export/pack.pdf?a=1' },
          },
          _meta: {},
        },
      })),
      getProject: vi.fn(),
      downloadCsv: vi.fn(),
      downloadPdf: vi.fn(),
    },
  };
});

vi.mock('../../hooks/useCropCycles', () => ({
  useCropCycles: () => ({ data: [{ id: 'cc1', name: '2026' }] }),
}));

vi.mock('../../hooks/useFormatting', () => ({
  useFormatting: () => ({
    formatMoney: (n: number) => String(n),
    formatDate: (d: string) => d,
  }),
}));

describe('SettlementPackCropCycleReportPage', () => {
  it('renders and loads for crop cycle', async () => {
    const qc = new QueryClient();
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/app/reports/settlement-pack/crop-cycle?crop_cycle_id=cc1&from=2026-01-01&to=2026-01-31']}>
          <SettlementPackCropCycleReportPage />
        </MemoryRouter>
      </QueryClientProvider>
    );

    expect(await screen.findByText(/Settlement pack \(Phase 1\) — crop cycle/i)).toBeTruthy();
    expect(await screen.findByText(/Cost buckets/i)).toBeTruthy();
    expect(await screen.findByText('42.00')).toBeTruthy();
  });
});


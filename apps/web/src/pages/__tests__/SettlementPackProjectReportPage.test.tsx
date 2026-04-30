import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import SettlementPackProjectReportPage from '../reports/SettlementPackProjectReportPage';

vi.mock('../../api/settlementPackPhase1', async () => {
  const actual: any = await vi.importActual('../../api/settlementPackPhase1');
  return {
    ...actual,
    settlementPackPhase1Api: {
      getProject: vi.fn(async () => ({
        data: {
          scope: { tenant_id: 't1', kind: 'project', project_id: 'p1', crop_cycle_id: 'cc1' },
          period: { from: '2026-01-01', to: '2026-01-31', posting_date_axis: 'posting_groups.posting_date', bucket: 'total' },
          currency_code: 'GBP',
          totals: {
            harvest_production: { qty: '100.000', value: '250.00' },
            ledger_revenue: { sales: '0.00', machinery_income: '0.00', in_kind_income: '0.00', total: '0.00' },
            costs: { inputs: '60.00', labour: '40.00', machinery: '0.00', credit_premium: '12.00', other: '5.00', total: '117.00' },
            advances: { advances: null, recoveries: null, net: null },
            net: { net_ledger_result: '-117.00', net_harvest_production_result: '133.00' },
          },
          register: { allocation_rows: { rows: [], page: 1, per_page: 200, total_rows: 0, capped: false } },
          exports: {
            csv: {
              summary_url: '/api/reports/settlement-pack/project/export/summary.csv?a=1',
              allocation_register_url: '/api/reports/settlement-pack/project/export/allocation-register.csv?a=1',
              ledger_audit_register_url: '/api/reports/settlement-pack/project/export/ledger-audit-register.csv?a=1',
            },
            pdf: { url: '/api/reports/settlement-pack/project/export/pack.pdf?a=1' },
          },
          _meta: {},
        },
      })),
      getCropCycle: vi.fn(),
      downloadCsv: vi.fn(),
      downloadPdf: vi.fn(),
    },
  };
});

vi.mock('../../hooks/useProjects', () => ({
  useProjects: () => ({ data: [{ id: 'p1', name: 'Field A' }] }),
}));

vi.mock('../../hooks/useFormatting', () => ({
  useFormatting: () => ({
    formatMoney: (n: number) => String(n),
    formatDate: (d: string) => d,
  }),
}));

describe('SettlementPackProjectReportPage', () => {
  it('renders and loads after selecting project', async () => {
    const qc = new QueryClient();
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/app/reports/settlement-pack/project?project_id=p1&from=2026-01-01&to=2026-01-31']}>
          <SettlementPackProjectReportPage />
        </MemoryRouter>
      </QueryClientProvider>
    );

    expect(await screen.findByText(/Settlement pack \(Phase 1\) — project/i)).toBeTruthy();
    expect(await screen.findByText(/Cost buckets/i)).toBeTruthy();
    expect(await screen.findByText('117.00')).toBeTruthy();
  });
});


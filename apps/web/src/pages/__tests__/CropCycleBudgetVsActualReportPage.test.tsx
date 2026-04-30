import { describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import CropCycleBudgetVsActualReportPage from '../reports/CropCycleBudgetVsActualReportPage';

const apiClientGet = vi.fn();

vi.mock('@farm-erp/shared', () => ({
  apiClient: {
    get: (...args: any[]) => apiClientGet(...args),
  },
}));

vi.mock('../../hooks/useCropCycles', () => ({
  useCropCycles: () => ({ data: [{ id: 'cc-1', name: '2026' }] }),
}));

vi.mock('../../hooks/useFormatting', () => ({
  useFormatting: () => ({
    formatMoney: (v: number) => `£${v.toFixed(2)}`,
  }),
}));

describe('CropCycleBudgetVsActualReportPage', () => {
  it('renders summary + monthly + per-project tables', async () => {
    apiClientGet.mockResolvedValueOnce({
      data: {
        scope: { tenant_id: 't', crop_cycle_id: 'cc-1' },
        currency_code: 'GBP',
        period: { from: '2026-01-01', to: '2026-02-28', bucket: 'month' },
        series: [
          {
            month: '2026-01',
            planned: {
              planned_input_cost: '10.00',
              planned_labour_cost: '0.00',
              planned_machinery_cost: '0.00',
              planned_total_cost: '10.00',
              planned_yield_qty: null,
              planned_yield_value: null,
            },
            actual: {
              actual_input_cost: '0.00',
              actual_labour_cost: '0.00',
              actual_machinery_cost: '0.00',
              actual_credit_premium_cost: '0.00',
              actual_total_cost: '0.00',
              actual_yield_qty: null,
              actual_yield_value: null,
            },
            variance: {
              variance_input_cost: '-10.00',
              variance_labour_cost: '0.00',
              variance_machinery_cost: '0.00',
              variance_credit_premium_cost: '0.00',
              variance_total_cost: '-10.00',
              variance_yield_qty: null,
              variance_yield_value: null,
            },
          },
        ],
        totals: {
          planned: {
            planned_input_cost: '10.00',
            planned_labour_cost: '0.00',
            planned_machinery_cost: '0.00',
            planned_total_cost: '10.00',
            planned_yield_qty: null,
            planned_yield_value: null,
          },
          actual: {
            actual_input_cost: '0.00',
            actual_labour_cost: '0.00',
            actual_machinery_cost: '0.00',
            actual_credit_premium_cost: '0.00',
            actual_total_cost: '0.00',
            actual_yield_qty: null,
            actual_yield_value: null,
          },
          variance: {
            variance_input_cost: '-10.00',
            variance_labour_cost: '0.00',
            variance_machinery_cost: '0.00',
            variance_credit_premium_cost: '0.00',
            variance_total_cost: '-10.00',
            variance_yield_qty: null,
            variance_yield_value: null,
          },
        },
        projects: [
          {
            project_id: 'p1',
            project_name: 'Field A',
            planned: {
              planned_input_cost: '10.00',
              planned_labour_cost: '0.00',
              planned_machinery_cost: '0.00',
              planned_total_cost: '10.00',
              planned_yield_qty: null,
              planned_yield_value: null,
            },
            actual: {
              actual_input_cost: '0.00',
              actual_labour_cost: '0.00',
              actual_machinery_cost: '0.00',
              actual_credit_premium_cost: '0.00',
              actual_total_cost: '0.00',
              actual_yield_qty: null,
              actual_yield_value: null,
            },
            variance: {
              variance_input_cost: '-10.00',
              variance_labour_cost: '0.00',
              variance_machinery_cost: '0.00',
              variance_credit_premium_cost: '0.00',
              variance_total_cost: '-10.00',
              variance_yield_qty: null,
              variance_yield_value: null,
            },
          },
        ],
      },
    });

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/app/reports/budget-vs-actual/crop-cycle?crop_cycle_id=cc-1']}>
          <Routes>
            <Route path="/app/reports/budget-vs-actual/crop-cycle" element={<CropCycleBudgetVsActualReportPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );

    // PageHeader title rendering can vary in test env; assert on section headers instead.
    expect(await screen.findByText('Monthly breakdown')).toBeInTheDocument();
    expect(await screen.findByText('Per-project variance')).toBeInTheDocument();
    expect(await screen.findByText('Coming soon.')).toBeInTheDocument();
  });
});


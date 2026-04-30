import { describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import ProjectBudgetVsActualReportPage from '../reports/ProjectBudgetVsActualReportPage';

const apiClientGet = vi.fn();

vi.mock('@farm-erp/shared', () => ({
  apiClient: {
    get: (...args: any[]) => apiClientGet(...args),
  },
}));

vi.mock('../../hooks/useProjects', () => ({
  useProjects: () => ({
    data: [{ id: 'proj-1', name: 'Field A' }],
  }),
}));

vi.mock('../../hooks/useFormatting', () => ({
  useFormatting: () => ({
    formatMoney: (v: number) => `£${v.toFixed(2)}`,
  }),
}));

describe('ProjectBudgetVsActualReportPage', () => {
  it('loads and renders table + note + yield coming soon', async () => {
    apiClientGet.mockResolvedValueOnce({
      data: {
        scope: { tenant_id: 't', project_id: 'proj-1', crop_cycle_id: 'cc' },
        plan: { plan_id: 'p', plan_name: 'Plan', status: 'ACTIVE', updated_at: null },
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
      },
    });

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/app/reports/budget-vs-actual/project?project_id=proj-1&from=2026-01-01&to=2026-02-28']}>
          <Routes>
            <Route path="/app/reports/budget-vs-actual/project" element={<ProjectBudgetVsActualReportPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );

    expect(screen.getByText('Planned monthly values are evenly distributed until time-phased budgets are added.')).toBeInTheDocument();
    expect(await screen.findByText('Monthly breakdown')).toBeInTheDocument();
    expect(await screen.findByText('Coming soon.')).toBeInTheDocument();
  });
});


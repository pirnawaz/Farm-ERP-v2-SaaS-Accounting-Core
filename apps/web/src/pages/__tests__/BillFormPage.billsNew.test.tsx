import { describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { BillFormNewRoute } from '../accounting/BillFormRoute';

vi.mock('../../hooks/useParties', () => ({
  useParties: () => ({ data: [], isLoading: false }),
}));
vi.mock('../../hooks/useCostCenters', () => ({
  useCostCenters: () => ({ data: [], isLoading: false }),
}));
vi.mock('../../hooks/useProjects', () => ({
  useProjects: () => ({ data: [], isLoading: false }),
}));
vi.mock('../../hooks/useTenantSettings', () => ({
  useTenantSettings: () => ({ settings: { currency_code: 'GBP' } }),
}));

describe('BillFormPage route wiring', () => {
  it('renders on /app/accounting/bills/new (no :id param)', () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/app/accounting/bills/new']}>
          <Routes>
            <Route path="/app/accounting/bills/new" element={<BillFormNewRoute mode="overhead" />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(screen.getByText('New farm overhead bill')).toBeInTheDocument();
  });
});


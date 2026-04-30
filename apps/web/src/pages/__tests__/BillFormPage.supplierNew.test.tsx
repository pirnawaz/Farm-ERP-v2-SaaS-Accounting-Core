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

describe('BillFormPage (supplier mode)', () => {
  it('renders on /app/accounting/supplier-invoices/new', () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/app/accounting/supplier-invoices/new']}>
          <Routes>
            <Route path="/app/accounting/supplier-invoices/new" element={<BillFormNewRoute mode="supplier" />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(screen.getByText('New supplier bill / invoice')).toBeInTheDocument();
    expect(screen.getByText('Payment terms')).toBeInTheDocument();
  });
});


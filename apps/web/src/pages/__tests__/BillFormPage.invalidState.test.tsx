import { describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import BillFormPage from '../accounting/BillFormPage';

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

describe('BillFormPage invalid state', () => {
  it('shows fallback UI instead of blank screen', () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/app/accounting/bills/broken']}>
          <Routes>
            <Route path="/app/accounting/bills/broken" element={<BillFormPage mode="overhead" isNew={false} />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(screen.getByText('Bill form unavailable')).toBeInTheDocument();
  });
});


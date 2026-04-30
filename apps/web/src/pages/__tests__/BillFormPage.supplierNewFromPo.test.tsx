import { describe, expect, it, vi } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { BillFormNewRoute } from '../accounting/BillFormRoute';

const apiClientGet = vi.fn();

vi.mock('@farm-erp/shared', () => ({
  apiClient: {
    get: (...args: any[]) => apiClientGet(...args),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));

vi.mock('../../hooks/useParties', () => ({
  useParties: () => ({
    data: [{ id: 'party-1', name: 'Vendor' }],
    isLoading: false,
  }),
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

describe('BillFormPage (supplier mode, prefill from PO)', () => {
  it('prefills supplier + lines from purchase order remaining qty', async () => {
    apiClientGet.mockImplementation((url: string) => {
      if (url === '/api/purchase-orders/po-1/prepare-invoice') {
        return Promise.resolve({
          data: {
            purchase_order_id: 'po-1',
            po_no: 'PO-1',
            party_id: 'party-1',
            currency_code: 'GBP',
            lines: [
              {
                purchase_order_line_id: 'pol-1',
                line_no: 1,
                item_id: null,
                description: 'Item',
                qty_ordered: '10.000000',
                qty_received: '0.000000',
                qty_invoiced: '7.000000',
                remaining_qty: '3.000000',
                unit_price: '11.500000',
              },
            ],
          },
        });
      }
      throw new Error(`Unexpected GET ${url}`);
    });

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={['/app/accounting/supplier-invoices/new?po_id=po-1']}>
          <Routes>
            <Route path="/app/accounting/supplier-invoices/new" element={<BillFormNewRoute mode="supplier" />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText('New supplier bill / invoice')).toBeInTheDocument();

    await waitFor(() => {
      // Party prefilled
      const partySel = document.getElementById('party') as HTMLSelectElement | null;
      expect(partySel?.value).toBe('party-1');
      // Remaining qty line
      expect(screen.getByDisplayValue('3')).toBeInTheDocument();
      expect(screen.getByDisplayValue('11.5')).toBeInTheDocument();
    });
  });
});


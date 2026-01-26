import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import SaleDetailPage from '../SaleDetailPage';

// Mock hooks
vi.mock('../../hooks/useSales', () => ({
  useSale: vi.fn(),
  useDeleteSale: vi.fn(),
  usePostSale: vi.fn(),
}));

vi.mock('../../hooks/useRole', () => ({
  useRole: vi.fn(),
}));

import { useSale, usePostSale } from '../../hooks/useSales';
import { useRole } from '../../hooks/useRole';

describe('SaleDetailPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('disables POST button when crop cycle is closed', () => {
    (useSale as any).mockReturnValue({
      data: {
        id: '1',
        status: 'DRAFT',
        crop_cycle: {
          id: 'cycle-1',
          name: 'Test Cycle',
          status: 'CLOSED',
        },
      },
      isLoading: false,
    });

    (useRole as any).mockReturnValue({
      hasRole: () => true,
    });

    (usePostSale as any).mockReturnValue({
      mutateAsync: vi.fn(),
      isPending: false,
    });

    render(
      <BrowserRouter>
        <SaleDetailPage />
      </BrowserRouter>
    );

    // Check for warning message about closed cycle
    expect(screen.getByText(/Cannot post/i)).toBeInTheDocument();
    expect(screen.getByText(/Crop cycle is closed/i)).toBeInTheDocument();
  });
});

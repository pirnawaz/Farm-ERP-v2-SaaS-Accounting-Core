import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ProductionUnitsPage from '../ProductionUnitsPage';

const mockUseOrchardLivestock = vi.fn(() => ({ hasOrchardLivestockModule: false }));
vi.mock('../../hooks/useModules', () => ({
  useOrchardLivestockAddonsEnabled: () => mockUseOrchardLivestock(),
}));

vi.mock('../../hooks/useProductionUnits', () => ({
  useProductionUnits: vi.fn(() => ({ data: [], isLoading: false })),
  useCreateProductionUnit: vi.fn(() => ({ mutateAsync: vi.fn(), isPending: false })),
}));

describe('ProductionUnitsPage module gating', () => {
  beforeEach(() => {
    mockUseOrchardLivestock.mockReturnValue({ hasOrchardLivestockModule: false });
  });

  it('shows modules gate when orchard and livestock addons are disabled', () => {
    render(
      <MemoryRouter>
        <ProductionUnitsPage />
      </MemoryRouter>
    );

    expect(screen.getByText(/Open Modules/i)).toBeInTheDocument();
    expect(screen.queryByTestId('new-production-unit')).not.toBeInTheDocument();
  });

  it('shows long-cycle copy and create control when an addon is enabled', () => {
    mockUseOrchardLivestock.mockReturnValue({ hasOrchardLivestockModule: true });

    render(
      <MemoryRouter>
        <ProductionUnitsPage />
      </MemoryRouter>
    );

    expect(screen.getByText(/optional/i)).toBeInTheDocument();
    expect(screen.getByTestId('new-production-unit')).toBeInTheDocument();
  });
});

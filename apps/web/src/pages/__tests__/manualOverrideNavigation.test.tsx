import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

const navigateMock = vi.fn();

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<any>('react-router-dom');
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

import ActivitiesPage from '../cropOps/ActivitiesPage';

vi.mock('../../hooks/useCropOps', () => ({
  useActivities: vi.fn(() => ({ data: [], isLoading: false })),
  useActivityTypes: vi.fn(() => ({ data: [] })),
}));

vi.mock('../../hooks/useCropCycles', () => ({
  useCropCycles: vi.fn(() => ({ data: [] })),
}));

vi.mock('../../hooks/useProjects', () => ({
  useProjects: vi.fn(() => ({ data: [] })),
}));

vi.mock('../../hooks/useLandParcels', () => ({
  useLandParcels: vi.fn(() => ({ data: [] })),
}));

vi.mock('../../hooks/useLabour', () => ({
  useWorkers: vi.fn(() => ({ data: [] })),
}));

vi.mock('../../hooks/useInventory', () => ({
  useInventoryStores: vi.fn(() => ({ data: [] })),
  useInventoryItems: vi.fn(() => ({ data: [] })),
  useStockOnHand: vi.fn(() => ({ data: [] })),
}));

vi.mock('../../hooks/useFormatting', () => ({
  useFormatting: vi.fn(() => ({ formatMoney: (n: number) => String(n), formatDate: (d: string) => d })),
}));

vi.mock('../../hooks/useRole', () => ({
  useRole: vi.fn(() => ({ hasRole: () => true })),
}));

describe('Manual override navigation wiring', () => {
  beforeEach(() => {
    navigateMock.mockReset();
  });

  it('does not expose legacy manual create CTA', () => {
    render(
      <MemoryRouter>
        <ActivitiesPage />
      </MemoryRouter>
    );

    expect(screen.queryByRole('button', { name: /New manual field work log/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /Continue to manual create/i })).toBeNull();
    expect(navigateMock).not.toHaveBeenCalled();
  });
});


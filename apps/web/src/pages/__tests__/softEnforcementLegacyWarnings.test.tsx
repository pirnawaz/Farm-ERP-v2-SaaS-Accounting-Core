import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';

import ActivitiesPage from '../cropOps/ActivitiesPage';
import MachineryWorkLogsPage from '../machinery/WorkLogsPage';
import LabourWorkLogsPage from '../labour/WorkLogsPage';
import InvIssuesPage from '../inventory/InvIssuesPage';

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

vi.mock('../../hooks/useMachinery', () => ({
  useWorkLogsQuery: vi.fn(() => ({ data: [], isLoading: false })),
  usePostWorkLog: vi.fn(() => ({ mutateAsync: vi.fn(), isPending: false })),
  useReverseWorkLog: vi.fn(() => ({ mutateAsync: vi.fn(), isPending: false })),
  useMachinesQuery: vi.fn(() => ({ data: [] })),
}));

vi.mock('../../hooks/useLabour', () => ({
  useWorkLogs: vi.fn(() => ({ data: [], isLoading: false })),
  useWorkers: vi.fn(() => ({ data: [] })),
}));

vi.mock('../../hooks/useInventory', () => ({
  useIssues: vi.fn(() => ({ data: [], isLoading: false })),
  useInventoryStores: vi.fn(() => ({ data: [] })),
}));

vi.mock('../../hooks/useRole', () => ({
  useRole: vi.fn(() => ({ hasRole: () => true })),
}));

vi.mock('../../hooks/useFormatting', () => ({
  useFormatting: () => ({
    formatMoney: (v: unknown) => `£${String(v ?? '')}`,
    formatDate: (v: unknown) => String(v ?? ''),
    formatDateTime: (v: unknown) => String(v ?? ''),
    formatNumber: (v: unknown) => String(v ?? ''),
  }),
}));

describe('Soft enforcement advisory warnings', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders advisory warning on Field Work Logs page', () => {
    render(
      <BrowserRouter>
        <ActivitiesPage />
      </BrowserRouter>,
    );
    expect(screen.getByText(/Advisory: avoid duplicate crop-work records/i)).toBeInTheDocument();
    expect(screen.getAllByText(/Use Field Jobs/i).length).toBeGreaterThanOrEqual(1);
  });

  it('renders advisory warning on Machine Usage page', () => {
    render(
      <BrowserRouter>
        <MachineryWorkLogsPage />
      </BrowserRouter>,
    );
    expect(screen.getByText(/Advisory: avoid duplicate crop-work records/i)).toBeInTheDocument();
    expect(screen.getAllByText(/Use Field Jobs/i).length).toBeGreaterThanOrEqual(1);
  });

  it('renders advisory warning on Labour Work Logs page', () => {
    render(
      <BrowserRouter>
        <LabourWorkLogsPage />
      </BrowserRouter>,
    );
    expect(screen.getByText(/Advisory: avoid duplicate crop-work records/i)).toBeInTheDocument();
    expect(screen.getAllByText(/Use Field Jobs/i).length).toBeGreaterThanOrEqual(1);
  });

  it('renders advisory warning on Stock Used page', () => {
    render(
      <BrowserRouter>
        <InvIssuesPage />
      </BrowserRouter>,
    );
    expect(screen.getByText(/Advanced\/manual inventory workflow/i)).toBeInTheDocument();
    expect(screen.getAllByText('Field Jobs').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(/duplicate operational and accounting records/i)).toBeInTheDocument();
  });
});


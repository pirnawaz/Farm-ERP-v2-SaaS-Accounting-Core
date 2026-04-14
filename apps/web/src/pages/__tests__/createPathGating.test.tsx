import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

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

describe('Create-path gating steers to Field Jobs', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('ActivitiesPage: Field Job is primary create CTA and manual create requires acknowledgement', async () => {
    const user = userEvent.setup();
    render(
      <MemoryRouter initialEntries={['/app/crop-ops/activities']}>
        <ActivitiesPage />
      </MemoryRouter>,
    );

    expect(screen.getByRole('button', { name: /New field job/i })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /New manual field work log/i }));
    expect(screen.getByText(/Manual \/ legacy create path/i)).toBeInTheDocument();
    const continueBtn = screen.getByRole('button', { name: /Continue to manual create/i });
    expect(continueBtn).toBeDisabled();
    await user.click(screen.getByRole('checkbox'));
    expect(continueBtn).not.toBeDisabled();
  });

  it('Machine Usage: Field Job is primary create CTA and manual create requires acknowledgement', async () => {
    const user = userEvent.setup();
    render(
      <MemoryRouter initialEntries={['/app/machinery/work-logs']}>
        <MachineryWorkLogsPage />
      </MemoryRouter>,
    );

    expect(screen.getByRole('button', { name: /New field job/i })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /New manual usage entry/i }));
    const continueBtn = screen.getByRole('button', { name: /Continue to manual create/i });
    expect(continueBtn).toBeDisabled();
    await user.click(screen.getByRole('checkbox'));
    expect(continueBtn).not.toBeDisabled();
  });

  it('Labour Work Logs: Field Job is primary create CTA and manual create requires acknowledgement', async () => {
    const user = userEvent.setup();
    render(
      <MemoryRouter initialEntries={['/app/labour/work-logs']}>
        <LabourWorkLogsPage />
      </MemoryRouter>,
    );

    expect(screen.getByRole('button', { name: /New field job/i })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /New manual labour log/i }));
    const continueBtn = screen.getByRole('button', { name: /Continue to manual create/i });
    expect(continueBtn).toBeDisabled();
    await user.click(screen.getByRole('checkbox'));
    expect(continueBtn).not.toBeDisabled();
  });

  it('Stock Used: Field Job is primary create CTA and manual create requires acknowledgement', async () => {
    const user = userEvent.setup();
    render(
      <MemoryRouter initialEntries={['/app/inventory/issues']}>
        <InvIssuesPage />
      </MemoryRouter>,
    );

    expect(screen.getByRole('button', { name: /New field job/i })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Record manual stock used/i }));
    const continueBtn = screen.getByRole('button', { name: /Continue to manual create/i });
    expect(continueBtn).toBeDisabled();
    await user.click(screen.getByRole('checkbox'));
    expect(continueBtn).not.toBeDisabled();
  });
});


import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { FieldJobCostSummary } from './FieldJobCostSummary';
import type { FieldJob, FieldJobDraftCostPreview } from '../../types';

let mockPreview: FieldJobDraftCostPreview;

vi.mock('../../hooks/useFormatting', () => ({
  useFormatting: () => ({
    formatMoney: (v: number | string) => `£${String(v)}`,
  }),
}));

vi.mock('../../hooks/useFieldJobs', () => ({
  useFieldJobDraftCostPreview: () => ({
    data: mockPreview,
    isLoading: false,
    isError: false,
  }),
}));

function makeDraftJob(): FieldJob {
  return {
    id: 'fj_1',
    tenant_id: 't_1',
    status: 'DRAFT',
    job_date: '2024-06-15',
    project_id: 'p_1',
    crop_cycle_id: 'cc_1',
    created_at: '2024-06-15T00:00:00Z',
    updated_at: '2024-06-15T00:00:00Z',
    inputs: [],
    labour: [],
    machines: [],
  };
}

describe('FieldJobCostSummary', () => {
  it('renders fully known totals without valued-on-posting messaging', () => {
    mockPreview = {
      field_job_id: 'fj_1',
      status: 'DRAFT',
      as_of_date: '2024-06-15',
      inputs: { lines: [{} as never], subtotal_estimate: '100.00', known_subtotal_estimate: '100.00', unknown_lines_count: 0, all_known: true },
      labour: { lines: [{} as never], subtotal_estimate: '30.00', known_subtotal_estimate: '30.00', unknown_lines_count: 0, all_known: true },
      machinery: { lines: [{} as never], subtotal_estimate: '200.00', known_subtotal_estimate: '200.00', unknown_lines_count: 0, all_known: true },
      summary: { grand_total_estimate: '330.00', known_total_estimate: '330.00', unknown_lines_count: 0, all_known: true },
      warnings: [],
    };

    render(<FieldJobCostSummary job={makeDraftJob()} />);
    expect(screen.getByText('Estimated cost summary')).toBeInTheDocument();
    expect(screen.queryByText(/Some costs will be valued on posting/i)).not.toBeInTheDocument();
    expect(screen.getByText('Total job cost')).toBeInTheDocument();
    expect(screen.getAllByText('£330.00').length).toBeGreaterThanOrEqual(1);
  });

  it('renders partial section with known subtotal amount', () => {
    mockPreview = {
      field_job_id: 'fj_1',
      status: 'DRAFT',
      as_of_date: '2024-06-15',
      inputs: { lines: [{} as never], subtotal_estimate: null, known_subtotal_estimate: '25.00', unknown_lines_count: 1, all_known: false },
      labour: { lines: [], subtotal_estimate: null, known_subtotal_estimate: '0.00', unknown_lines_count: 0, all_known: true },
      machinery: { lines: [], subtotal_estimate: null, known_subtotal_estimate: '0.00', unknown_lines_count: 0, all_known: true },
      summary: { grand_total_estimate: null, known_total_estimate: '25.00', unknown_lines_count: 1, all_known: false },
      warnings: [],
    };

    render(<FieldJobCostSummary job={makeDraftJob()} />);

    expect(screen.getByText('Estimated cost summary')).toBeInTheDocument();
    expect(screen.getByText('Known inputs subtotal')).toBeInTheDocument();
    expect(screen.getAllByText('£25.00').length).toBeGreaterThanOrEqual(1);
  });

  it('renders Valued on posting when section is partial with zero known subtotal and unknown lines', () => {
    mockPreview = {
      field_job_id: 'fj_1',
      status: 'DRAFT',
      as_of_date: '2024-06-15',
      inputs: { lines: [], subtotal_estimate: null, known_subtotal_estimate: '0.00', unknown_lines_count: 0, all_known: true },
      labour: { lines: [], subtotal_estimate: null, known_subtotal_estimate: '0.00', unknown_lines_count: 0, all_known: true },
      machinery: { lines: [{} as never], subtotal_estimate: null, known_subtotal_estimate: '0.00', unknown_lines_count: 2, all_known: false },
      summary: { grand_total_estimate: null, known_total_estimate: '0.00', unknown_lines_count: 2, all_known: false },
      warnings: [],
    };

    render(<FieldJobCostSummary job={makeDraftJob()} />);

    expect(screen.getByText('Estimated cost summary')).toBeInTheDocument();
    expect(screen.getByText(/Some costs will be valued on posting/i)).toBeInTheDocument();

    const machRow = screen.getByText('machinery subtotal').closest('div');
    expect(machRow).not.toBeNull();
    expect(within(machRow as HTMLElement).getByText('Valued on posting')).toBeInTheDocument();
  });
});


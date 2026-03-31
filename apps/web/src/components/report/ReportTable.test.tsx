import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ReportTable, ReportTableBody, ReportTableHead, ReportTableRow, ReportEmptyState } from './ReportTable';
import { EMPTY_COPY } from '../../config/presentation';

describe('ReportEmptyState', () => {
  it('renders default empty copy', () => {
    render(
      <ReportTable>
        <ReportTableHead>
          <ReportTableRow>
            <th>A</th>
          </ReportTableRow>
        </ReportTableHead>
        <ReportTableBody>
          <ReportEmptyState colSpan={3} />
        </ReportTableBody>
      </ReportTable>,
    );
    expect(screen.getByText(EMPTY_COPY.noDataForPeriod)).toBeInTheDocument();
  });

  it('allows custom message', () => {
    render(
      <ReportTable>
        <ReportTableHead>
          <ReportTableRow>
            <th>A</th>
          </ReportTableRow>
        </ReportTableHead>
        <ReportTableBody>
          <ReportEmptyState colSpan={1} message={EMPTY_COPY.noTransactions} />
        </ReportTableBody>
      </ReportTable>,
    );
    expect(screen.getByText(EMPTY_COPY.noTransactions)).toBeInTheDocument();
  });
});

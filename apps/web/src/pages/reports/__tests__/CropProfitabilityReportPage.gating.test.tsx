import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import CropProfitabilityReportPage from '../CropProfitabilityReportPage';

vi.mock('../../../components/report', async (importOriginal) => {
  const mod = await importOriginal<typeof import('../../../components/report')>();
  return {
    ...mod,
    ReportMetadataBlock: () => null,
  };
});

vi.mock('../../../hooks/useReports', () => ({
  useCropProfitability: vi.fn(() => ({ data: { rows: [], totals: null }, isLoading: false, isFetching: false })),
}));
vi.mock('../../../hooks/useFormatting', () => ({
  useFormatting: () => ({
    formatMoney: (x: string | number) => String(x),
    formatDateRange: () => '',
  }),
}));

vi.mock('../../../hooks/useModules', () => ({
  useOrchardLivestockAddonsEnabled: vi.fn(() => ({ hasOrchardLivestockModule: false })),
}));

describe('CropProfitabilityReportPage module gating', () => {
  it('hides Orchard/Livestock drill-down copy when addons are disabled', () => {
    render(
      <MemoryRouter>
        <CropProfitabilityReportPage />
      </MemoryRouter>
    );

    expect(screen.queryByText(/Orchard & Livestock performance/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/orchard\/livestock unit/i)).not.toBeInTheDocument();
  });
});


import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { DataTable, type Column } from '../components/DataTable';
import { useFormatting } from '../hooks/useFormatting';
import type { APAgeingReport } from '../types';
import { ReportMetadataBlock } from '../components/report/ReportMetadataBlock';
import { ReportEmptyStateCard, ReportErrorState, ReportLoadingState } from '../components/report';
import { PageContainer } from '../components/PageContainer';
import { FilterBar, FilterField, FilterGrid } from '../components/FilterBar';

export default function APAgeingPage() {
  const [asOfDate, setAsOfDate] = useState<string>(new Date().toISOString().split('T')[0]);
  const { formatMoney, formatDate } = useFormatting();

  const { data: report, isLoading, error } = useQuery<APAgeingReport>({
    queryKey: ['ap-ageing', asOfDate],
    queryFn: () => {
      const params = new URLSearchParams();
      params.append('as_of', asOfDate);
      return apiClient.get<APAgeingReport>(`/api/reports/ap-ageing?${params.toString()}`);
    },
  });

  const columns: Column<APAgeingReport['rows'][0]>[] = [
    {
      header: 'Supplier',
      accessor: (row) => (
        <Link
          to={`/app/parties/${row.supplier_party_id}`}
          className="text-[#1F6F5C] hover:underline font-medium"
        >
          {row.supplier_name}
        </Link>
      ),
    },
    {
      header: 'Total outstanding',
      accessor: (row) => (
        <span className="font-semibold text-right block">
          <span className="tabular-nums">{formatMoney(row.total_outstanding)}</span>
        </span>
      ),
    },
    {
      header: 'Unlinked credits',
      accessor: (row) => (
        <span className="tabular-nums text-right block text-gray-700">
          {row.posted_unlinked_credits != null ? formatMoney(row.posted_unlinked_credits) : '—'}
        </span>
      ),
    },
    {
      header: 'Net (after unlinked)',
      accessor: (row) => (
        <span className="tabular-nums text-right block font-medium">
          {row.net_after_unlinked_credits != null ? formatMoney(row.net_after_unlinked_credits) : '—'}
        </span>
      ),
    },
    { header: '0–30 days', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.bucket_0_30)}</span> },
    { header: '31–60 days', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.bucket_31_60)}</span> },
    { header: '61–90 days', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.bucket_61_90)}</span> },
    { header: '90+ days', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.bucket_90_plus)}</span> },
  ];

  return (
    <PageContainer className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">AP ageing</h1>
        <p className="text-sm text-gray-600 mt-1">
          Open supplier GRN bills and posted supplier invoices, bucketed by due date. Unlinked posted credits reduce net
          exposure but are not re-bucketed here — see notes below the table when present.
        </p>
      </div>

      <div className="no-print">
        <div className="bg-white rounded-lg shadow p-4">
          <FilterBar>
            <FilterGrid className="lg:grid-cols-2 xl:grid-cols-2">
              <FilterField label="As of">
                <input type="date" value={asOfDate} onChange={(e) => setAsOfDate(e.target.value)} />
              </FilterField>
            </FilterGrid>
          </FilterBar>
        </div>
      </div>

      <div className="no-print">
        <ReportMetadataBlock asOfDate={formatDate(asOfDate)} />
      </div>

      {isLoading && <ReportLoadingState label="Loading AP ageing…" className="no-print" />}
      {error && <ReportErrorState error={error} className="no-print" />}

      {!isLoading && !error && report && (
        <div className="bg-white rounded-lg shadow">
          <div className="p-6">
            <h2 className="text-lg font-medium text-gray-900 mb-4">AP ageing as of {formatDate(report.as_of)}</h2>

            {report.notes && report.notes.length > 0 && (
              <ul className="text-sm text-gray-600 list-disc pl-5 mb-4 space-y-1">
                {report.notes.map((n) => (
                  <li key={n}>{n}</li>
                ))}
              </ul>
            )}

            {report.reconciliation && (
              <p className="text-sm text-gray-600 mb-4">
                Subledger open total (GRN + supplier invoices):{' '}
                <span className="font-semibold tabular-nums text-gray-900">
                  {formatMoney(report.reconciliation.subledger_open_total)}
                </span>
                {' — '}
                should match report total outstanding below.
              </p>
            )}

            {report.rows && report.rows.length > 0 ? (
              <>
                <DataTable
                  data={report.rows.map((r, i) => ({ ...r, id: r.supplier_party_id || String(i) }))}
                  columns={columns}
                />

                <div className="mt-6 pt-6 border-t border-gray-200">
                  <h3 className="text-md font-medium text-gray-900 mb-4">Totals</h3>
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4">
                    <div>
                      <div className="text-sm text-gray-500">Total outstanding</div>
                      <div className="text-lg font-semibold">
                        <span className="tabular-nums">{formatMoney(report.totals.total_outstanding)}</span>
                      </div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">0–30 days</div>
                      <div className="text-lg font-semibold">
                        <span className="tabular-nums">{formatMoney(report.totals.bucket_0_30)}</span>
                      </div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">31–60 days</div>
                      <div className="text-lg font-semibold">
                        <span className="tabular-nums">{formatMoney(report.totals.bucket_31_60)}</span>
                      </div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">61–90 days</div>
                      <div className="text-lg font-semibold">
                        <span className="tabular-nums">{formatMoney(report.totals.bucket_61_90)}</span>
                      </div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">90+ days</div>
                      <div className="text-lg font-semibold text-red-600">
                        <span className="tabular-nums">{formatMoney(report.totals.bucket_90_plus)}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </>
            ) : (
              <ReportEmptyStateCard message="No open supplier payables for this date." />
            )}
          </div>
        </div>
      )}
    </PageContainer>
  );
}

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { DataTable, type Column } from '../components/DataTable';
import { useFormatting } from '../hooks/useFormatting';
import type { ARAgeingReport } from '../types';
import { term } from '../config/terminology';
import { ReportMetadataBlock } from '../components/report/ReportMetadataBlock';
import { ReportEmptyStateCard, ReportErrorState, ReportLoadingState } from '../components/report';
import { PageContainer } from '../components/PageContainer';
import { FilterBar, FilterField, FilterGrid } from '../components/FilterBar';

export default function ARAgeingPage() {
  const [asOfDate, setAsOfDate] = useState<string>(new Date().toISOString().split('T')[0]);
  const { formatMoney, formatDate } = useFormatting();

  const { data: report, isLoading, error } = useQuery<ARAgeingReport>({
    queryKey: ['ar-ageing', asOfDate],
    queryFn: () => {
      const params = new URLSearchParams();
      params.append('as_of', asOfDate);
      return apiClient.get<ARAgeingReport>(`/api/reports/ar-ageing?${params.toString()}`);
    },
  });

  const columns: Column<ARAgeingReport['rows'][0]>[] = [
    { header: 'Buyer', accessor: 'buyer_name' },
    { 
      header: 'Total Outstanding', 
      accessor: (row) => <span className="font-semibold text-right block"><span className="tabular-nums">{formatMoney(row.total_outstanding)}</span></span>
    },
    { header: '0-30 Days', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.bucket_0_30)}</span> },
    { header: '31-60 Days', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.bucket_31_60)}</span> },
    { header: '61-90 Days', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.bucket_61_90)}</span> },
    { header: '90+ Days', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.bucket_90_plus)}</span> },
  ];

  return (
    <PageContainer className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">{term('arAgeing')} Report</h1>
        <p className="text-sm text-gray-600 mt-1">
          Shows outstanding receivables grouped by ageing buckets
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

      {isLoading && <ReportLoadingState label={`Loading ${term('arAgeing').toLowerCase()}...`} className="no-print" />}
      {error && <ReportErrorState error={error} className="no-print" />}

      {!isLoading && !error && report && (
        <div className="bg-white rounded-lg shadow">
          <div className="p-6">
            <h2 className="text-lg font-medium text-gray-900 mb-4">
              {term('arAgeing')} as of {formatDate(report.as_of)}
            </h2>
            
            {report.rows && report.rows.length > 0 ? (
              <>
                <DataTable data={report.rows.map((r, i) => ({ ...r, id: r.buyer_party_id || String(i) }))} columns={columns} />
                
                <div className="mt-6 pt-6 border-t border-gray-200">
                  <h3 className="text-md font-medium text-gray-900 mb-4">Totals</h3>
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4">
                    <div>
                      <div className="text-sm text-gray-500">Total Outstanding</div>
                      <div className="text-lg font-semibold"><span className="tabular-nums">{formatMoney(report.totals.total_outstanding)}</span></div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">0-30 Days</div>
                      <div className="text-lg font-semibold"><span className="tabular-nums">{formatMoney(report.totals.bucket_0_30)}</span></div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">31-60 Days</div>
                      <div className="text-lg font-semibold"><span className="tabular-nums">{formatMoney(report.totals.bucket_31_60)}</span></div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">61-90 Days</div>
                      <div className="text-lg font-semibold"><span className="tabular-nums">{formatMoney(report.totals.bucket_61_90)}</span></div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">90+ Days</div>
                      <div className="text-lg font-semibold text-red-600"><span className="tabular-nums">{formatMoney(report.totals.bucket_90_plus)}</span></div>
                    </div>
                  </div>
                </div>
              </>
            ) : (
              <ReportEmptyStateCard message={`No outstanding receivables found for this date.`} className="shadow-none p-0 bg-transparent" />
            )}
          </div>
        </div>
      )}
    </PageContainer>
  );
}

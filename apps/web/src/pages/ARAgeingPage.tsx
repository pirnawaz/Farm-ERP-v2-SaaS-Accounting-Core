import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { DataTable, type Column } from '../components/DataTable';
import { useFormatting } from '../hooks/useFormatting';
import type { ARAgeingReport } from '../types';

export default function ARAgeingPage() {
  const [asOfDate, setAsOfDate] = useState<string>(new Date().toISOString().split('T')[0]);
  const { formatMoney } = useFormatting();

  const { data: report, isLoading } = useQuery<ARAgeingReport>({
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
      accessor: (row) => <span className="font-semibold">{formatMoney(row.total_outstanding)}</span>
    },
    { header: '0-30 Days', accessor: (row) => formatMoney(row.bucket_0_30) },
    { header: '31-60 Days', accessor: (row) => formatMoney(row.bucket_31_60) },
    { header: '61-90 Days', accessor: (row) => formatMoney(row.bucket_61_90) },
    { header: '90+ Days', accessor: (row) => formatMoney(row.bucket_90_plus) },
  ];

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">AR Ageing Report</h1>
        <p className="text-sm text-gray-600 mt-1">
          Shows outstanding receivables grouped by ageing buckets
        </p>
      </div>

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <div className="flex items-center space-x-4 mb-4">
          <label className="text-sm font-medium text-gray-700">As Of Date:</label>
          <input
            type="date"
            value={asOfDate}
            onChange={(e) => setAsOfDate(e.target.value)}
            className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
      </div>

      {report && (
        <div className="bg-white rounded-lg shadow">
          <div className="p-6">
            <h2 className="text-lg font-medium text-gray-900 mb-4">
              AR Ageing as of {report.as_of}
            </h2>
            
            {report.rows && report.rows.length > 0 ? (
              <>
                <DataTable data={report.rows.map((r, i) => ({ ...r, id: r.buyer_party_id || String(i) }))} columns={columns} />
                
                <div className="mt-6 pt-6 border-t border-gray-200">
                  <h3 className="text-md font-medium text-gray-900 mb-4">Totals</h3>
                  <div className="grid grid-cols-5 gap-4">
                    <div>
                      <div className="text-sm text-gray-500">Total Outstanding</div>
                      <div className="text-lg font-semibold">{formatMoney(report.totals.total_outstanding)}</div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">0-30 Days</div>
                      <div className="text-lg font-semibold">{formatMoney(report.totals.bucket_0_30)}</div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">31-60 Days</div>
                      <div className="text-lg font-semibold">{formatMoney(report.totals.bucket_31_60)}</div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">61-90 Days</div>
                      <div className="text-lg font-semibold">{formatMoney(report.totals.bucket_61_90)}</div>
                    </div>
                    <div>
                      <div className="text-sm text-gray-500">90+ Days</div>
                      <div className="text-lg font-semibold text-red-600">{formatMoney(report.totals.bucket_90_plus)}</div>
                    </div>
                  </div>
                </div>
              </>
            ) : (
              <p className="text-gray-500">No outstanding receivables as of {report.as_of}</p>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { reportsApi } from '../../api/reports';
import { exportToCSV } from '../../utils/csvExport';
import { useFormatting } from '../../hooks/useFormatting';
import { useParties } from '../../hooks/useParties';
import type { LandlordStatementResponse } from '@farm-erp/shared';
import type { Party } from '../../types';

export default function LandlordStatementPage() {
  const { formatMoney } = useFormatting();
  const { data: parties = [] } = useParties();
  const [data, setData] = useState<LandlordStatementResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [filters, setFilters] = useState({
    party_id: '',
    date_from: new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0],
    date_to: new Date().toISOString().split('T')[0],
  });

  useEffect(() => {
    const fetchData = async () => {
      if (!filters.party_id || !filters.date_from || !filters.date_to) {
        setData(null);
        return;
      }
      try {
        setLoading(true);
        setError(null);
        const result = await reportsApi.landlordStatement({
          party_id: filters.party_id,
          date_from: filters.date_from,
          date_to: filters.date_to,
        });
        setData(result);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch landlord statement');
        setData(null);
      } finally {
        setLoading(false);
      }
    };
    fetchData();
  }, [filters.party_id, filters.date_from, filters.date_to]);

  const handleExport = () => {
    if (!data?.lines?.length || !filters.party_id) return;
    exportToCSV(
      data.lines,
      '',
      [
        'posting_date',
        'description',
        'source_type',
        'source_id',
        'posting_group_id',
        'debit',
        'credit',
        'running_balance',
        'lease_id',
        'land_parcel_id',
        'project_id',
      ],
      {
        reportName: 'LandlordStatement',
        fromDate: filters.date_from,
        toDate: filters.date_to,
      }
    );
  };

  const lines = data?.lines ?? [];

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center no-print">
        <h2 className="text-2xl font-bold">Landlord Statement</h2>
        <div className="flex gap-2">
          <button
            type="button"
            onClick={() => window.print()}
            className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] text-sm font-medium"
          >
            Print
          </button>
          <button
            type="button"
            onClick={handleExport}
            disabled={!data?.lines?.length}
            className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] disabled:bg-gray-400 disabled:cursor-not-allowed text-sm font-medium"
          >
            Export CSV
          </button>
        </div>
      </div>

      <div className="bg-white p-4 rounded-lg shadow space-y-4 no-print">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Landlord (Party)</label>
            <select
              value={filters.party_id}
              onChange={(e) => setFilters({ ...filters, party_id: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">Select landlord</option>
              {parties.map((p: Party) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">From Date</label>
            <input
              type="date"
              value={filters.date_from}
              onChange={(e) => setFilters({ ...filters, date_from: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">To Date</label>
            <input
              type="date"
              value={filters.date_to}
              onChange={(e) => setFilters({ ...filters, date_to: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
        </div>
      </div>

      {loading && <div className="text-center py-8">Loading...</div>}
      {error && (
        <div className="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
          {error}
        </div>
      )}

      {!loading && !error && data && (
        <>
          <div className="bg-white rounded-lg shadow p-4 print:shadow-none">
            <div className="mb-4">
              <h3 className="text-lg font-semibold text-gray-900">
                {data.party.name}
              </h3>
              <p className="text-sm text-gray-500">
                Statement from {data.date_from} to {data.date_to}
              </p>
              <div className="mt-2 flex gap-6 text-sm">
                <span>
                  <strong>Opening balance:</strong> {formatMoney(data.opening_balance)}
                </span>
                <span>
                  <strong>Closing balance:</strong> {formatMoney(data.closing_balance)}
                </span>
              </div>
            </div>

            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-[#E6ECEA]">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Date
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Description
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Posting Group
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Debit
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Credit
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Running Balance
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {lines.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="px-6 py-4 text-center text-gray-500">
                        No entries in this period
                      </td>
                    </tr>
                  ) : (
                    lines.map((line, idx) => (
                      <tr key={`${line.posting_group_id}-${line.posting_date}-${idx}`}>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          {line.posting_date}
                        </td>
                        <td className="px-6 py-4 text-sm text-gray-900">
                          {line.description}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm">
                          <Link
                            to={`/app/posting-groups/${line.posting_group_id}`}
                            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                          >
                            {line.posting_group_id.slice(0, 8)}…
                          </Link>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                          {formatMoney(line.debit)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                          {formatMoney(line.credit)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                          {formatMoney(line.running_balance)}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}

      {!loading && !error && !filters.party_id && (
        <div className="bg-white rounded-lg shadow p-8 text-center text-gray-500">
          Select a landlord and date range to view the statement.
        </div>
      )}

      <div className="no-print">
        <Link
          to="/app/land-leases"
          className="text-[#1F6F5C] hover:text-[#1a5a4a] text-sm"
        >
          ← Back to Land Leases
        </Link>
      </div>
    </div>
  );
}

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient } from '@farm-erp/shared';
import { reportsApi } from '../api/reports';
import { exportToCSV } from '../utils/csvExport';
import { useFormatting } from '../hooks/useFormatting';
import { useParties } from '../hooks/useParties';
import { PrintableReport } from '../components/print/PrintableReport';
import type { PartyLedgerResponse, Party, Project, CropCycle } from '../types';

function PartyLedgerPage() {
  const { formatMoney, formatDate } = useFormatting();
  const { data: parties = [] } = useParties();
  const [data, setData] = useState<PartyLedgerResponse | null>(null);
  const [projects, setProjects] = useState<Project[]>([]);
  const [cropCycles, setCropCycles] = useState<CropCycle[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [filters, setFilters] = useState({
    party_id: '',
    from: new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
    project_id: '',
    crop_cycle_id: '',
  });

  useEffect(() => {
    const fetchOptions = async () => {
      try {
        const [projectsData, cropCyclesData] = await Promise.all([
          apiClient.get<Project[]>('/api/projects'),
          apiClient.get<CropCycle[]>('/api/crop-cycles').catch(() => []),
        ]);
        setProjects(projectsData);
        setCropCycles(cropCyclesData);
      } catch (err) {
        console.error('Failed to fetch options', err);
      }
    };
    fetchOptions();
  }, []);

  useEffect(() => {
    const fetchData = async () => {
      if (!filters.party_id || !filters.from || !filters.to) {
        setData(null);
        return;
      }
      try {
        setLoading(true);
        setError(null);
        const result = await reportsApi.partyLedger({
          party_id: filters.party_id,
          from: filters.from,
          to: filters.to,
          project_id: filters.project_id || undefined,
          crop_cycle_id: filters.crop_cycle_id || undefined,
        });
        setData(result);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch party ledger');
        setData(null);
      } finally {
        setLoading(false);
      }
    };
    fetchData();
  }, [filters.party_id, filters.from, filters.to, filters.project_id, filters.crop_cycle_id]);

  const handleExport = () => {
    if (!data?.rows?.length || !filters.party_id) return;
    exportToCSV(
      data.rows,
      '',
      [
        'posting_date',
        'posting_group_id',
        'source_type',
        'source_id',
        'description',
        'project_id',
        'crop_cycle_id',
        'debit',
        'credit',
        'running_balance',
      ],
      {
        reportName: 'PartyLedger',
        fromDate: filters.from,
        toDate: filters.to,
      }
    );
  };

  const selectedParty = parties.find((p: Party) => p.id === filters.party_id);
  const rows = data?.rows ?? [];

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center no-print">
        <h2 className="text-2xl font-bold">Party Ledger</h2>
        <div className="flex gap-2">
          <button
            onClick={() => window.print()}
            className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] text-sm font-medium"
          >
            Print
          </button>
          <button
            onClick={handleExport}
            disabled={!data?.rows?.length}
            className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] disabled:bg-gray-400 disabled:cursor-not-allowed text-sm font-medium"
          >
            Export CSV
          </button>
        </div>
      </div>

      <div className="bg-white p-4 rounded-lg shadow space-y-4 no-print">
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Party</label>
            <select
              value={filters.party_id}
              onChange={(e) => setFilters({ ...filters, party_id: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">Select party</option>
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
              value={filters.from}
              onChange={(e) => setFilters({ ...filters, from: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">To Date</label>
            <input
              type="date"
              value={filters.to}
              onChange={(e) => setFilters({ ...filters, to: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Project</label>
            <select
              value={filters.project_id}
              onChange={(e) => setFilters({ ...filters, project_id: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All Projects</option>
              {projects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Crop Cycle</label>
            <select
              value={filters.crop_cycle_id}
              onChange={(e) => setFilters({ ...filters, crop_cycle_id: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All Crop Cycles</option>
              {cropCycles.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      {loading && <div className="text-center py-8">Loading...</div>}
      {error && (
        <div className="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
          {error}
        </div>
      )}

      {!loading && !error && (
        <>
          <div className="bg-white rounded-lg shadow overflow-hidden no-print">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-[#E6ECEA]">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Date
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Posting Group
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Source Type
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Description
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
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="px-6 py-4 text-center text-gray-500">
                        {filters.party_id
                          ? 'No ledger entries in this period'
                          : 'Select a party and date range'}
                      </td>
                    </tr>
                  ) : (
                    rows.map((row, idx) => (
                      <tr key={`${row.posting_group_id}-${idx}`}>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          {formatDate(row.posting_date)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          <Link
                            to={`/app/posting-groups/${row.posting_group_id}`}
                            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                          >
                            {row.posting_group_id?.substring(0, 8)}...
                          </Link>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {row.source_type}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {row.description ?? '—'}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                          {formatMoney(row.debit)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                          {formatMoney(row.credit)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right tabular-nums">
                          {formatMoney(row.running_balance)}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
                {data && rows.length > 0 && (
                  <tfoot className="bg-[#E6ECEA] font-medium">
                    <tr>
                      <td colSpan={4} className="px-6 py-3 text-sm text-gray-700">
                        Opening balance
                      </td>
                      <td colSpan={2} className="px-6 py-3 text-right text-sm text-gray-700" />
                      <td className="px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(data.opening_balance)}
                      </td>
                    </tr>
                    <tr>
                      <td colSpan={4} className="px-6 py-3 text-sm text-gray-700">
                        Closing balance
                      </td>
                      <td colSpan={2} className="px-6 py-3 text-right text-sm text-gray-700" />
                      <td className="px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(data.closing_balance)}
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </div>

          <PrintableReport
            title="Party Ledger"
            metaLeft={
              selectedParty
                ? `${selectedParty.name} • From ${formatDate(filters.from)} to ${formatDate(filters.to)}`
                : `From ${formatDate(filters.from)} to ${formatDate(filters.to)}`
            }
          >
            <table className="w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Date
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Posting Group
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Source Type
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Description
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
                {rows.map((row, idx) => (
                  <tr key={`${row.posting_group_id}-${idx}`}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">{row.posting_date}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">{row.posting_group_id?.substring(0, 8)}...</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">{row.source_type}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">{row.description ?? '—'}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right">{row.debit}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right">{row.credit}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                      {row.running_balance}
                    </td>
                  </tr>
                ))}
              </tbody>
              {data && rows.length > 0 && (
                <tfoot className="bg-[#E6ECEA] font-medium">
                  <tr>
                    <td colSpan={4} className="px-6 py-3 text-sm">Opening balance</td>
                    <td colSpan={2} className="px-6 py-3 text-right text-sm" />
                    <td className="px-6 py-3 text-right text-sm">{data.opening_balance}</td>
                  </tr>
                  <tr>
                    <td colSpan={4} className="px-6 py-3 text-sm">Closing balance</td>
                    <td colSpan={2} className="px-6 py-3 text-right text-sm" />
                    <td className="px-6 py-3 text-right text-sm">{data.closing_balance}</td>
                  </tr>
                </tfoot>
              )}
            </table>
          </PrintableReport>
        </>
      )}
    </div>
  );
}

export default PartyLedgerPage;

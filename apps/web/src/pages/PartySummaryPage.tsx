import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiClient } from '@farm-erp/shared';
import { reportsApi } from '../api/reports';
import { exportToCSV } from '../utils/csvExport';
import { useFormatting } from '../hooks/useFormatting';
import { PrintableReport } from '../components/print/PrintableReport';
import type { PartySummaryResponse, PartySummaryRow, Project, CropCycle } from '../types';

function PartySummaryPage() {
  const { formatMoney, formatDate } = useFormatting();
  const navigate = useNavigate();
  const [data, setData] = useState<PartySummaryResponse | null>(null);
  const [projects, setProjects] = useState<Project[]>([]);
  const [cropCycles, setCropCycles] = useState<CropCycle[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [filters, setFilters] = useState({
    from: new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
    role: '',
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
      if (!filters.from || !filters.to) {
        setData(null);
        return;
      }
      try {
        setLoading(true);
        setError(null);
        const result = await reportsApi.partySummary({
          from: filters.from,
          to: filters.to,
          role: filters.role || undefined,
          project_id: filters.project_id || undefined,
          crop_cycle_id: filters.crop_cycle_id || undefined,
        });
        setData(result);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch party summary');
        setData(null);
      } finally {
        setLoading(false);
      }
    };
    fetchData();
  }, [filters.from, filters.to, filters.role, filters.project_id, filters.crop_cycle_id]);

  const handleExport = () => {
    if (!data?.rows?.length) return;
    exportToCSV(
      data.rows,
      '',
      ['party_name', 'role', 'opening_balance', 'period_movement', 'closing_balance'],
      {
        reportName: 'RoleSummary',
        fromDate: filters.from,
        toDate: filters.to,
      }
    );
  };

  const handleRowClick = (row: PartySummaryRow) => {
    const params = new URLSearchParams({
      party_id: row.party_id,
      from: filters.from,
      to: filters.to,
    });
    if (filters.project_id) params.append('project_id', filters.project_id);
    if (filters.crop_cycle_id) params.append('crop_cycle_id', filters.crop_cycle_id);
    navigate(`/app/reports/party-ledger?${params.toString()}`);
  };

  const rows = data?.rows ?? [];
  const totals = data?.totals;

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center no-print">
        <h2 className="text-2xl font-bold">Role Summary</h2>
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
            <label className="block text-sm font-medium text-gray-700 mb-1">Role</label>
            <select
              value={filters.role}
              onChange={(e) => setFilters({ ...filters, role: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All</option>
              <option value="HARI">Hari</option>
              <option value="LANDLORD">Landlord</option>
              <option value="KAMDAR">Kamdar</option>
            </select>
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
        <p className="text-sm text-gray-600 mt-2">
          Shows balances summarized by role (Hari/Landlord/Kamdar). Use Party Ledger for detailed transactions.
        </p>
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
                      Party
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Role
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Opening
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Movement
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Closing
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="px-6 py-4 text-center text-gray-500">
                        No party balances in this period
                      </td>
                    </tr>
                  ) : (
                    rows.map((row) => (
                      <tr
                        key={`${row.party_id}-${row.role}`}
                        onClick={() => handleRowClick(row)}
                        className="cursor-pointer hover:bg-gray-50"
                      >
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          {row.party_name}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {row.role}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                          {formatMoney(row.opening_balance)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                          {formatMoney(row.period_movement)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right tabular-nums">
                          {formatMoney(row.closing_balance)}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
                {totals && rows.length > 0 && (
                  <tfoot className="bg-[#E6ECEA] font-medium">
                    <tr>
                      <td className="px-6 py-3 text-sm text-gray-700" colSpan={2}>
                        Totals
                      </td>
                      <td className="px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(totals.opening_balance)}
                      </td>
                      <td className="px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(totals.period_movement)}
                      </td>
                      <td className="px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(totals.closing_balance)}
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </div>

          <PrintableReport
            title="Role Summary"
            metaLeft={`From ${formatDate(filters.from)} to ${formatDate(filters.to)}`}
          >
            <table className="w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Party
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Role
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Opening
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Movement
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Closing
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {rows.map((row) => (
                  <tr key={`${row.party_id}-${row.role}`}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">{row.party_name}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">{row.role}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right">
                      {row.opening_balance}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right">
                      {row.period_movement}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                      {row.closing_balance}
                    </td>
                  </tr>
                ))}
              </tbody>
              {totals && rows.length > 0 && (
                <tfoot className="bg-[#E6ECEA] font-medium">
                  <tr>
                    <td className="px-6 py-3 text-sm" colSpan={2}>
                      Totals
                    </td>
                    <td className="px-6 py-3 text-right text-sm">{totals.opening_balance}</td>
                    <td className="px-6 py-3 text-right text-sm">{totals.period_movement}</td>
                    <td className="px-6 py-3 text-right text-sm">{totals.closing_balance}</td>
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

export default PartySummaryPage;

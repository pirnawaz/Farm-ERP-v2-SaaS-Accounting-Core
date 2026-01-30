import { useEffect, useState } from 'react';
import { apiClient } from '@farm-erp/shared';
import { reportsApi } from '../api/reports';
import { exportToCSV } from '../utils/csvExport';
import { useFormatting } from '../hooks/useFormatting';
import { PrintableReport } from '../components/print/PrintableReport';
import type { RoleAgeingResponse, RoleAgeingRow, Project, CropCycle } from '../types';

function RoleAgeingPage() {
  const { formatMoney, formatDate } = useFormatting();
  const [data, setData] = useState<RoleAgeingResponse | null>(null);
  const [projects, setProjects] = useState<Project[]>([]);
  const [cropCycles, setCropCycles] = useState<CropCycle[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [filters, setFilters] = useState({
    as_of: new Date().toISOString().split('T')[0],
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
      if (!filters.as_of) {
        setData(null);
        return;
      }
      try {
        setLoading(true);
        setError(null);
        const result = await reportsApi.roleAgeing({
          as_of: filters.as_of,
          project_id: filters.project_id || undefined,
          crop_cycle_id: filters.crop_cycle_id || undefined,
        });
        setData(result);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch role ageing');
        setData(null);
      } finally {
        setLoading(false);
      }
    };
    fetchData();
  }, [filters.as_of, filters.project_id, filters.crop_cycle_id]);

  const handleExport = () => {
    if (!data?.rows?.length) return;
    exportToCSV(
      data.rows,
      '',
      ['role', 'label', 'bucket_0_30', 'bucket_31_60', 'bucket_61_90', 'bucket_90_plus', 'total_balance'],
      {
        reportName: 'RoleAgeing',
        asOfDate: filters.as_of,
      }
    );
  };

  const rows = data?.rows ?? [];
  const totals = data?.totals;

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center no-print">
        <h2 className="text-2xl font-bold">Role Ageing</h2>
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
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">As Of Date</label>
            <input
              type="date"
              value={filters.as_of}
              onChange={(e) => setFilters({ ...filters, as_of: e.target.value })}
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
        <p className="text-sm text-gray-600 mt-2">
          Role-level ageing based on posting date of party control movements. Per-party ageing will be available when subledger is enabled.
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
                      Role
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      0–30
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      31–60
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      61–90
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      90+
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Total
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="px-6 py-4 text-center text-gray-500">
                        No role balances as of this date
                      </td>
                    </tr>
                  ) : (
                    rows.map((row: RoleAgeingRow) => (
                      <tr key={row.role}>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          {row.label}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                          {formatMoney(row.bucket_0_30)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                          {formatMoney(row.bucket_31_60)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                          {formatMoney(row.bucket_61_90)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                          {formatMoney(row.bucket_90_plus)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right tabular-nums">
                          {formatMoney(row.total_balance)}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
                {totals && rows.length > 0 && (
                  <tfoot className="bg-[#E6ECEA] font-medium">
                    <tr>
                      <td className="px-6 py-3 text-sm text-gray-700">
                        Totals
                      </td>
                      <td className="px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(totals.bucket_0_30)}
                      </td>
                      <td className="px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(totals.bucket_31_60)}
                      </td>
                      <td className="px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(totals.bucket_61_90)}
                      </td>
                      <td className="px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(totals.bucket_90_plus)}
                      </td>
                      <td className="px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(totals.total_balance)}
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </div>

          <PrintableReport
            title="Role Ageing"
            metaLeft={`As of ${formatDate(filters.as_of)}`}
          >
            <table className="w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Role
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    0–30
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    31–60
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    61–90
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    90+
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Total
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {rows.map((row: RoleAgeingRow) => (
                  <tr key={row.role}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">{row.label}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right">
                      {row.bucket_0_30}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right">
                      {row.bucket_31_60}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right">
                      {row.bucket_61_90}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right">
                      {row.bucket_90_plus}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                      {row.total_balance}
                    </td>
                  </tr>
                ))}
              </tbody>
              {totals && rows.length > 0 && (
                <tfoot className="bg-[#E6ECEA] font-medium">
                  <tr>
                    <td className="px-6 py-3 text-sm">Totals</td>
                    <td className="px-6 py-3 text-right text-sm">{totals.bucket_0_30}</td>
                    <td className="px-6 py-3 text-right text-sm">{totals.bucket_31_60}</td>
                    <td className="px-6 py-3 text-right text-sm">{totals.bucket_61_90}</td>
                    <td className="px-6 py-3 text-right text-sm">{totals.bucket_90_plus}</td>
                    <td className="px-6 py-3 text-right text-sm">{totals.total_balance}</td>
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

export default RoleAgeingPage;

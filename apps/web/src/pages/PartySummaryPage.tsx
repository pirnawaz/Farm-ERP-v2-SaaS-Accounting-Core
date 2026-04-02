import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiClient } from '@farm-erp/shared';
import { reportsApi } from '../api/reports';
import { exportToCSV } from '../utils/csvExport';
import { exportAmountForSpreadsheet } from '../utils/exportFormatting';
import { metaReportingPeriodLabel } from '../utils/reportPresentation';
import { useFormatting } from '../hooks/useFormatting';
import { useLocalisation } from '../hooks/useLocalisation';
import { useTenantSettings } from '../hooks/useTenantSettings';
import { EMPTY_COPY } from '../config/presentation';
import { PrintableReport } from '../components/print/PrintableReport';
import type { PartySummaryResponse, PartySummaryRow, Project, CropCycle } from '../types';
import { term } from '../config/terminology';
import { ReportMetadataBlock } from '../components/report/ReportMetadataBlock';
import { terravaBaseExportMetadataRows } from '../utils/reportPageMetadata';
import { ReportErrorState, ReportLoadingState } from '../components/report';
import { PageContainer } from '../components/PageContainer';
import { FilterBar, FilterField, FilterGrid } from '../components/FilterBar';

function PartySummaryPage() {
  const { formatMoney, formatDateRange } = useFormatting();
  const { settings } = useTenantSettings();
  const { currency_code, locale, timezone } = useLocalisation();
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
    const mapped = data.rows.map((row) => ({
      party_name: row.party_name,
      role: row.role,
      opening_balance: exportAmountForSpreadsheet(row.opening_balance),
      period_movement: exportAmountForSpreadsheet(row.period_movement),
      closing_balance: exportAmountForSpreadsheet(row.closing_balance),
    }));
    const headers = ['party_name', 'role', 'opening_balance', 'period_movement', 'closing_balance'];
    exportToCSV(mapped, '', headers, {
      reportName: 'RoleSummary',
      fromDate: filters.from,
      toDate: filters.to,
      metadataRows: terravaBaseExportMetadataRows({
        reportExportName: 'Terrava Party Summary',
        baseCurrency: currency_code || settings?.currency_code || 'PKR',
        period: { mode: 'range', from: filters.from, to: filters.to },
        locale,
        timezone,
      }),
    });
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
    <PageContainer className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between no-print">
        <h2 className="text-2xl font-bold">Role Summary</h2>
        <div className="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
          <button
            onClick={() => window.print()}
            className="w-full sm:w-auto bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] text-sm font-medium"
          >
            Print
          </button>
          <button
            onClick={handleExport}
            disabled={!data?.rows?.length}
            className="w-full sm:w-auto bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] disabled:bg-gray-400 disabled:cursor-not-allowed text-sm font-medium"
          >
            Export CSV
          </button>
        </div>
      </div>

      <div className="bg-white p-4 rounded-lg shadow space-y-4 no-print">
        <FilterBar>
          <FilterGrid>
            <FilterField label="From Date">
              <input
                type="date"
                value={filters.from}
                onChange={(e) => setFilters({ ...filters, from: e.target.value })}
              />
            </FilterField>
            <FilterField label="To Date">
              <input
                type="date"
                value={filters.to}
                onChange={(e) => setFilters({ ...filters, to: e.target.value })}
              />
            </FilterField>
            <FilterField label="Role">
              <select
                value={filters.role}
                onChange={(e) => setFilters({ ...filters, role: e.target.value })}
              >
                <option value="">All</option>
                <option value="HARI">Hari</option>
                <option value="LANDLORD">Landlord</option>
                <option value="KAMDAR">Kamdar</option>
              </select>
            </FilterField>
            <FilterField label={term('fieldCycle')}>
              <select
                value={filters.project_id}
                onChange={(e) => setFilters({ ...filters, project_id: e.target.value })}
              >
                <option value="">{`All ${term('fieldCycles')}`}</option>
                {projects.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </FilterField>
            <FilterField label="Crop Cycle">
              <select
                value={filters.crop_cycle_id}
                onChange={(e) => setFilters({ ...filters, crop_cycle_id: e.target.value })}
              >
                <option value="">All Crop Cycles</option>
                {cropCycles.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </FilterField>
          </FilterGrid>
        </FilterBar>
        <p className="text-sm text-gray-600 mt-2">
          Shows balances summarized by role (Hari/Landlord/Kamdar). Use Party Ledger for detailed transactions.
        </p>
      </div>

      <div className="no-print">
        <ReportMetadataBlock reportingPeriodRange={formatDateRange(filters.from, filters.to)} />
      </div>

      {loading && <ReportLoadingState label="Loading role summary..." className="no-print" />}
      {error && <ReportErrorState error={error} className="no-print" />}

      {!loading && !error && (
        <>
          <div className="bg-white rounded-lg shadow overflow-hidden no-print">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-[#E6ECEA]">
                  <tr>
                    <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Party
                    </th>
                    <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Role
                    </th>
                    <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Opening
                    </th>
                    <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Movement
                    </th>
                    <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Closing
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="px-3 sm:px-6 py-3 sm:py-4 text-center text-gray-500">
                        No activity found for this period.
                      </td>
                    </tr>
                  ) : (
                    rows.map((row) => (
                      <tr
                        key={`${row.party_id}-${row.role}`}
                        onClick={() => handleRowClick(row)}
                        className="cursor-pointer hover:bg-gray-50"
                      >
                        <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-normal break-words text-sm text-gray-900">
                          {row.party_name}
                        </td>
                        <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-500">
                          {row.role}
                        </td>
                        <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                          {formatMoney(row.opening_balance)}
                        </td>
                        <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                          {formatMoney(row.period_movement)}
                        </td>
                        <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right tabular-nums">
                          {formatMoney(row.closing_balance)}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
                {totals && rows.length > 0 && (
                  <tfoot className="bg-[#E6ECEA] font-medium">
                    <tr>
                      <td className="px-3 sm:px-6 py-3 text-sm text-gray-700" colSpan={2}>
                        Totals
                      </td>
                      <td className="px-3 sm:px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(totals.opening_balance)}
                      </td>
                      <td className="px-3 sm:px-6 py-3 text-right text-sm tabular-nums">
                        {formatMoney(totals.period_movement)}
                      </td>
                      <td className="px-3 sm:px-6 py-3 text-right text-sm tabular-nums">
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
            metaLeft={metaReportingPeriodLabel(formatDateRange(filters.from, filters.to))}
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
                {rows.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-6 py-4 text-center text-gray-500">
                      {EMPTY_COPY.noDataForPeriod}
                    </td>
                  </tr>
                ) : (
                  rows.map((row) => (
                    <tr key={`${row.party_id}-${row.role}`}>
                      <td className="px-6 py-4 whitespace-nowrap text-sm">{row.party_name}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm">{row.role}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-right tabular-nums">
                        {formatMoney(row.opening_balance)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-right tabular-nums">
                        {formatMoney(row.period_movement)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-right font-medium tabular-nums">
                        {formatMoney(row.closing_balance)}
                      </td>
                    </tr>
                  ))
                )}
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
    </PageContainer>
  );
}

export default PartySummaryPage;

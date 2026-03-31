import { useEffect, useState } from 'react';
import { AccountBalanceRow, Project, apiClient } from '@farm-erp/shared';
import { exportToCSV } from '../utils/csvExport';
import { exportAmountForSpreadsheet } from '../utils/exportFormatting';
import { metaAsOfLabel } from '../utils/reportPresentation';
import { useFormatting } from '../hooks/useFormatting';
import { useLocalisation } from '../hooks/useLocalisation';
import { terravaBaseExportMetadataRows } from '../utils/reportPageMetadata';
import { PrintableReport } from '../components/print/PrintableReport';
import {
  ReportTable,
  ReportTableHead,
  ReportTableBody,
  ReportTableRow,
  ReportTableHeaderCell,
  ReportTableCell,
  ReportEmptyState,
  ReportMetadataBlock,
  ReportErrorState,
  ReportLoadingState,
} from '../components/report';
import { EMPTY_COPY } from '../config/presentation';
import { term } from '../config/terminology';

function AccountBalancesPage() {
  const { formatMoney, formatDate } = useFormatting();
  const { currency_code, locale, timezone } = useLocalisation();
  const [data, setData] = useState<AccountBalanceRow[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [filters, setFilters] = useState({
    as_of: new Date().toISOString().split('T')[0],
    project_id: '',
  });

  useEffect(() => {
    const fetchProjects = async () => {
      try {
        const projectsData = await apiClient.get<Project[]>('/api/projects');
        setProjects(projectsData);
      } catch (err) {
        console.error('Failed to fetch projects', err);
      }
    };
    fetchProjects();
  }, []);

  useEffect(() => {
    const fetchData = async () => {
      if (!filters.as_of) return;

      try {
        setLoading(true);
        setError(null);

        const result = await apiClient.getAccountBalances({
          as_of: filters.as_of,
          project_id: filters.project_id || undefined,
        });
        setData(result);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch account balances');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [filters]);

  const handleExport = () => {
    const rows = data.map((row) => ({
      account_code: row.account_code,
      account_name: row.account_name,
      account_type: row.account_type,
      currency_code: row.currency_code,
      debits: exportAmountForSpreadsheet(row.debits),
      credits: exportAmountForSpreadsheet(row.credits),
      balance: exportAmountForSpreadsheet(row.balance),
    }));
    const headers = ['account_code', 'account_name', 'account_type', 'currency_code', 'debits', 'credits', 'balance'];
    exportToCSV(rows, '', headers, {
      reportName: 'AccountBalances',
      asOfDate: filters.as_of,
      metadataRows: terravaBaseExportMetadataRows({
        reportExportName: 'Terrava Account Balances',
        baseCurrency: currency_code,
        period: { mode: 'asOf', asOf: filters.as_of },
        locale,
        timezone,
      }),
    });
  };

  const asOfMeta = metaAsOfLabel(formatDate(filters.as_of));

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center no-print">
        <h2 className="text-2xl font-bold">Account Balances</h2>
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
            disabled={data.length === 0}
            className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] disabled:bg-gray-400 disabled:cursor-not-allowed text-sm font-medium"
          >
            Export CSV
          </button>
        </div>
      </div>

      <div className="bg-white p-4 rounded-lg shadow space-y-4 no-print">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">As of</label>
            <input
              type="date"
              value={filters.as_of}
              onChange={(e) => setFilters({ ...filters, as_of: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{term('fieldCycle')}</label>
            <select
              value={filters.project_id}
              onChange={(e) => setFilters({ ...filters, project_id: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">{`All ${term('fieldCycles')}`}</option>
              {projects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      <div className="no-print">
        <ReportMetadataBlock asOfDate={formatDate(filters.as_of)} />
      </div>

      {loading && <ReportLoadingState label="Loading account balances..." className="no-print" />}
      {error && <ReportErrorState error={error} className="no-print" />}

      {!loading && !error && (
        <>
          <div className="bg-white rounded-lg shadow overflow-hidden no-print">
            <ReportTable>
              <ReportTableHead>
                <ReportTableRow>
                  <ReportTableHeaderCell>Account Code</ReportTableHeaderCell>
                  <ReportTableHeaderCell>Account Name</ReportTableHeaderCell>
                  <ReportTableHeaderCell>Type</ReportTableHeaderCell>
                  <ReportTableHeaderCell>Currency</ReportTableHeaderCell>
                  <ReportTableHeaderCell align="right">Debits</ReportTableHeaderCell>
                  <ReportTableHeaderCell align="right">Credits</ReportTableHeaderCell>
                  <ReportTableHeaderCell align="right">Balance</ReportTableHeaderCell>
                </ReportTableRow>
              </ReportTableHead>
              <ReportTableBody>
                {data.length === 0 ? (
                  <ReportEmptyState colSpan={7} message="No balances found for this date." />
                ) : (
                  data.map((row) => (
                    <ReportTableRow key={`${row.account_id}-${row.currency_code}`}>
                      <ReportTableCell>{row.account_code}</ReportTableCell>
                      <ReportTableCell muted>{row.account_name}</ReportTableCell>
                      <ReportTableCell muted>{row.account_type}</ReportTableCell>
                      <ReportTableCell muted>{row.currency_code}</ReportTableCell>
                      <ReportTableCell align="right" numeric>
                        {formatMoney(row.debits)}
                      </ReportTableCell>
                      <ReportTableCell align="right" numeric>
                        {formatMoney(row.credits)}
                      </ReportTableCell>
                      <ReportTableCell align="right" numeric>
                        {formatMoney(row.balance)}
                      </ReportTableCell>
                    </ReportTableRow>
                  ))
                )}
              </ReportTableBody>
            </ReportTable>
          </div>

          <PrintableReport title="Account Balances" metaLeft={asOfMeta}>
            <ReportTable>
              <ReportTableHead>
                <ReportTableRow>
                  <ReportTableHeaderCell>Account Code</ReportTableHeaderCell>
                  <ReportTableHeaderCell>Account Name</ReportTableHeaderCell>
                  <ReportTableHeaderCell>Type</ReportTableHeaderCell>
                  <ReportTableHeaderCell>Currency</ReportTableHeaderCell>
                  <ReportTableHeaderCell align="right">Debits</ReportTableHeaderCell>
                  <ReportTableHeaderCell align="right">Credits</ReportTableHeaderCell>
                  <ReportTableHeaderCell align="right">Balance</ReportTableHeaderCell>
                </ReportTableRow>
              </ReportTableHead>
              <ReportTableBody>
                {data.length === 0 ? (
                  <ReportEmptyState colSpan={7} message="No balances found for this date." />
                ) : (
                  data.map((row) => (
                    <ReportTableRow key={`print-${row.account_id}-${row.currency_code}`}>
                      <ReportTableCell>{row.account_code}</ReportTableCell>
                      <ReportTableCell muted>{row.account_name}</ReportTableCell>
                      <ReportTableCell muted>{row.account_type}</ReportTableCell>
                      <ReportTableCell muted>{row.currency_code}</ReportTableCell>
                      <ReportTableCell align="right" numeric>
                        {formatMoney(row.debits)}
                      </ReportTableCell>
                      <ReportTableCell align="right" numeric>
                        {formatMoney(row.credits)}
                      </ReportTableCell>
                      <ReportTableCell align="right" numeric>
                        {formatMoney(row.balance)}
                      </ReportTableCell>
                    </ReportTableRow>
                  ))
                )}
              </ReportTableBody>
            </ReportTable>
          </PrintableReport>
        </>
      )}
    </div>
  );
}

export default AccountBalancesPage;

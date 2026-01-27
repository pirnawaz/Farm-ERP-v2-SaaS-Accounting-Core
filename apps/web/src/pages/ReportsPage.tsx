import { useState } from 'react';
import { useTrialBalance, useGeneralLedger, useProjectStatement } from '../hooks/useReports';
import { useProjects } from '../hooks/useProjects';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { useFormatting } from '../hooks/useFormatting';
import { EmptyState } from '../components/EmptyState';
import type { TrialBalanceRow, GeneralLedgerRow } from '../types';

type Tab = 'trial-balance' | 'general-ledger' | 'project-statement';

export default function ReportsPage() {
  const { formatMoney, formatDate } = useFormatting();
  const [activeTab, setActiveTab] = useState<Tab>('trial-balance');
  const [trialBalanceParams, setTrialBalanceParams] = useState({
    from: new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
  });
  const [generalLedgerParams, setGeneralLedgerParams] = useState({
    from: new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
    account_id: '',
    project_id: '',
    page: 1,
    per_page: 50,
  });
  const [projectStatementParams, setProjectStatementParams] = useState({
    project_id: '',
    up_to_date: new Date().toISOString().split('T')[0],
  });

  const { data: trialBalance, isLoading: loadingTrialBalance } = useTrialBalance(trialBalanceParams);
  const { data: generalLedger, isLoading: loadingGeneralLedger } = useGeneralLedger(generalLedgerParams);
  const { data: projectStatement, isLoading: loadingProjectStatement } = useProjectStatement(projectStatementParams);
  const { data: projects } = useProjects();

  const trialBalanceColumns: Column<TrialBalanceRow>[] = [
    { header: 'Account Code', accessor: 'account_code' },
    { header: 'Account Name', accessor: 'account_name' },
    { header: 'Account Type', accessor: 'account_type' },
    { header: 'Currency', accessor: 'currency_code' },
    { header: 'Total Debit', accessor: 'total_debit' },
    { header: 'Total Credit', accessor: 'total_credit' },
    { header: 'Net', accessor: 'net' },
  ];

  const generalLedgerColumns: Column<GeneralLedgerRow>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.posting_date) },
    { header: 'Account Code', accessor: 'account_code' },
    { header: 'Account Name', accessor: 'account_name' },
    { header: 'Debit', accessor: 'debit' },
    { header: 'Credit', accessor: 'credit' },
    { header: 'Net', accessor: 'net' },
  ];

  const renderTrialBalance = () => {
    if (loadingTrialBalance) {
      return <LoadingSpinner size="lg" />;
    }

    const totalDebit = trialBalance?.reduce((sum, row) => sum + parseFloat(row.total_debit || '0'), 0) || 0;
    const totalCredit = trialBalance?.reduce((sum, row) => sum + parseFloat(row.total_credit || '0'), 0) || 0;
    const totalNet = trialBalance?.reduce((sum, row) => sum + parseFloat(row.net || '0'), 0) || 0;

    return (
      <div>
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Filters</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">From Date</label>
              <input
                type="date"
                value={trialBalanceParams.from}
                onChange={(e) => setTrialBalanceParams({ ...trialBalanceParams, from: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">To Date</label>
              <input
                type="date"
                value={trialBalanceParams.to}
                onChange={(e) => setTrialBalanceParams({ ...trialBalanceParams, to: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
              />
            </div>
          </div>
        </div>
        <div className="bg-white rounded-lg shadow">
          {trialBalance && trialBalance.length > 0 ? (
            <>
              <DataTable data={trialBalance.map((r, i) => ({ ...r, id: r.account_id || String(i) }))} columns={trialBalanceColumns} />
              <div className="p-4 bg-gray-50 border-t">
                <div className="grid grid-cols-3 gap-4 text-sm font-medium">
                  <div>Total Debit: <span className="tabular-nums">{formatMoney(totalDebit)}</span></div>
                  <div>Total Credit: <span className="tabular-nums">{formatMoney(totalCredit)}</span></div>
                  <div>Total Net: <span className="tabular-nums">{formatMoney(totalNet)}</span></div>
                </div>
              </div>
            </>
          ) : (
            <EmptyState
              title="No trial balance data"
              description="Reports will appear once transactions are posted."
            />
          )}
        </div>
      </div>
    );
  };

  const renderGeneralLedger = () => {
    if (loadingGeneralLedger) {
      return <LoadingSpinner size="lg" />;
    }

    return (
      <div>
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Filters</h2>
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">From Date</label>
              <input
                type="date"
                value={generalLedgerParams.from}
                onChange={(e) => setGeneralLedgerParams({ ...generalLedgerParams, from: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">To Date</label>
              <input
                type="date"
                value={generalLedgerParams.to}
                onChange={(e) => setGeneralLedgerParams({ ...generalLedgerParams, to: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Project</label>
              <select
                value={generalLedgerParams.project_id}
                onChange={(e) => setGeneralLedgerParams({ ...generalLedgerParams, project_id: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
              >
                <option value="">All Projects</option>
                {projects?.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Per Page</label>
              <select
                value={generalLedgerParams.per_page}
                onChange={(e) => setGeneralLedgerParams({ ...generalLedgerParams, per_page: parseInt(e.target.value) })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
              >
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="200">200</option>
              </select>
            </div>
          </div>
        </div>
        <div className="bg-white rounded-lg shadow">
          {generalLedger?.data && generalLedger.data.length > 0 ? (
            <>
              <DataTable data={generalLedger.data.map((r, i) => ({ ...r, id: r.ledger_entry_id || String(i) }))} columns={generalLedgerColumns} />
              {generalLedger.pagination && (
                <div className="p-4 bg-gray-50 border-t">
                  <div className="text-sm text-gray-600">
                    Page {generalLedger.pagination.page} of {generalLedger.pagination.last_page} 
                    (Total: {generalLedger.pagination.total} entries)
                  </div>
                </div>
              )}
            </>
          ) : (
            <EmptyState
              title="No general ledger data"
              description="Reports will appear once transactions are posted."
            />
          )}
        </div>
      </div>
    );
  };

  const renderProjectStatement = () => {
    if (loadingProjectStatement) {
      return <LoadingSpinner size="lg" />;
    }

    return (
      <div>
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Filters</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Project</label>
              <select
                value={projectStatementParams.project_id}
                onChange={(e) => setProjectStatementParams({ ...projectStatementParams, project_id: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
              >
                <option value="">Select Project</option>
                {projects?.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Up To Date</label>
              <input
                type="date"
                value={projectStatementParams.up_to_date}
                onChange={(e) => setProjectStatementParams({ ...projectStatementParams, up_to_date: e.target.value })}
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
              />
            </div>
          </div>
        </div>
        {projectStatement ? (
          <div className="bg-white rounded-lg shadow p-6">
            <h2 className="text-lg font-medium text-gray-900 mb-4">
              Project Statement: {projectStatement.project.name}
            </h2>
            <div className="space-y-4">
              <div>
                <h3 className="font-medium text-gray-900 mb-2">Totals</h3>
                <dl className="grid grid-cols-3 gap-4">
                  <div>
                    <dt className="text-sm text-gray-500">Revenue</dt>
                    <dd className="text-lg font-semibold">{projectStatement.totals.revenue}</dd>
                  </div>
                  <div>
                    <dt className="text-sm text-gray-500">Shared Costs</dt>
                    <dd className="text-lg font-semibold">{projectStatement.totals.shared_costs}</dd>
                  </div>
                  <div>
                    <dt className="text-sm text-gray-500">HARI Only Costs</dt>
                    <dd className="text-lg font-semibold">{projectStatement.totals.hari_only_costs}</dd>
                  </div>
                </dl>
              </div>
              {projectStatement.settlement && (
                <div>
                  <h3 className="font-medium text-gray-900 mb-2">Settlement</h3>
                  <dl className="grid grid-cols-2 gap-4">
                    <div>
                      <dt className="text-sm text-gray-500">Pool Revenue</dt>
                      <dd className="text-sm font-semibold">{projectStatement.settlement.pool_revenue}</dd>
                    </div>
                    <div>
                      <dt className="text-sm text-gray-500">Shared Costs</dt>
                      <dd className="text-sm font-semibold">{projectStatement.settlement.shared_costs}</dd>
                    </div>
                    <div>
                      <dt className="text-sm text-gray-500">Pool Profit</dt>
                      <dd className="text-sm font-semibold">{projectStatement.settlement.pool_profit}</dd>
                    </div>
                    <div>
                      <dt className="text-sm text-gray-500">Kamdari Amount</dt>
                      <dd className="text-sm font-semibold">{projectStatement.settlement.kamdari_amount}</dd>
                    </div>
                    <div>
                      <dt className="text-sm text-gray-500">Landlord Share</dt>
                      <dd className="text-sm font-semibold">{projectStatement.settlement.landlord_share}</dd>
                    </div>
                    <div>
                      <dt className="text-sm text-gray-500">HARI Share</dt>
                      <dd className="text-sm font-semibold">{projectStatement.settlement.hari_share}</dd>
                    </div>
                    <div>
                      <dt className="text-sm text-gray-500">HARI Only Deductions</dt>
                      <dd className="text-sm font-semibold">{projectStatement.settlement.hari_only_deductions}</dd>
                    </div>
                    <div>
                      <dt className="text-sm text-gray-500">Posting Date</dt>
                      <dd className="text-sm font-semibold">{formatDate(projectStatement.settlement.posting_date)}</dd>
                    </div>
                  </dl>
                </div>
              )}
            </div>
          </div>
        ) : (
          <div className="bg-white rounded-lg shadow">
            <EmptyState
              title="No project statement"
              description="Select a project to view its statement."
            />
          </div>
        )}
      </div>
    );
  };

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Reports</h1>

      <div className="border-b border-gray-200 mb-6">
        <nav className="-mb-px flex space-x-8">
          <button
            onClick={() => setActiveTab('trial-balance')}
            className={`py-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'trial-balance'
                ? 'border-[#1F6F5C] text-[#1F6F5C]'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            Trial Balance
          </button>
          <button
            onClick={() => setActiveTab('general-ledger')}
            className={`py-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'general-ledger'
                ? 'border-[#1F6F5C] text-[#1F6F5C]'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            General Ledger
          </button>
          <button
            onClick={() => setActiveTab('project-statement')}
            className={`py-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'project-statement'
                ? 'border-[#1F6F5C] text-[#1F6F5C]'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            Project Statement
          </button>
        </nav>
      </div>

      {activeTab === 'trial-balance' && renderTrialBalance()}
      {activeTab === 'general-ledger' && renderGeneralLedger()}
      {activeTab === 'project-statement' && renderProjectStatement()}
    </div>
  );
}

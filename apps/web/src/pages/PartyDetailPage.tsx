import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useParty, usePartyBalanceSummary, usePartyStatement, usePartyOpenSales } from '../hooks/useParties';
import { usePayments } from '../hooks/usePayments';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { DataTable, type Column } from '../components/DataTable';
import { useFormatting } from '../hooks/useFormatting';
import { exportToCSV } from '../utils/csvExport';
import { PrintHeader } from '../components/print/PrintHeader';
import type { Payment, PartyStatementLine, PartyStatementGroup, OpenSale } from '../types';

type Tab = 'overview' | 'breakdown' | 'statement' | 'payments' | 'open-sales';

export default function PartyDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [activeTab, setActiveTab] = useState<Tab>('overview');
  const [statementFrom, setStatementFrom] = useState<string>('');
  const [statementTo, setStatementTo] = useState<string>('');

  const { data: party, isLoading: partyLoading } = useParty(id || '');
  const { data: balances, isLoading: balancesLoading } = usePartyBalanceSummary(id || '');
  const { data: statement, isLoading: statementLoading } = usePartyStatement(
    id || '',
    statementFrom || undefined,
    statementTo || undefined,
    activeTab === 'breakdown' ? 'cycle' : undefined
  );
  const { data: payments, isLoading: paymentsLoading } = usePayments({ party_id: id });
  const [openSalesAsOf, setOpenSalesAsOf] = useState<string>(new Date().toISOString().split('T')[0]);
  const { data: openSales, isLoading: openSalesLoading } = usePartyOpenSales(id || '', openSalesAsOf);
  const { formatMoney, formatDate } = useFormatting();

  const payableAmount = parseFloat(balances?.outstanding_total || '0');
  const receivableAmount = parseFloat(balances?.receivable_balance || '0');
  const advanceBalanceOutstanding = parseFloat(balances?.advance_balance_outstanding || '0');
  const isHariOrVendor = party?.party_types?.some(type => ['HARI', 'VENDOR'].includes(type)) || false;
  const isBuyer = party?.party_types?.some(type => type === 'BUYER') || false;

  if (partyLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!party) {
    return <div>Party not found</div>;
  }

  const renderOverview = () => {
    if (balancesLoading) {
      return <LoadingSpinner />;
    }

    return (
      <div className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="bg-white rounded-lg shadow p-6">
            <h3 className="text-sm font-medium text-gray-500 mb-2">Posted Payables</h3>
            <p className="text-2xl font-bold text-gray-900"><span className="tabular-nums">{formatMoney(balances?.allocated_payable_total || '0')}</span></p>
            <p className="text-xs text-gray-500 mt-1">From posted settlements</p>
          </div>
          <div className="bg-white rounded-lg shadow p-6">
            <h3 className="text-sm font-medium text-gray-500 mb-2">Paid</h3>
            <p className="text-2xl font-bold text-gray-900"><span className="tabular-nums">{formatMoney(balances?.paid_total || '0')}</span></p>
            <p className="text-xs text-gray-500 mt-1">Posted payments</p>
          </div>
          <div className="bg-white rounded-lg shadow p-6">
            <h3 className="text-sm font-medium text-gray-500 mb-2">Outstanding</h3>
            <p className="text-2xl font-bold text-red-600"><span className="tabular-nums">{formatMoney(balances?.outstanding_total || '0')}</span></p>
            <p className="text-xs text-gray-500 mt-1">Posted payables minus posted payments</p>
          </div>
        </div>
        
        <div className="bg-[#E6ECEA] border border-[#1F6F5C]/20 rounded-lg p-3">
          <p className="text-xs text-[#2D3A3A]">
            <strong>Note:</strong> Computed from posted settlements/allocations minus posted payments.
          </p>
        </div>

        <div className="bg-white rounded-lg shadow p-6">
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Quick Actions</h3>
          </div>
          <div className="flex space-x-4">
            <Link
              to={`/app/payments/new?direction=OUT&partyId=${party.id}`}
              className={`px-4 py-2 rounded-md font-medium ${
                payableAmount > 0
                  ? 'bg-[#1F6F5C] text-white hover:bg-[#1a5a4a]'
                  : 'bg-gray-300 text-gray-500 cursor-not-allowed'
              }`}
            >
              Pay OUT
            </Link>
            <Link
              to={`/app/payments/new?direction=IN&partyId=${party.id}`}
              className={`px-4 py-2 rounded-md font-medium ${
                receivableAmount > 0
                  ? 'bg-green-600 text-white hover:bg-green-700'
                  : 'bg-gray-300 text-gray-500 cursor-not-allowed'
              }`}
            >
              Receive IN
            </Link>
            {isBuyer && (
              <Link
                to={`/app/sales/new?buyerPartyId=${party.id}`}
                className="px-4 py-2 rounded-md font-medium bg-[#1F6F5C] text-white hover:bg-[#1a5a4a]"
              >
                Create Sale
              </Link>
            )}
            {isHariOrVendor && (
              <Link
                to={`/app/advances/new?partyId=${party.id}&type=${party.party_types?.includes('HARI') ? 'HARI_ADVANCE' : 'VENDOR_ADVANCE'}&direction=OUT`}
                className="px-4 py-2 rounded-md font-medium bg-purple-600 text-white hover:bg-purple-700"
              >
                Create Advance
              </Link>
            )}
          </div>
          {payableAmount > 0 && (
            <p className="mt-4 text-sm text-gray-600">
              Outstanding payable: <span className="tabular-nums">{formatMoney(balances?.outstanding_total || '0')}</span>. Click "Pay OUT" to record a payment.
            </p>
          )}
          {receivableAmount > 0 && (
            <p className="mt-4 text-sm text-gray-600">
              Outstanding receivable: <span className="tabular-nums">{formatMoney(balances?.receivable_balance || '0')}</span>. Click "Receive IN" to record a payment.
            </p>
          )}
          {isBuyer && receivableAmount === 0 && (
            <p className="mt-4 text-sm text-gray-600">
              No outstanding receivables. Click "Create Sale" to record a sale.
            </p>
          )}
        </div>

        {isHariOrVendor && advanceBalanceOutstanding > 0 && (
          <div className="bg-white rounded-lg shadow p-6">
            <h3 className="text-lg font-medium text-gray-900 mb-2">Outstanding Advances (they owe us)</h3>
            <p className="text-2xl font-bold text-green-600"><span className="tabular-nums">{formatMoney(balances?.advance_balance_outstanding || '0')}</span></p>
            <p className="text-xs text-gray-500 mt-1">
              Disbursed: <span className="tabular-nums">{formatMoney(balances?.advance_balance_disbursed || '0')}</span> | 
              Repaid: <span className="tabular-nums">{formatMoney(balances?.advance_balance_repaid || '0')}</span>
            </p>
          </div>
        )}

        {isBuyer && receivableAmount > 0 && (
          <div className="bg-white rounded-lg shadow p-6">
            <h3 className="text-lg font-medium text-gray-900 mb-2">Outstanding Receivables (they owe us)</h3>
            <p className="text-2xl font-bold text-green-600"><span className="tabular-nums">{formatMoney(balances?.receivable_balance || '0')}</span></p>
            <p className="text-xs text-gray-500 mt-1">
              Sales Total: <span className="tabular-nums">{formatMoney(balances?.receivable_sales_total || '0')}</span> | 
              Payments Received: <span className="tabular-nums">{formatMoney(balances?.receivable_payments_in_total || '0')}</span>
            </p>
          </div>
        )}

        {balances?.allocations && balances.allocations.length > 0 && (
          <div className="bg-white rounded-lg shadow p-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">Recent Allocations</h3>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-[#E6ECEA]">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Project</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {balances.allocations.map((allocation, idx) => (
                    <tr key={idx}>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{formatDate(allocation.posting_date)}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{allocation.project_name}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{allocation.allocation_type}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right"><span className="tabular-nums">{formatMoney(allocation.amount)}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    );
  };

  const renderBreakdown = () => {
    if (statementLoading) {
      return <LoadingSpinner />;
    }

    if (!statement || !statement.grouped_breakdown || statement.grouped_breakdown.length === 0) {
      return (
        <div className="bg-white rounded-lg shadow p-6">
          <p className="text-gray-500">No breakdown data available for this period.</p>
        </div>
      );
    }

    return (
      <div className="space-y-4">
        <div className="bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-medium text-gray-900 mb-4">Breakdown by Crop Cycle</h3>
          
          {statement.summary && parseFloat(statement.summary.unassigned_payments_total || '0') > 0 && (
            <div className="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
              <div className="flex items-start">
                <div className="flex-shrink-0">
                  <svg className="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                  </svg>
                </div>
                <div className="ml-3 flex-1">
                  <h4 className="text-sm font-medium text-yellow-800">
                    Unassigned Payments: <span className="tabular-nums">{formatMoney(statement.summary.unassigned_payments_total)}</span>
                  </h4>
                  <p className="mt-1 text-sm text-yellow-700">
                    These payments reduce total outstanding but aren't assigned to any crop cycle/project in Breakdown because they're not linked to a settlement.
                  </p>
                </div>
              </div>
            </div>
          )}
          
          <div className="space-y-4">
            {statement.grouped_breakdown.map((group: PartyStatementGroup, idx: number) => (
              <div key={idx} className="border border-gray-200 rounded-lg p-4">
                <div className="flex justify-between items-center mb-2">
                  <h4 className="font-medium text-gray-900">
                    {group.crop_cycle_name || `Crop Cycle ${idx + 1}`}
                  </h4>
                  <span className="text-sm font-medium text-gray-600">
                    Net: <span className="tabular-nums">{formatMoney(group.net_outstanding)}</span>
                  </span>
                </div>
                <div className="grid grid-cols-3 gap-4 text-sm">
                  <div>
                    <span className="text-gray-500">Allocations:</span>
                    <span className="ml-2 font-medium"><span className="tabular-nums">{formatMoney(group.total_allocations)}</span></span>
                  </div>
                  <div>
                    <span className="text-gray-500">Payments Out:</span>
                    <span className="ml-2 font-medium"><span className="tabular-nums">{formatMoney(group.total_payments_out)}</span></span>
                  </div>
                  <div>
                    <span className="text-gray-500">Payments In:</span>
                    <span className="ml-2 font-medium"><span className="tabular-nums">{formatMoney(group.total_payments_in)}</span></span>
                  </div>
                </div>
                {group.projects && group.projects.length > 0 && (
                  <div className="mt-4 pl-4 border-l-2 border-gray-200">
                    <h5 className="text-sm font-medium text-gray-700 mb-2">Projects:</h5>
                    {group.projects.map((project: PartyStatementGroup, pIdx: number) => (
                      <div key={pIdx} className="mb-2 text-sm">
                        <span className="font-medium">{project.project_name}</span>
                        <span className="ml-2 text-gray-600">Net: <span className="tabular-nums">{formatMoney(project.net_outstanding)}</span></span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      </div>
    );
  };

  const renderStatement = () => {
    if (statementLoading) {
      return <LoadingSpinner />;
    }

    const statementColumns: Column<PartyStatementLine>[] = [
      { header: 'Date', accessor: (row) => formatDate(row.date) },
      { header: 'Type', accessor: 'type' },
      { header: 'Description', accessor: 'description' },
      { header: 'Reference', accessor: 'reference' },
      {
        header: 'Amount',
        accessor: (row) => (
          <span className={row.direction === '+' ? 'text-green-600' : 'text-red-600'}>
            {row.direction}<span className="tabular-nums">{formatMoney(row.amount)}</span>
          </span>
        ),
      },
    ];

    return (
      <div className="space-y-4">
        {/* Print Template - Statement */}
        {statement && statement.line_items && statement.line_items.length > 0 && (
          <div className="print-document hidden">
            <PrintHeader
              title="Account Statement"
              subtitle={party.name}
              metaLeft={statementFrom && statementTo ? `From ${formatDate(statementFrom)} to ${formatDate(statementTo)}` : undefined}
            />
            
            <div className="print-document-meta">
              <div>
                <dl>
                  <dt>Party:</dt>
                  <dd>{party.name}</dd>
                  <dt>Party Types:</dt>
                  <dd>{party.party_types.join(', ')}</dd>
                </dl>
              </div>
              <div>
                {statement.summary && (
                  <dl>
                    <dt>Closing Payable:</dt>
                    <dd className="tabular-nums">{formatMoney(statement.summary.closing_balance_payable)}</dd>
                    <dt>Closing Receivable:</dt>
                    <dd className="tabular-nums">{formatMoney(statement.summary.closing_balance_receivable)}</dd>
                  </dl>
                )}
              </div>
            </div>

            <div className="print-line-items">
              <table className="min-w-full">
                <thead>
                  <tr>
                    <th className="text-left">Date</th>
                    <th className="text-left">Type</th>
                    <th className="text-left">Description</th>
                    <th className="text-left">Reference</th>
                    <th className="text-right">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {statement.line_items.map((line, idx) => (
                    <tr key={`${line.date}-${line.type}-${line.reference}-${idx}`}>
                      <td>{formatDate(line.date)}</td>
                      <td>{line.type}</td>
                      <td>{line.description}</td>
                      <td>{line.reference}</td>
                      <td className="text-right tabular-nums">
                        {line.direction}{formatMoney(line.amount)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {statement.summary && (
              <div className="print-totals">
                <div className="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <div className="flex justify-between mb-1">
                      <span>Total Allocations:</span>
                      <span className="tabular-nums">{formatMoney(statement.summary.total_allocations_increasing_balance)}</span>
                    </div>
                    <div className="flex justify-between mb-1">
                      <span>Payments Out:</span>
                      <span className="tabular-nums">{formatMoney(statement.summary.total_payments_out)}</span>
                    </div>
                    <div className="flex justify-between mb-1">
                      <span>Payments In:</span>
                      <span className="tabular-nums">{formatMoney(statement.summary.total_payments_in)}</span>
                    </div>
                  </div>
                  <div>
                    <div className="flex justify-between mb-1">
                      <span className="font-semibold">Closing Payable:</span>
                      <span className="font-semibold tabular-nums text-red-600">{formatMoney(statement.summary.closing_balance_payable)}</span>
                    </div>
                    <div className="flex justify-between mb-1">
                      <span className="font-semibold">Closing Receivable:</span>
                      <span className="font-semibold tabular-nums text-green-600">{formatMoney(statement.summary.closing_balance_receivable)}</span>
                    </div>
                  </div>
                </div>
              </div>
            )}
          </div>
        )}

        <div className="bg-white rounded-lg shadow p-6">
          <div className="flex space-x-4 mb-4 no-print">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">From</label>
              <input
                type="date"
                value={statementFrom}
                onChange={(e) => setStatementFrom(e.target.value)}
                className="px-3 py-2 border border-gray-300 rounded-md"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">To</label>
              <input
                type="date"
                value={statementTo}
                onChange={(e) => setStatementTo(e.target.value)}
                className="px-3 py-2 border border-gray-300 rounded-md"
              />
            </div>
          </div>
          <div className="mb-4 flex justify-end gap-2 no-print">
            {statement && statement.line_items && statement.line_items.length > 0 && (
              <>
                <button
                  onClick={() => window.print()}
                  className="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 text-sm"
                >
                  Print Statement
                </button>
                <button
                  onClick={() => {
                    exportToCSV(
                      statement.line_items,
                      `party-statement-${party.name}-${statementFrom || 'all'}-${statementTo || 'all'}.csv`,
                      ['date', 'type', 'reference', 'description', 'amount', 'direction']
                    );
                  }}
                  className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 text-sm"
                >
                  Export CSV
                </button>
              </>
            )}
          </div>
          {statement && statement.summary && (
            <div className="mb-4 p-4 bg-gray-50 rounded-lg">
              <h4 className="font-medium text-gray-900 mb-2">Summary</h4>
              <div className="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                <div>
                  <span className="text-gray-500">Total Allocations:</span>
                  <span className="ml-2 font-medium"><span className="tabular-nums">{formatMoney(statement.summary.total_allocations_increasing_balance)}</span></span>
                </div>
                <div>
                  <span className="text-gray-500">Payments Out:</span>
                  <span className="ml-2 font-medium"><span className="tabular-nums">{formatMoney(statement.summary.total_payments_out)}</span></span>
                </div>
                <div>
                  <span className="text-gray-500">Payments In:</span>
                  <span className="ml-2 font-medium"><span className="tabular-nums">{formatMoney(statement.summary.total_payments_in)}</span></span>
                </div>
                <div>
                  <span className="text-gray-500">Closing Payable:</span>
                  <span className="ml-2 font-medium text-red-600"><span className="tabular-nums">{formatMoney(statement.summary.closing_balance_payable)}</span></span>
                </div>
                <div>
                  <span className="text-gray-500">Closing Receivable:</span>
                  <span className="ml-2 font-medium text-green-600"><span className="tabular-nums">{formatMoney(statement.summary.closing_balance_receivable)}</span></span>
                </div>
                {parseFloat(statement.summary.unassigned_payments_total || '0') > 0 && (
                  <div>
                    <span className="text-gray-500">Unassigned Payments:</span>
                    <span className="ml-2 font-medium text-yellow-600"><span className="tabular-nums">{formatMoney(statement.summary.unassigned_payments_total)}</span></span>
                  </div>
                )}
              </div>
            </div>
          )}
          {statement && statement.line_items && statement.line_items.length > 0 ? (
            <DataTable data={statement.line_items.map((r, i) => ({ ...r, id: `${r.date}-${r.type}-${r.reference}-${i}` }))} columns={statementColumns} />
          ) : (
            <p className="text-gray-500">No statement lines for this period.</p>
          )}
        </div>
      </div>
    );
  };

  const renderPayments = () => {
    if (paymentsLoading) {
      return <LoadingSpinner />;
    }

    const paymentColumns: Column<Payment>[] = [
      { header: 'Date', accessor: (row) => formatDate(row.payment_date) },
      { header: 'Direction', accessor: 'direction' },
      { header: 'Amount', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.amount)}</span> },
      { header: 'Method', accessor: 'method' },
      { header: 'Status', accessor: 'status' },
      {
        header: 'Actions',
        accessor: (row) => (
          <Link
            to={`/app/payments/${row.id}`}
            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            View
          </Link>
        ),
      },
    ];

    return (
      <div className="bg-white rounded-lg shadow p-6">
        <div className="flex justify-between items-center mb-4">
          <h3 className="text-lg font-medium text-gray-900">Payments</h3>
          <Link
            to={`/app/payments?party_id=${party.id}`}
            className="text-sm text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            View all →
          </Link>
        </div>
        {payments && payments.length > 0 ? (
          <DataTable data={payments} columns={paymentColumns} />
        ) : (
          <p className="text-gray-500">No payments found for this party.</p>
        )}
      </div>
    );
  };

  const renderOpenSales = () => {
    if (openSalesLoading) {
      return <LoadingSpinner />;
    }

    const columns: Column<OpenSale>[] = [
      { header: 'Sale Ref', accessor: (row) => row.sale_no || 'N/A' },
      { header: 'Date', accessor: (row) => formatDate(row.posting_date) },
      { header: 'Due Date', accessor: (row) => formatDate(row.due_date) },
      { header: 'Amount', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.amount)}</span> },
      { header: 'Paid', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.allocated)}</span> },
      {
        header: 'Outstanding',
        accessor: (row) => (
          <span className="font-semibold text-red-600"><span className="tabular-nums">{formatMoney(row.outstanding)}</span></span>
        )
      },
      {
        header: 'Actions',
        accessor: (row) => (
          <Link
            to={`/app/payments/new?direction=IN&partyId=${id}&amount=${row.outstanding}`}
            className="text-[#1F6F5C] hover:text-[#1a5a4a] text-sm"
          >
            Receive Payment
          </Link>
        ),
      },
    ];

    return (
      <div className="space-y-4">
        <div className="bg-white rounded-lg shadow p-6">
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Open Sales (Outstanding Receivables)</h3>
            <div className="flex items-center space-x-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">As Of</label>
                <input
                  type="date"
                  value={openSalesAsOf}
                  onChange={(e) => setOpenSalesAsOf(e.target.value)}
                  className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                />
              </div>
            </div>
          </div>
          {openSales && openSales.length > 0 ? (
            <DataTable data={openSales.map((o) => ({ ...o, id: o.sale_id }))} columns={columns} />
          ) : (
            <p className="text-gray-500">No open sales (all receivables are paid).</p>
          )}
        </div>
      </div>
    );
  };

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/parties" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Parties
        </Link>
        <div className="flex justify-between items-start mt-2">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{party.name}</h1>
            <p className="text-sm text-gray-500 mt-1">
              {party.party_types.join(', ')}
            </p>
          </div>
        </div>
      </div>

      <div className="border-b border-gray-200 mb-6">
        <nav className="-mb-px flex space-x-8">
          <button
            onClick={() => setActiveTab('overview')}
            className={`py-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'overview'
                ? 'border-[#1F6F5C] text-[#1F6F5C]'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            Overview
          </button>
          <button
            onClick={() => setActiveTab('breakdown')}
            className={`py-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'breakdown'
                ? 'border-[#1F6F5C] text-[#1F6F5C]'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            Breakdown
          </button>
          <button
            onClick={() => setActiveTab('statement')}
            className={`py-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'statement'
                ? 'border-[#1F6F5C] text-[#1F6F5C]'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            Statement
          </button>
          <button
            onClick={() => setActiveTab('payments')}
            className={`py-4 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'payments'
                ? 'border-[#1F6F5C] text-[#1F6F5C]'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            Payments
          </button>
          {isBuyer && (
            <button
              onClick={() => setActiveTab('open-sales')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'open-sales'
                  ? 'border-[#1F6F5C] text-[#1F6F5C]'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Open Sales
            </button>
          )}
        </nav>
      </div>

      {activeTab === 'overview' && renderOverview()}
      {activeTab === 'breakdown' && renderBreakdown()}
      {activeTab === 'statement' && renderStatement()}
      {activeTab === 'payments' && renderPayments()}
      {activeTab === 'open-sales' && isBuyer && renderOpenSales()}
    </div>
  );
}

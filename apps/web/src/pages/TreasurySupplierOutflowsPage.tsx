import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { PageContainer } from '../components/PageContainer';
import { useFormatting } from '../hooks/useFormatting';
import { useParties } from '../hooks/useParties';
import type { TreasurySupplierOutflowsReport } from '../types';
import { ReportMetadataBlock } from '../components/report/ReportMetadataBlock';
import { ReportEmptyStateCard, ReportErrorState, ReportLoadingState } from '../components/report';
import { FilterBar, FilterField, FilterGrid } from '../components/FilterBar';
import { DataTable, type Column } from '../components/DataTable';

export default function TreasurySupplierOutflowsPage() {
  const defaultTo = new Date().toISOString().split('T')[0];
  const defaultFrom = new Date(new Date().setMonth(new Date().getMonth() - 1)).toISOString().split('T')[0];
  const [from, setFrom] = useState(defaultFrom);
  const [to, setTo] = useState(defaultTo);
  const [partyId, setPartyId] = useState('');
  const [sourceAccountId, setSourceAccountId] = useState('');
  const { formatMoney, formatDate } = useFormatting();
  const { data: parties } = useParties();

  const { data: accounts } = useQuery({
    queryKey: ['accounts', 'treasury-filter'],
    queryFn: () => apiClient.get<Array<{ id: string; code: string; name: string; type: string }>>('/api/accounts'),
  });
  const assetAccounts = (accounts ?? []).filter((a) => (a.type || '').toLowerCase() === 'asset');

  const { data: report, isLoading, error } = useQuery<TreasurySupplierOutflowsReport>({
    queryKey: ['reports', 'treasury-supplier-outflows', from, to, partyId, sourceAccountId],
    queryFn: () => {
      const params = new URLSearchParams();
      params.set('from', from);
      params.set('to', to);
      if (partyId) params.set('party_id', partyId);
      if (sourceAccountId) params.set('source_account_id', sourceAccountId);
      return apiClient.get<TreasurySupplierOutflowsReport>(
        `/api/reports/treasury-supplier-outflows?${params.toString()}`,
      );
    },
  });

  type Row = TreasurySupplierOutflowsReport['by_supplier'][0];
  const columns: Column<Row>[] = [
    {
      header: 'Supplier',
      accessor: (row) => (
        <Link to={`/app/parties/${row.party_id}`} className="text-[#1F6F5C] hover:underline font-medium">
          {row.supplier_name || row.party_id}
        </Link>
      ),
    },
    {
      header: 'Posted paid',
      accessor: (row) => <span className="tabular-nums text-right block font-semibold">{formatMoney(row.total_paid)}</span>,
    },
  ];

  return (
    <PageContainer className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Treasury — supplier outflows</h1>
        <p className="text-sm text-gray-600 mt-1">
          Posted outgoing supplier payments in the period, optional filters by supplier or pay-from GL account.
        </p>
      </div>

      <div className="no-print">
        <div className="bg-white rounded-lg shadow p-4">
          <FilterBar>
            <FilterGrid className="lg:grid-cols-2 xl:grid-cols-4">
              <FilterField label="From *">
                <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
              </FilterField>
              <FilterField label="To *">
                <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
              </FilterField>
              <FilterField label="Supplier">
                <select
                  value={partyId}
                  onChange={(e) => setPartyId(e.target.value)}
                  className="w-full rounded border border-gray-300 px-2 py-2 text-sm"
                >
                  <option value="">All</option>
                  {(parties ?? []).map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name}
                    </option>
                  ))}
                </select>
              </FilterField>
              <FilterField label="Pay-from account">
                <select
                  value={sourceAccountId}
                  onChange={(e) => setSourceAccountId(e.target.value)}
                  className="w-full rounded border border-gray-300 px-2 py-2 text-sm"
                >
                  <option value="">All asset accounts</option>
                  {assetAccounts.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.code} — {a.name}
                    </option>
                  ))}
                </select>
              </FilterField>
            </FilterGrid>
          </FilterBar>
        </div>
      </div>

      <div className="no-print">
        <ReportMetadataBlock reportingPeriodRange={`${formatDate(from)} – ${formatDate(to)}`} />
      </div>

      {report?.notes && report.notes.length > 0 && (
        <ul className="text-sm text-gray-600 list-disc pl-5 space-y-1 no-print">
          {report.notes.map((n) => (
            <li key={n}>{n}</li>
          ))}
        </ul>
      )}

      {isLoading && <ReportLoadingState label="Loading…" className="no-print" />}
      {error && <ReportErrorState error={error} className="no-print" />}

      {!isLoading && !error && report && (
        <div className="space-y-4">
          <div className="bg-white rounded-lg shadow p-4 flex flex-wrap items-baseline gap-4">
            <span className="text-sm text-gray-600">Total posted (filtered)</span>
            <span className="text-2xl font-bold tabular-nums">{formatMoney(report.total_paid)}</span>
          </div>
          {report.by_supplier.length === 0 ? (
            <ReportEmptyStateCard message="No posted supplier payments in this range." />
          ) : (
            <div className="bg-white rounded-lg shadow overflow-hidden">
              <DataTable<Row & { id: string }>
                data={report.by_supplier.map((r) => ({ ...r, id: r.party_id }))}
                columns={columns}
              />
            </div>
          )}
        </div>
      )}
    </PageContainer>
  );
}

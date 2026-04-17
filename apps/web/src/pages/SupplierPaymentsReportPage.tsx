import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { PageContainer } from '../components/PageContainer';
import { useFormatting } from '../hooks/useFormatting';
import { useParties } from '../hooks/useParties';
import type { SupplierPaymentsHistoryReport } from '../types';
import { ReportMetadataBlock } from '../components/report/ReportMetadataBlock';
import { ReportEmptyStateCard, ReportErrorState, ReportLoadingState } from '../components/report';
import { FilterBar, FilterField, FilterGrid } from '../components/FilterBar';
import { DataTable, type Column } from '../components/DataTable';

export default function SupplierPaymentsReportPage() {
  const defaultTo = new Date().toISOString().split('T')[0];
  const defaultFrom = new Date(new Date().setMonth(new Date().getMonth() - 3)).toISOString().split('T')[0];
  const [from, setFrom] = useState(defaultFrom);
  const [to, setTo] = useState(defaultTo);
  const [partyId, setPartyId] = useState('');
  const { formatMoney, formatDate } = useFormatting();
  const { data: parties } = useParties();

  const { data: report, isLoading, error } = useQuery<SupplierPaymentsHistoryReport>({
    queryKey: ['reports', 'supplier-payments', from, to, partyId],
    queryFn: () => {
      const params = new URLSearchParams();
      if (from) params.set('from', from);
      if (to) params.set('to', to);
      if (partyId) params.set('party_id', partyId);
      return apiClient.get<SupplierPaymentsHistoryReport>(`/api/reports/supplier-payments?${params.toString()}`);
    },
  });

  type Row = SupplierPaymentsHistoryReport['rows'][0];
  const columns: Column<Row>[] = useMemo(
    () => [
      {
        header: 'Date',
        accessor: (row) => formatDate(row.payment_date),
      },
      {
        header: 'Supplier',
        accessor: (row) => (
          <Link to={`/app/parties/${row.party_id}`} className="text-[#1F6F5C] hover:underline">
            {row.supplier_name || row.party_id}
          </Link>
        ),
      },
      {
        header: 'Amount',
        accessor: (row) => <span className="tabular-nums text-right block font-medium">{formatMoney(row.amount)}</span>,
      },
      { header: 'Status', accessor: (row) => row.status },
      { header: 'Method', accessor: (row) => row.method },
      {
        header: 'Pay from',
        accessor: (row) =>
          row.source_account ? (
            <span className="text-xs">
              {row.source_account.code} — {row.source_account.name}
            </span>
          ) : (
            <span className="text-gray-400 text-xs">Default</span>
          ),
      },
      {
        header: 'Reference',
        accessor: (row) => row.reference || '—',
      },
      {
        header: 'Payment',
        accessor: (row) => (
          <Link to={`/app/payments/${row.payment_id}`} className="text-[#1F6F5C] hover:underline text-sm">
            View
          </Link>
        ),
      },
    ],
    [formatDate, formatMoney],
  );

  return (
    <PageContainer className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Supplier payments</h1>
        <p className="text-sm text-gray-600 mt-1">
          Outgoing supplier payments (cash/bank, excluding wages). Posted rows include allocation summary.
        </p>
      </div>

      <div className="no-print">
        <div className="bg-white rounded-lg shadow p-4">
          <FilterBar>
            <FilterGrid className="lg:grid-cols-2 xl:grid-cols-4">
              <FilterField label="From">
                <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
              </FilterField>
              <FilterField label="To">
                <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
              </FilterField>
              <FilterField label="Supplier">
                <select
                  value={partyId}
                  onChange={(e) => setPartyId(e.target.value)}
                  className="w-full rounded border border-gray-300 px-2 py-2 text-sm"
                >
                  <option value="">All suppliers</option>
                  {(parties ?? []).map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name}
                    </option>
                  ))}
                </select>
              </FilterField>
            </FilterGrid>
          </FilterBar>
        </div>
      </div>

      <div className="no-print">
        <ReportMetadataBlock reportingPeriodRange={from && to ? `${formatDate(from)} – ${formatDate(to)}` : undefined} />
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
        <>
          {report.rows.length === 0 ? (
            <ReportEmptyStateCard message="No supplier payments in this range." />
          ) : (
            <div className="bg-white rounded-lg shadow overflow-hidden">
              <DataTable<Row & { id: string }>
                data={report.rows.map((r) => ({ ...r, id: r.payment_id }))}
                columns={columns}
              />
            </div>
          )}
        </>
      )}
    </PageContainer>
  );
}

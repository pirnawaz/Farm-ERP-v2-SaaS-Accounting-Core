import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { PageContainer } from '../components/PageContainer';
import { useFormatting } from '../hooks/useFormatting';
import type { APSupplierOutstandingReport } from '../types';
import { ReportMetadataBlock } from '../components/report/ReportMetadataBlock';
import { ReportEmptyStateCard, ReportErrorState, ReportLoadingState } from '../components/report';
import { FilterBar, FilterField, FilterGrid } from '../components/FilterBar';
import { DataTable, type Column } from '../components/DataTable';

export default function APSupplierOutstandingPage() {
  const [asOfDate, setAsOfDate] = useState<string>(new Date().toISOString().split('T')[0]);
  const { formatMoney, formatDate } = useFormatting();

  const { data: report, isLoading, error } = useQuery<APSupplierOutstandingReport>({
    queryKey: ['ap-supplier-outstanding', asOfDate],
    queryFn: () => {
      const params = new URLSearchParams();
      params.append('as_of', asOfDate);
      return apiClient.get<APSupplierOutstandingReport>(`/api/reports/ap-supplier-outstanding?${params.toString()}`);
    },
  });

  type Row = APSupplierOutstandingReport['rows'][0];
  const columns: Column<Row>[] = [
    {
      header: 'Supplier',
      accessor: (row) => (
        <Link to={`/app/parties/${row.supplier_party_id}`} className="text-[#1F6F5C] hover:underline font-medium">
          {row.supplier_name}
        </Link>
      ),
    },
    { header: 'Open GRN', accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.open_grn_outstanding)}</span> },
    {
      header: 'Open bills (SIs)',
      accessor: (row) => (
        <span className="tabular-nums text-right block">{formatMoney(row.open_supplier_invoice_outstanding)}</span>
      ),
    },
    {
      header: 'Docs open',
      accessor: (row) => <span className="tabular-nums text-right block font-medium">{formatMoney(row.documents_open_total)}</span>,
    },
    {
      header: 'Credits (posted)',
      accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.posted_credit_notes_total)}</span>,
    },
    {
      header: 'Unlinked credits',
      accessor: (row) => <span className="tabular-nums text-right block">{formatMoney(row.posted_unlinked_credits)}</span>,
    },
    {
      header: 'Net (after unlinked)',
      accessor: (row) => (
        <span className="tabular-nums text-right block font-semibold">{formatMoney(row.net_after_unlinked_credits)}</span>
      ),
    },
  ];

  return (
    <PageContainer className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Supplier AP outstanding</h1>
        <p className="text-sm text-gray-600 mt-1">
          Posted supplier invoices and GRN payables, with posted credit notes (linked credits already reduce open bill
          amounts).
        </p>
      </div>

      <div className="no-print">
        <div className="bg-white rounded-lg shadow p-4">
          <FilterBar>
            <FilterGrid className="lg:grid-cols-2 xl:grid-cols-2">
              <FilterField label="As of">
                <input type="date" value={asOfDate} onChange={(e) => setAsOfDate(e.target.value)} />
              </FilterField>
            </FilterGrid>
          </FilterBar>
        </div>
      </div>

      <div className="no-print">
        <ReportMetadataBlock asOfDate={formatDate(asOfDate)} />
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
        <div className="bg-white rounded-lg shadow p-6">
          {report.rows?.length ? (
            <DataTable data={report.rows.map((r) => ({ ...r, id: r.supplier_party_id }))} columns={columns} />
          ) : (
            <ReportEmptyStateCard message="No supplier AP rows for this date." />
          )}
        </div>
      )}
    </PageContainer>
  );
}

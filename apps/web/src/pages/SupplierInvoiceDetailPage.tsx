import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { apiClient } from '@farm-erp/shared';
import { PageContainer } from '../components/PageContainer';
import { PageHeader } from '../components/PageHeader';
import { useFormatting } from '../hooks/useFormatting';
import { useTenantSettings } from '../hooks/useTenantSettings';
import type { SupplierInvoiceDetail, SupplierStatementResponse } from '../types';

export default function SupplierInvoiceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { formatMoney, formatDate } = useFormatting();
  const { settings } = useTenantSettings();

  const { data: invoice, isLoading, error } = useQuery({
    queryKey: ['supplier-invoice', id],
    queryFn: () => apiClient.get<SupplierInvoiceDetail>(`/api/supplier-invoices/${id}`),
    enabled: !!id,
  });

  const functionalCc = (settings?.currency_code || 'GBP').toUpperCase();
  const txCc = (invoice?.currency_code || functionalCc).toUpperCase();
  const isForeign = invoice ? txCc !== functionalCc : false;

  const partyId = invoice?.party_id;

  const { data: statement } = useQuery({
    queryKey: ['supplier-statement', partyId, id],
    queryFn: () => {
      const params = new URLSearchParams();
      const to = new Date().toISOString().split('T')[0];
      const from = new Date(new Date().setFullYear(new Date().getFullYear() - 1)).toISOString().split('T')[0];
      params.set('from', from);
      params.set('to', to);
      return apiClient.get<SupplierStatementResponse>(`/api/parties/${partyId}/supplier-statement?${params.toString()}`);
    },
    enabled: !!partyId && !!invoice,
  });

  if (!id) {
    return null;
  }

  if (isLoading) {
    return (
      <PageContainer>
        <div className="text-gray-600 p-4">Loading…</div>
      </PageContainer>
    );
  }

  if (error || !invoice) {
    return (
      <PageContainer>
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{error instanceof Error ? error.message : 'Not found'}</p>
          <Link to="/app/accounting/supplier-invoices" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
            ← Back to supplier invoices
          </Link>
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title={invoice.reference_no ? `Supplier invoice · ${invoice.reference_no}` : 'Supplier invoice'}
        backTo="/app/accounting/supplier-invoices"
        breadcrumbs={[
          { label: 'Reports', to: '/app/reports' },
          { label: 'Supplier invoices', to: '/app/accounting/supplier-invoices' },
          { label: 'Detail' },
        ]}
      />

      {(invoice.status === 'POSTED' || invoice.status === 'PAID') && (
        <div
          className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
          role="status"
        >
          This invoice is <strong>{invoice.status}</strong> — details are read-only in the app. Changes require
          support workflows; posting or edits are enforced on the server.
        </div>
      )}

      <div className="flex flex-wrap gap-4 text-sm text-gray-600">
        <span>
          Status: <strong className="text-gray-900">{invoice.status}</strong>
        </span>
        {invoice.party && (
          <span>
            Supplier:{' '}
            <Link to={`/app/parties/${invoice.party_id}`} className="text-[#1F6F5C] font-semibold hover:underline">
              {invoice.party.name}
            </Link>
          </span>
        )}
        {invoice.project?.name && (
          <span>
            Project: <strong className="text-gray-900">{invoice.project.name}</strong>
          </span>
        )}
        <span>
          Transaction currency: <strong className="text-gray-900">{txCc}</strong>
        </span>
        <span>
          Functional (reporting): <strong className="text-gray-900">{functionalCc}</strong>
          {isForeign && invoice.posting_group && (
            <span className="text-gray-500"> — GL uses rate at post date for {functionalCc} equivalents</span>
          )}
        </span>
        {invoice.posting_group?.posting_date && (
          <span>
            Posted: <strong className="text-gray-900">{formatDate(invoice.posting_group.posting_date)}</strong>
          </span>
        )}
      </div>

      <section className="bg-white rounded-lg shadow p-6">
        <h2 className="text-lg font-semibold mb-1">Amounts</h2>
        <p className="text-sm text-gray-600 mb-4">
          Figures below are in <strong>{txCc}</strong> (document / transaction currency). Posted ledger entries also
          carry <strong>{functionalCc}</strong> base amounts for reporting.
        </p>
        <dl className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
          <div>
            <dt className="text-gray-500">Subtotal</dt>
            <dd className="mt-1 font-medium tabular-nums">{invoice.subtotal_amount != null ? formatMoney(invoice.subtotal_amount) : '—'}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Tax</dt>
            <dd className="mt-1 font-medium tabular-nums">{invoice.tax_amount != null ? formatMoney(invoice.tax_amount) : '—'}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Total</dt>
            <dd className="mt-1 font-semibold tabular-nums">{invoice.total_amount != null ? formatMoney(invoice.total_amount) : '—'}</dd>
          </div>
        </dl>
      </section>

      {invoice.grn && (
        <section className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold mb-2">Linked GRN</h2>
          <Link to={`/app/inventory/grns/${invoice.grn_id}`} className="text-[#1F6F5C] hover:underline">
            {invoice.grn.doc_no || invoice.grn_id}
          </Link>
        </section>
      )}

      <section className="bg-white rounded-lg shadow overflow-hidden">
        <h2 className="text-lg font-semibold p-6 pb-0">Lines</h2>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Line total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {invoice.lines?.map((line) => (
                <tr key={line.id}>
                  <td className="px-4 py-2 text-sm tabular-nums">{line.line_no ?? '—'}</td>
                  <td className="px-4 py-2 text-sm">{line.description ?? '—'}</td>
                  <td className="px-4 py-2 text-sm text-right tabular-nums">{line.qty ?? '—'}</td>
                  <td className="px-4 py-2 text-sm text-right tabular-nums">
                    {line.line_total != null ? formatMoney(line.line_total) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {statement && (
        <section className="bg-white rounded-lg shadow p-6 space-y-4">
          <h2 className="text-lg font-semibold">Supplier statement (preview)</h2>
          <p className="text-sm text-gray-600">
            Period {statement.period.from} → {statement.period.to}. Subledger outstanding at period end:{' '}
            <span className="font-semibold tabular-nums">{formatMoney(statement.reconciliation.subledger_outstanding_at_to)}</span>
            . Statement running balance:{' '}
            <span className="font-semibold tabular-nums">{formatMoney(statement.reconciliation.statement_balance_at_to)}</span>
            {statement.reconciliation.delta !== '0.00' && (
              <>
                {' '}
                (delta {formatMoney(statement.reconciliation.delta)} — e.g. unapplied supplier payments)
              </>
            )}
            .
          </p>
          <div className="overflow-x-auto max-h-80 border border-gray-100 rounded">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50 sticky top-0">
                <tr>
                  <th className="px-3 py-2 text-left">Date</th>
                  <th className="px-3 py-2 text-left">Description</th>
                  <th className="px-3 py-2 text-right">Debit</th>
                  <th className="px-3 py-2 text-right">Credit</th>
                  <th className="px-3 py-2 text-right">Balance</th>
                </tr>
              </thead>
              <tbody>
                {statement.lines.map((line, i) => (
                  <tr key={`${line.source_type}-${line.source_id}-${i}`} className="border-t border-gray-100">
                    <td className="px-3 py-1.5 whitespace-nowrap">{formatDate(line.posting_date)}</td>
                    <td className="px-3 py-1.5">{line.description}</td>
                    <td className="px-3 py-1.5 text-right tabular-nums">{line.debit !== '0.00' ? formatMoney(line.debit) : '—'}</td>
                    <td className="px-3 py-1.5 text-right tabular-nums">{line.credit !== '0.00' ? formatMoney(line.credit) : '—'}</td>
                    <td className="px-3 py-1.5 text-right tabular-nums font-medium">{formatMoney(line.running_balance)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </PageContainer>
  );
}

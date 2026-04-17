import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { apiClient } from '@farm-erp/shared';
import { PageContainer } from '../../components/PageContainer';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import type { SupplierInvoiceListItem } from '../../types';

function scopeLabel(r: SupplierInvoiceListItem): string {
  if (r.cost_center_id && !r.project_id) {
    return r.cost_center?.name ? `Cost center · ${r.cost_center.name}` : 'Cost center';
  }
  if (r.project_id) {
    return r.project?.name ? `Project · ${r.project.name}` : 'Project';
  }
  return '—';
}

export default function BillsPage() {
  const { formatMoney, formatDate } = useFormatting();

  const { data: rows, isLoading, error } = useQuery({
    queryKey: ['supplier-invoices', 'billing_scope', 'farm'],
    queryFn: () => apiClient.get<SupplierInvoiceListItem[]>('/api/supplier-invoices?billing_scope=farm&limit=100'),
  });

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title="Bills"
        backTo="/app/reports"
        breadcrumbs={[{ label: 'Reports', to: '/app/reports' }, { label: 'Bills' }]}
      />
      <p className="text-sm text-gray-600 -mt-2">
        Farm overhead and other non-crop supplier bills (utilities, rent, insurance, admin). Each bill is saved as a
        draft, then posted explicitly when you are ready. Scope must be a <strong>cost center</strong> — project
        costs stay on{' '}
        <Link to="/app/accounting/supplier-invoices" className="text-[#1F6F5C] hover:underline">
          supplier invoices
        </Link>{' '}
        or your usual crop and inventory flows.
      </p>

      <div className="flex flex-wrap gap-3 text-sm">
        <Link
          to="/app/accounting/bills/new"
          className="inline-flex items-center rounded-lg bg-[#1F6F5C] px-4 py-2 font-medium text-white hover:bg-[#185647]"
        >
          New bill
        </Link>
        <Link
          to="/app/accounting/cost-centers"
          className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 font-medium text-gray-800 hover:bg-gray-50"
        >
          Cost centers
        </Link>
      </div>

      <div className="bg-white rounded-lg shadow overflow-hidden">
        {isLoading && <div className="p-8 text-center text-gray-500">Loading…</div>}
        {error && (
          <div className="p-6 text-red-700">{error instanceof Error ? error.message : 'Failed to load bills'}</div>
        )}
        {!isLoading && !error && rows && rows.length === 0 && (
          <div className="p-8 text-center text-gray-500">No farm bills yet. Create one to record overhead expenses.</div>
        )}
        {!isLoading && !error && rows && rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Belongs to</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Bill date</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <Link
                        to={`/app/accounting/supplier-invoices/${r.id}`}
                        className="text-[#1F6F5C] font-medium hover:underline"
                      >
                        {r.reference_no || r.id.slice(0, 8)}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-900">{r.party?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-sm text-gray-700">{scopeLabel(r)}</td>
                    <td className="px-4 py-3 text-sm">{r.status}</td>
                    <td className="px-4 py-3 text-sm text-right tabular-nums">
                      {r.total_amount != null ? formatMoney(r.total_amount) : '—'}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">{r.invoice_date ? formatDate(r.invoice_date) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </PageContainer>
  );
}

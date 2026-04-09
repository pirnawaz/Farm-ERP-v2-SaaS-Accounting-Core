import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { apiClient } from '@farm-erp/shared';
import { PageContainer } from '../components/PageContainer';
import { PageHeader } from '../components/PageHeader';
import { useFormatting } from '../hooks/useFormatting';
import type { SupplierInvoiceListItem } from '../types';

export default function SupplierInvoicesPage() {
  const { formatMoney, formatDate } = useFormatting();

  const { data: rows, isLoading, error } = useQuery({
    queryKey: ['supplier-invoices'],
    queryFn: () => apiClient.get<SupplierInvoiceListItem[]>('/api/supplier-invoices?limit=100'),
  });

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title="Supplier invoices"
        backTo="/app/reports"
        breadcrumbs={[{ label: 'Reports', to: '/app/reports' }, { label: 'Supplier invoices' }]}
      />
      <p className="text-sm text-gray-600 -mt-2">
        Posted and draft supplier invoices. Outstanding amounts follow the same subledger rules as{' '}
        <Link to="/app/reports/ap-ageing" className="text-[#1F6F5C] hover:underline">
          AP ageing
        </Link>
        .
      </p>

      <div className="bg-white rounded-lg shadow overflow-hidden">
        {isLoading && <div className="p-8 text-center text-gray-500">Loading…</div>}
        {error && (
          <div className="p-6 text-red-700">
            {error instanceof Error ? error.message : 'Failed to load supplier invoices'}
          </div>
        )}
        {!isLoading && !error && rows && rows.length === 0 && (
          <div className="p-8 text-center text-gray-500">No supplier invoices yet.</div>
        )}
        {!isLoading && !error && rows && rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Invoice date</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <Link to={`/app/accounting/supplier-invoices/${r.id}`} className="text-[#1F6F5C] font-medium hover:underline">
                        {r.reference_no || r.id.slice(0, 8)}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-900">{r.party?.name ?? '—'}</td>
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

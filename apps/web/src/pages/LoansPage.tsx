import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import type { LoanAgreementListItem } from '@farm-erp/shared';
import { loanAgreementsApi } from '../api/loanAgreements';
import { PageHeader } from '../components/PageHeader';
import { useFormatting } from '../hooks/useFormatting';

export default function LoansPage() {
  const { formatDate, formatMoney } = useFormatting();
  const [rows, setRows] = useState<LoanAgreementListItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const load = async () => {
      try {
        setLoading(true);
        setError(null);
        const res = await loanAgreementsApi.list();
        if (!cancelled) {
          setRows(res.data);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Failed to load loan agreements');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };
    load();
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="space-y-6">
      <PageHeader
        title="Loans"
        backTo="/app/governance"
        breadcrumbs={[
          { label: 'Governance', to: '/app/governance' },
          { label: 'Loans' },
        ]}
      />

      <section className="bg-white rounded-lg shadow overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Loading…</div>
        ) : error ? (
          <div className="p-6 text-red-700">{error}</div>
        ) : rows.length === 0 ? (
          <div className="p-8 text-center text-gray-500">No loan agreements yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Reference
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Project
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Lender
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Status
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">
                    Facility
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Maturity
                  </th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase" />
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm font-mono text-gray-900">
                      {row.reference_no ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-900">
                      {row.project?.name ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {row.lender_party?.name ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-sm">{row.status}</td>
                    <td className="px-4 py-2 text-sm text-right tabular-nums">
                      {row.principal_amount != null
                        ? formatMoney(row.principal_amount, { currencyCode: row.currency_code })
                        : '—'}
                    </td>
                    <td className="px-4 py-2 text-sm tabular-nums">
                      {row.maturity_date ? formatDate(row.maturity_date) : '—'}
                    </td>
                    <td className="px-4 py-2 text-right">
                      <Link
                        to={`/app/loans/${row.id}`}
                        className="text-[#1F6F5C] hover:underline text-sm font-medium"
                      >
                        View
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

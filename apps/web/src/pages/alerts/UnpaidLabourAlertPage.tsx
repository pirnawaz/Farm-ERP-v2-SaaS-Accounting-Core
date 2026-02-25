import { useNavigate, Link } from 'react-router-dom';
import { usePayablesOutstanding } from '../../hooks/useLabour';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import type { PayablesOutstandingRow } from '../../types';

export default function UnpaidLabourAlertPage() {
  const { data: rows = [], isLoading } = usePayablesOutstanding();
  const navigate = useNavigate();
  const { formatMoney } = useFormatting();

  const withBalance = rows.filter((r) => parseFloat(r.payable_balance || '0') > 0);

  const handlePay = (r: PayablesOutstandingRow) => {
    if (!r.party_id) return;
    const params = new URLSearchParams();
    params.set('party_id', r.party_id);
    params.set('purpose', 'WAGES');
    params.set('direction', 'OUT');
    if (parseFloat(r.payable_balance) > 0) params.set('amount', r.payable_balance);
    navigate('/app/payments/new?' + params.toString());
  };

  if (isLoading) {
    return (
      <div className="max-w-2xl mx-auto pb-24 sm:pb-6">
        <PageHeader
          title="Unpaid labour"
          backTo="/app/alerts"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Alerts', to: '/app/alerts' },
            { label: 'Unpaid labour' },
          ]}
        />
        <div className="flex justify-center py-12">
          <LoadingSpinner />
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-2xl mx-auto pb-24 sm:pb-6">
      <PageHeader
        title="Unpaid labour"
        backTo="/app/alerts"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Alerts', to: '/app/alerts' },
          { label: 'Unpaid labour' },
        ]}
      />

      <p className="text-sm text-gray-500 mb-4">Workers with outstanding wages to be paid.</p>

      {withBalance.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-500">
          No outstanding labour balances. Post work logs to accrue wages payable.
        </div>
      ) : (
        <ul className="space-y-2 mb-6">
          {withBalance.map((r) => (
            <li key={r.worker_id}>
              <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="font-medium text-gray-900">{r.worker_name}</p>
                  <p className="text-lg font-semibold tabular-nums text-gray-900">
                    {formatMoney(r.payable_balance)}
                  </p>
                </div>
                {r.party_id ? (
                  <button
                    type="button"
                    onClick={() => handlePay(r)}
                    className="inline-flex items-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
                  >
                    Pay
                  </button>
                ) : (
                  <span className="text-xs text-gray-400">
                    Link worker to Party in Workers to enable wage payments.
                  </span>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      <div className="flex flex-wrap gap-3">
        <Link
          to="/app/labour/payables"
          className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          Full payables (Labour)
        </Link>
        <Link
          to="/app/labour/work-logs"
          className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          Work logs
        </Link>
      </div>
    </div>
  );
}

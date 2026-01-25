import { useNavigate } from 'react-router-dom';
import { usePayablesOutstanding } from '../../hooks/useLabour';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import type { PayablesOutstandingRow } from '../../types';

export default function PayablesOutstandingPage() {
  const { data: rows, isLoading } = usePayablesOutstanding();
  const navigate = useNavigate();
  const { formatMoney } = useFormatting();

  const handlePay = (r: PayablesOutstandingRow) => {
    if (!r.party_id) return;
    const params = new URLSearchParams();
    params.set('party_id', r.party_id);
    params.set('purpose', 'WAGES');
    params.set('direction', 'OUT');
    if (parseFloat(r.payable_balance) > 0) params.set('amount', r.payable_balance);
    navigate(`/app/payments/new?${params.toString()}`);
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Wages Payable Outstanding"
        backTo="/app/labour"
        breadcrumbs={[{ label: 'Labour', to: '/app/labour' }, { label: 'Payables' }]}
      />
      <div className="bg-white rounded-lg shadow overflow-hidden">
        {!rows || rows.length === 0 ? (
          <div className="text-center py-8 text-gray-500">No worker balances. Post work logs to accrue wages payable.</div>
        ) : (
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Worker</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payable Balance</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {rows.map((r) => (
                <tr key={r.worker_id}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{r.worker_name}</td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{formatMoney(r.payable_balance)}</td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm">
                    {r.party_id ? (
                      <button
                        onClick={() => handlePay(r)}
                        className="text-blue-600 hover:text-blue-800"
                      >
                        Pay
                      </button>
                    ) : (
                      <span className="text-gray-400 text-xs">Link worker to Party in Workers to enable wage payments.</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

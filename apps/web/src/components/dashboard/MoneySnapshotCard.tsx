import { Link } from 'react-router-dom';
import { useFormatting } from '../../hooks/useFormatting';
import type { DashboardMoney } from '@farm-erp/shared';

interface MoneySnapshotCardProps {
  money: DashboardMoney;
}

export function MoneySnapshotCard({ money }: MoneySnapshotCardProps) {
  const { formatMoney } = useFormatting();

  return (
    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
      <h3 className="text-sm font-medium text-gray-600 mb-3">Money</h3>
      <dl className="space-y-2 text-sm">
        <div className="flex justify-between">
          <dt className="text-gray-500">Cash</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">{formatMoney(money.cash_balance)}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-gray-500">Bank</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">{formatMoney(money.bank_balance)}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-gray-500">Receivables</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">{formatMoney(money.receivables_total)}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-gray-500">Advances outstanding</dt>
          <dd className="font-semibold text-gray-900 tabular-nums">{formatMoney(money.advances_outstanding_total)}</dd>
        </div>
      </dl>
      <Link
        to="/app/reports/account-balances"
        className="mt-4 inline-block text-sm font-medium text-[#1F6F5C] hover:underline"
      >
        View account balances →
      </Link>
    </div>
  );
}

import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import type { AccountBalanceRow } from '@farm-erp/shared';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageContainer } from '../../components/PageContainer';
import { useFormatting } from '../../hooks/useFormatting';

export default function BankDrilldownPage() {
  const { formatMoney } = useFormatting();
  const asOf = useMemo(() => new Date().toISOString().split('T')[0], []);

  const { data: accountBalances, isLoading } = useQuery({
    queryKey: ['reports', 'account-balances', { as_of: asOf }],
    queryFn: () => apiClient.getAccountBalances({ as_of: asOf }),
    staleTime: 60 * 1000,
  });

  const bankRows = useMemo(() => {
    if (!accountBalances) return [];
    return accountBalances.filter(
      (r: AccountBalanceRow) =>
        r.account_code === 'BANK' ||
        (r.account_type === 'ASSET' && r.account_name?.toLowerCase().includes('bank'))
    );
  }, [accountBalances]);

  if (isLoading) {
    return (
      <PageContainer className="pb-24 sm:pb-6">
        <PageHeader title="Bank balance" backTo="/app/farm-pulse" breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Farm Pulse', to: '/app/farm-pulse' }, { label: 'Bank' }]} />
        <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>
      </PageContainer>
    );
  }

  return (
    <PageContainer className="pb-24 sm:pb-6">
      <PageHeader title="Bank balance" backTo="/app/farm-pulse" breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Farm Pulse', to: '/app/farm-pulse' }, { label: 'Bank' }]} />
      <p className="text-sm text-gray-500 mb-4">From account balances (as of today).</p>

      {bankRows.length > 0 ? (
        <section className="mb-6">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Bank accounts</h2>
          <div className="space-y-2">
            {bankRows.map((row: AccountBalanceRow) => (
              <div key={row.account_id} className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm flex justify-between items-center">
                <div>
                  <p className="font-medium text-gray-900">{row.account_name}</p>
                  <p className="text-xs text-gray-500">{row.account_code}</p>
                </div>
                <p className="text-lg font-semibold tabular-nums text-gray-900">{formatMoney(parseFloat(row.balance))}</p>
              </div>
            ))}
          </div>
        </section>
      ) : (
        <div className="rounded-lg border border-gray-200 bg-white p-4 mb-6">
          <p className="text-sm text-gray-500">No bank accounts in account balances.</p>
        </div>
      )}

      <div className="flex flex-wrap gap-3">
        <Link to="/app/reports/bank-reconciliation" className="inline-flex items-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]">Bank reconciliation</Link>
        <Link to="/app/reports/account-balances" className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">View all account balances</Link>
      </div>
    </PageContainer>
  );
}

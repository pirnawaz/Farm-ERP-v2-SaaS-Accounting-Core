import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import type { AccountBalanceRow } from '@farm-erp/shared';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageContainer } from '../../components/PageContainer';
import { useFormatting } from '../../hooks/useFormatting';

export default function PayablesPage() {
  const { formatMoney } = useFormatting();
  const asOf = useMemo(() => new Date().toISOString().split('T')[0], []);

  const { data: accountBalances, isLoading } = useQuery({
    queryKey: ['reports', 'account-balances', { as_of: asOf }],
    queryFn: () => apiClient.getAccountBalances({ as_of: asOf }),
    staleTime: 60 * 1000,
  });

  const apRows = useMemo(() => {
    if (!accountBalances) return [];
    return accountBalances.filter(
      (r: AccountBalanceRow) => r.account_code === 'AP' || (r.account_type === 'LIABILITY' && r.account_name?.toLowerCase().includes('payable'))
    );
  }, [accountBalances]);

  const totalAP = useMemo(() => apRows.reduce((sum, r) => sum + parseFloat(r.balance), 0), [apRows]);
  const hasSupplierBalances = false; // No per-supplier balances API; show placeholder

  if (isLoading) {
    return (
      <PageContainer className="pb-24 sm:pb-6">
        <PageHeader title="Bills to pay" backTo="/app/farm-pulse" breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Farm Pulse', to: '/app/farm-pulse' }, { label: 'Payables' }]} />
        <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>
      </PageContainer>
    );
  }

  return (
    <PageContainer className="pb-24 sm:pb-6">
      <PageHeader
        title="Bills to pay"
        backTo="/app/farm-pulse"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Farm Pulse', to: '/app/farm-pulse' },
          { label: 'Payables' },
        ]}
      />
      <p className="text-sm text-gray-500 mb-4">From account balances (as of today).</p>

      {apRows.length > 0 && (
        <section className="mb-6">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Payables balance</h2>
          <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="flex justify-between items-center">
              <p className="font-medium text-gray-900">Total you owe suppliers</p>
              <p className="text-xl font-semibold tabular-nums text-gray-900">{formatMoney(totalAP)}</p>
            </div>
          </div>
        </section>
      )}

      {!hasSupplierBalances && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 mb-6">
          <p className="font-medium text-amber-800">Supplier breakdown coming soon</p>
          <p className="text-sm text-amber-700 mt-1">A list of suppliers and their balances will appear here. For now, use Account Balances and Party Ledger for details.</p>
        </div>
      )}

      <div className="flex flex-wrap gap-3">
        <Link to="/app/reports/account-balances" className="inline-flex items-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]">
          View account balances
        </Link>
        <Link to="/app/payments/new" className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
          Record payment
        </Link>
      </div>
    </PageContainer>
  );
}

import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import type { AccountBalanceRow } from '@farm-erp/shared';
import { usePayments } from '../../hooks/usePayments';
import { useSales } from '../../hooks/useSales';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageContainer } from '../../components/PageContainer';
import { useFormatting } from '../../hooks/useFormatting';
import type { Payment, Sale } from '../../types';

const RECENT_LIMIT = 20;

export default function CashDrilldownPage() {
  const { formatMoney, formatDate } = useFormatting();
  const asOf = useMemo(() => new Date().toISOString().split('T')[0], []);

  const { data: accountBalances, isLoading: balancesLoading } = useQuery({
    queryKey: ['reports', 'account-balances', { as_of: asOf }],
    queryFn: () => apiClient.getAccountBalances({ as_of: asOf }),
    staleTime: 60 * 1000,
  });

  const { data: payments = [], isLoading: paymentsLoading } = usePayments();
  const { data: sales = [], isLoading: salesLoading } = useSales();

  const cashRows = useMemo(() => {
    if (!accountBalances) return [];
    return accountBalances.filter(
      (r: AccountBalanceRow) =>
        r.account_code === 'CASH' ||
        (r.account_type === 'ASSET' && r.account_name?.toLowerCase().includes('cash'))
    );
  }, [accountBalances]);

  type ActivityItem = { date: string; label: string; amount: number; id: string; link: string };
  const recentActivity = useMemo(() => {
    const items: ActivityItem[] = [];
    payments.forEach((p: Payment) => {
      const amt = parseFloat(String(p.amount));
      const partyName = p.party?.name ?? 'N/A';
      if (p.direction === 'IN') {
        items.push({ date: p.payment_date, label: 'Receipt: ' + partyName, amount: amt, id: 'pay-' + p.id, link: '/app/payments/' + p.id });
      } else if (p.direction === 'OUT') {
        items.push({ date: p.payment_date, label: 'Payment: ' + partyName, amount: -amt, id: 'pay-' + p.id, link: '/app/payments/' + p.id });
      }
    });
    sales.forEach((s: Sale) => {
      const amt = parseFloat(String(s.amount));
      const buyerName = s.buyer_party?.name ?? 'N/A';
      items.push({
        date: s.sale_date ?? s.posting_date ?? '',
        label: 'Sale: ' + buyerName,
        amount: amt,
        id: 'sale-' + s.id,
        link: '/app/sales/' + s.id,
      });
    });
    items.sort((a, b) => (b.date > a.date ? 1 : b.date < a.date ? -1 : 0));
    return items.slice(0, RECENT_LIMIT);
  }, [payments, sales]);

  const isLoading = balancesLoading;
  if (isLoading) {
    return (
      <PageContainer className="pb-24 sm:pb-6">
        <PageHeader title="Cash in hand" backTo="/app/farm-pulse" breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Farm Pulse', to: '/app/farm-pulse' }, { label: 'Cash' }]} />
        <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>
      </PageContainer>
    );
  }

  return (
    <PageContainer className="pb-24 sm:pb-6">
      <PageHeader title="Cash in hand" backTo="/app/farm-pulse" breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Farm Pulse', to: '/app/farm-pulse' }, { label: 'Cash' }]} />
      <p className="text-sm text-gray-500 mb-4">From account balances (as of today).</p>

      {cashRows.length > 0 && (
        <section className="mb-6">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Cash accounts</h2>
          <div className="space-y-2">
            {cashRows.map((row: AccountBalanceRow) => (
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
      )}

      <section>
        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Recent activity affecting cash</h2>
        {paymentsLoading || salesLoading ? (
          <div className="flex justify-center py-6"><LoadingSpinner /></div>
        ) : recentActivity.length === 0 ? (
          <p className="text-sm text-gray-500 py-4">No recent payments or sales receipts.</p>
        ) : (
          <ul className="space-y-2">
            {recentActivity.map((item) => (
              <li key={item.id}>
                <Link to={item.link} className="block rounded-lg border border-gray-200 bg-white p-3 shadow-sm hover:border-[#1F6F5C]/30">
                  <div className="flex justify-between items-start">
                    <div>
                      <p className="font-medium text-gray-900">{item.label}</p>
                      <p className="text-xs text-gray-500">{formatDate(item.date)}</p>
                    </div>
                    <span className={item.amount >= 0 ? 'tabular-nums font-medium text-green-700' : 'tabular-nums font-medium text-gray-700'}>
                      {item.amount >= 0 ? '+' : ''}{formatMoney(item.amount)}
                    </span>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      <div className="mt-6 flex flex-wrap gap-3">
        <Link to="/app/payments/new" className="inline-flex items-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]">Record payment</Link>
        <Link to="/app/reports/account-balances" className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">View all account balances</Link>
      </div>
    </PageContainer>
  );
}

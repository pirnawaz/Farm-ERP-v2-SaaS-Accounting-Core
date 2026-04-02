import { useState, useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useOperationalTransactions } from '../hooks/useOperationalTransactions';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import { PageHeader } from '../components/PageHeader';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { PageContainer } from '../components/PageContainer';
import type { OperationalTransaction } from '../types';

export default function ReviewQueuePage() {
  const navigate = useNavigate();
  const { hasRole } = useRole();
  const { formatDate, formatMoney } = useFormatting();
  const { data: drafts = [], isLoading } = useOperationalTransactions({ status: 'DRAFT' });
  const [openNextIndex, setOpenNextIndex] = useState(0);

  const canAccess = hasRole('tenant_admin') || hasRole('accountant');
  if (!canAccess) {
    return (
      <PageContainer className="p-4">
        <p className="text-gray-600">You don’t have access to the review queue.</p>
        <Link to="/app/dashboard" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
          Back to dashboard
        </Link>
      </PageContainer>
    );
  }

  const grouped = useMemo(() => {
    const byDate: Record<string, OperationalTransaction[]> = {};
    const sorted = [...drafts].sort(
      (a, b) => new Date(b.transaction_date).getTime() - new Date(a.transaction_date).getTime()
    );
    sorted.forEach((t) => {
      const d = t.transaction_date;
      if (!byDate[d]) byDate[d] = [];
      byDate[d].push(t);
    });
    return Object.entries(byDate).map(([date, items]) => ({
      date,
      items: items.sort((a, b) => a.type.localeCompare(b.type) || a.id.localeCompare(b.id)),
    }));
  }, [drafts]);

  const flatList = useMemo(() => drafts.sort(
    (a, b) => new Date(b.transaction_date).getTime() - new Date(a.transaction_date).getTime()
  ), [drafts]);

  const currentDraft = flatList[openNextIndex] ?? null;

  const handleOpenNext = () => {
    if (currentDraft) {
      navigate(`/app/transactions/${currentDraft.id}`);
    }
    setOpenNextIndex((i) => (i + 1) % Math.max(flatList.length, 1));
  };

  return (
    <PageContainer className="pb-24 sm:pb-6">
      <PageHeader
        title="Review Queue"
        backTo="/app/farm-pulse"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Review Queue' },
        ]}
      />

      <p className="mt-2 text-sm text-gray-500">
        Draft operational records waiting for review. Open to edit or post.
      </p>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner />
        </div>
      ) : flatList.length === 0 ? (
        <div className="mt-6 rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-500">
          <p className="font-medium">No drafts in queue</p>
          <p className="text-sm mt-1">When you create draft transactions, they will appear here.</p>
          <Link to="/app/transactions" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
            View all transactions
          </Link>
        </div>
      ) : (
        <>
          {flatList.length > 0 && (
            <div className="mt-4 flex justify-end">
              <button
                type="button"
                onClick={handleOpenNext}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] text-sm font-medium"
              >
                Open next draft
                {currentDraft && (
                  <span className="ml-1 opacity-90">
                    ({openNextIndex + 1}/{flatList.length})
                  </span>
                )}
              </button>
            </div>
          )}

          <div className="mt-6 space-y-6">
            {grouped.map(({ date, items }) => (
              <section key={date}>
                <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                  {formatDate(date)}
                </h2>
                <ul className="space-y-2">
                  {items.map((t) => (
                    <li key={t.id}>
                      <Link
                        to={`/app/transactions/${t.id}`}
                        className="block rounded-lg border border-gray-200 bg-white p-4 hover:border-[#1F6F5C]/40 hover:bg-gray-50/50"
                      >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <span className="font-medium text-gray-900">{t.type}</span>
                          <span className="tabular-nums text-gray-700">{formatMoney(parseFloat(t.amount))}</span>
                        </div>
                        <div className="mt-1 text-sm text-gray-500">
                          {t.project?.name ?? 'No project'} · {t.classification}
                        </div>
                      </Link>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </div>
        </>
      )}
    </PageContainer>
  );
}

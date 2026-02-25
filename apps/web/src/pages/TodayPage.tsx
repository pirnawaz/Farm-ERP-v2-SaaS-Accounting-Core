import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useActivities } from '../hooks/useCropOps';
import { useWorkLogs } from '../hooks/useLabour';
import { useHarvests } from '../hooks/useHarvests';
import { useSales } from '../hooks/useSales';
import { usePayments } from '../hooks/usePayments';
import { useGRNs, useIssues } from '../hooks/useInventory';
import { useOperationalTransactions } from '../hooks/useOperationalTransactions';
import { useAlerts } from '../hooks/useAlerts';
import { PageHeader } from '../components/PageHeader';
import { QuickActions } from '../components/QuickActions';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { useFormatting } from '../hooks/useFormatting';
import { term } from '../config/terminology';

type TodayItem = {
  id: string;
  kind: string;
  label: string;
  sortKey: string;
  link: string;
  meta?: string;
};

function todayDate(): string {
  return new Date().toISOString().slice(0, 10);
}

export default function TodayPage() {
  const today = useMemo(() => todayDate(), []);
  const { formatDate, formatMoney } = useFormatting();

  const { data: activities = [], isLoading: activitiesLoading } = useActivities({
    from: today,
    to: today,
  });
  const { data: workLogs = [], isLoading: workLogsLoading } = useWorkLogs({
    from: today,
    to: today,
  });
  const { data: harvests = [], isLoading: harvestsLoading } = useHarvests({
    from: today,
    to: today,
  });
  const { data: sales = [], isLoading: salesLoading } = useSales({
    date_from: today,
    date_to: today,
  });
  const { data: payments = [], isLoading: paymentsLoading } = usePayments({
    date_from: today,
    date_to: today,
  });
  const { data: grns = [], isLoading: grnsLoading } = useGRNs();
  const { data: issues = [], isLoading: issuesLoading } = useIssues();
  const { data: draftTransactions = [] } = useOperationalTransactions({
    status: 'DRAFT',
  });
  const { totalCount: alertsCount, isLoading: alertsLoading } = useAlerts();

  const pendingCount = draftTransactions.length;

  const todayGrns = useMemo(
    () => grns.filter((g) => g.doc_date === today),
    [grns, today]
  );
  const todayIssues = useMemo(
    () => issues.filter((i) => i.doc_date === today),
    [issues, today]
  );

  const items: TodayItem[] = useMemo(() => {
    const list: TodayItem[] = [];
    activities.forEach((a) => {
      list.push({
        id: a.id,
        kind: 'activity',
        label: a.doc_no || 'Field work',
        sortKey: `${a.activity_date}T${a.created_at || ''}`,
        link: `/app/crop-ops/activities/${a.id}`,
        meta: a.type?.name || undefined,
      });
    });
    workLogs.forEach((w) => {
      list.push({
        id: w.id,
        kind: 'work-log',
        label: w.doc_no || 'Labour',
        sortKey: `${w.work_date}T${w.created_at || ''}`,
        link: `/app/labour/work-logs/${w.id}`,
        meta: w.worker?.name || undefined,
      });
    });
    todayGrns.forEach((g) => {
      list.push({
        id: g.id,
        kind: 'grn',
        label: g.doc_no || 'Receipt',
        sortKey: `${g.doc_date}T${g.created_at || ''}`,
        link: `/app/inventory/grns/${g.id}`,
        meta: g.store?.name || undefined,
      });
    });
    todayIssues.forEach((i) => {
      list.push({
        id: i.id,
        kind: 'issue',
        label: i.doc_no || term('issueSingular'),
        sortKey: `${i.doc_date}T${i.created_at || ''}`,
        link: `/app/inventory/issues/${i.id}`,
        meta: i.store?.name || undefined,
      });
    });
    harvests.forEach((h) => {
      list.push({
        id: h.id,
        kind: 'harvest',
        label: h.harvest_no || 'Harvest',
        sortKey: `${h.harvest_date}T${h.created_at || ''}`,
        link: `/app/harvests/${h.id}`,
        meta: h.project?.name || undefined,
      });
    });
    sales.forEach((s) => {
      list.push({
        id: s.id,
        kind: 'sale',
        label: s.sale_no || 'Sale',
        sortKey: `${s.posting_date}T${s.created_at || ''}`,
        link: `/app/sales/${s.id}`,
        meta: formatMoney(s.amount),
      });
    });
    payments.forEach((p) => {
      list.push({
        id: p.id,
        kind: 'payment',
        label: p.reference || 'Payment',
        sortKey: `${p.payment_date}T${p.created_at || ''}`,
        link: `/app/payments/${p.id}`,
        meta: formatMoney(p.amount),
      });
    });
    list.sort((a, b) => (b.sortKey.localeCompare(a.sortKey)));
    return list;
  }, [
    activities,
    workLogs,
    todayGrns,
    todayIssues,
    harvests,
    sales,
    payments,
    formatMoney,
  ]);

  const kindLabel: Record<string, string> = {
    activity: 'Field work',
    'work-log': 'Labour',
    grn: term('grnSingular'),
    issue: term('issueSingular'),
    harvest: 'Harvest',
    sale: 'Sale',
    payment: 'Payment',
  };

  const isLoading =
    activitiesLoading ||
    workLogsLoading ||
    harvestsLoading ||
    salesLoading ||
    paymentsLoading ||
    grnsLoading ||
    issuesLoading;

  return (
    <div className="max-w-2xl mx-auto pb-24 sm:pb-6">
      <PageHeader
        title="Today"
        backTo="/app/dashboard"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Today' },
        ]}
      />

      <div className="space-y-4">
        {!alertsLoading && alertsCount > 0 && (
          <Link
            to="/app/alerts"
            className="block rounded-lg border border-[#1F6F5C]/30 bg-[#1F6F5C]/5 px-4 py-2 text-sm text-[#1F6F5C] font-medium"
          >
            {alertsCount} alert{alertsCount !== 1 ? 's' : ''} — View all
          </Link>
        )}
        {pendingCount > 0 && (
          <Link
            to="/app/transactions"
            className="block rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800"
          >
            {pendingCount} record{pendingCount !== 1 ? 's' : ''} pending review
          </Link>
        )}

        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            Quick actions
          </h2>
          <QuickActions />
        </section>

        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            Today&apos;s activity — {formatDate(today)}
          </h2>
          {isLoading ? (
            <div className="flex justify-center py-8">
              <LoadingSpinner />
            </div>
          ) : items.length === 0 ? (
            <p className="text-gray-500 text-sm py-6">No records for today yet.</p>
          ) : (
            <ul className="space-y-2">
              {items.map((it) => (
                <li key={`${it.kind}-${it.id}`}>
                  <Link
                    to={it.link}
                    className="block rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:border-[#1F6F5C]/30 hover:shadow transition"
                  >
                    <div className="flex justify-between items-start gap-2">
                      <div className="min-w-0">
                        <span className="text-xs font-medium text-gray-500 uppercase tracking-wide">
                          {kindLabel[it.kind] || it.kind}
                        </span>
                        <p className="font-medium text-gray-900 truncate mt-0.5">
                          {it.label || '—'}
                        </p>
                        {it.meta && (
                          <p className="text-sm text-gray-500 mt-0.5">{it.meta}</p>
                        )}
                      </div>
                      <span className="flex-shrink-0 text-gray-400" aria-hidden>
                        →
                      </span>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </div>
  );
}

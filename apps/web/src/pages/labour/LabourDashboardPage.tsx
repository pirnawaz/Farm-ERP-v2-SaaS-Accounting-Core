import { Link } from 'react-router-dom';
import { useWorkers, useWorkLogs, usePayablesOutstanding } from '../../hooks/useLabour';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';

function KpiCard({
  label,
  value,
  hint,
  to,
}: {
  label: string;
  value: string;
  hint?: string;
  to?: string;
}) {
  const body = (
    <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm hover:border-[#1F6F5C]/30 transition-colors">
      <div className="text-sm font-medium text-gray-600">{label}</div>
      <div className="mt-2 text-3xl font-semibold text-gray-900 tabular-nums">{value}</div>
      {hint ? <div className="mt-1 text-xs text-gray-500">{hint}</div> : null}
    </div>
  );
  return to ? (
    <Link to={to} className="block focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] rounded-xl">
      {body}
    </Link>
  ) : (
    body
  );
}

function ActionLink({
  to,
  children,
  variant,
}: {
  to: string;
  children: React.ReactNode;
  variant: 'primary' | 'secondary';
}) {
  const base =
    'inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]';
  const styles =
    variant === 'primary'
      ? 'bg-[#1F6F5C] text-white hover:bg-[#1a5a4a]'
      : 'border border-gray-200 bg-white text-[#1F6F5C] hover:bg-gray-50';
  return (
    <Link to={to} className={`${base} ${styles}`}>
      {children}
    </Link>
  );
}

export default function LabourDashboardPage() {
  const { data: workers, isLoading: workersLoading } = useWorkers();
  const { data: workLogs, isLoading: logsLoading } = useWorkLogs();
  const { data: payables, isLoading: payablesLoading } = usePayablesOutstanding();
  const { formatMoney } = useFormatting();

  const workerCount = (workers ?? []).length;
  const logs = workLogs ?? [];
  const outstandingTotal = (payables || []).reduce((s, r) => s + parseFloat(r.payable_balance || '0'), 0);

  const hasNoData = workerCount === 0 && logs.length === 0;
  const hasWorkersNoLogs = workerCount > 0 && logs.length === 0;

  if (workersLoading || logsLoading || payablesLoading) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div className="space-y-10 max-w-6xl">
      <header>
        <h1 className="text-2xl font-bold text-gray-900">Labour Overview</h1>
        <p className="mt-1 text-base text-gray-700 max-w-2xl">Track workers, labour activity, and amounts owed.</p>
        <p className="mt-1 text-sm text-gray-500 max-w-2xl">Use this page to see labour activity and manage your workforce.</p>
      </header>

      {hasNoData ? (
        <section className="rounded-xl border border-amber-200 bg-amber-50 p-5">
          <div className="text-base font-semibold text-amber-900">Get started with labour</div>
          <ol className="mt-2 list-decimal list-inside text-sm text-amber-900/90 space-y-1">
            <li>Add workers</li>
            <li>Review payables</li>
          </ol>
          <div className="mt-4 flex flex-wrap gap-2">
            <ActionLink to="/app/labour/workers" variant="primary">Add workers</ActionLink>
          </div>
        </section>
      ) : null}

      <section aria-label="Labour summary">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <KpiCard label="Workers" value={String(workerCount)} to="/app/labour/workers" />
          <KpiCard
            label="Payables"
            value={formatMoney(outstandingTotal)}
            hint="Amount owed to workers"
            to="/app/labour/payables"
          />
        </div>
      </section>

      {hasWorkersNoLogs ? (
        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
          No labour activity recorded yet.
        </div>
      ) : null}

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-10">
        <section className="lg:col-span-1" aria-labelledby="labour-actions-heading">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 id="labour-actions-heading" className="text-lg font-semibold text-gray-900">
              Quick actions
            </h2>
            <p className="mt-1 text-xs text-gray-500">Shortcuts — full lists stay in the sidebar.</p>

            <div className="mt-4 space-y-2">
              <ActionLink to="/app/labour/workers" variant="primary">Add Worker</ActionLink>
            </div>

            <div className="mt-6 flex flex-col gap-2">
              <ActionLink to="/app/labour/workers" variant="secondary">View Workers</ActionLink>
              <ActionLink to="/app/labour/payables" variant="secondary">View Payables</ActionLink>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}

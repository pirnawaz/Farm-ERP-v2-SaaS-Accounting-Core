import { useMemo, useState } from 'react';
import { PageHeader } from '../components/PageHeader';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { FarmActivityTimeline } from '../components/farmActivity/FarmActivityTimeline';
import { useFarmActivityTimeline } from '../hooks/useFarmActivityTimeline';
import { useModules } from '../contexts/ModulesContext';

function defaultFromTo(): { from: string; to: string } {
  const to = new Date();
  const from = new Date();
  from.setDate(from.getDate() - 90);
  return {
    from: from.toISOString().slice(0, 10),
    to: to.toISOString().slice(0, 10),
  };
}

export default function FarmActivityTimelinePage() {
  const defaults = useMemo(() => defaultFromTo(), []);
  const [from, setFrom] = useState(defaults.from);
  const [to, setTo] = useState(defaults.to);
  const { isModuleEnabled, loading: modulesLoading } = useModules();

  const hasAny =
    isModuleEnabled('crop_ops') || isModuleEnabled('ar_sales');

  const { data, isLoading, isError, error, refetch, isFetching } = useFarmActivityTimeline(
    { from, to, limit: 300 },
    !modulesLoading && hasAny,
  );

  if (modulesLoading) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!hasAny) {
    return (
      <div className="space-y-6 max-w-4xl">
        <PageHeader
          title="Farm activity"
          description="A single dated list of field jobs, harvests, and sales."
        backTo="/app/dashboard"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Farm activity' },
        ]}
      />
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
          Enable the <strong>Crop Ops</strong> and/or <strong>Sales</strong> module to see activity here.
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-4xl">
      <PageHeader
        title="What happened on my farm?"
        description="Field jobs, harvests, and sales in one timeline — newest first. Read-only; open a document to view or edit details."
        backTo="/app/dashboard"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Farm activity' },
        ]}
      />

      <div className="flex flex-wrap items-end gap-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <label className="text-sm">
          <span className="block text-gray-600 mb-1">From</span>
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </label>
        <label className="text-sm">
          <span className="block text-gray-600 mb-1">To</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </label>
        <button
          type="button"
          onClick={() => refetch()}
          disabled={isFetching}
          className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a] disabled:opacity-50"
        >
          {isFetching ? 'Loading…' : 'Refresh'}
        </button>
      </div>

      {isLoading && (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      )}

      {isError && (
        <div className="rounded-md bg-red-50 text-red-800 text-sm px-3 py-2">
          {(error as Error)?.message || 'Could not load timeline.'}
        </div>
      )}

      {data && (
        <>
          <p className="text-xs text-gray-500">
            Showing {data.items.length} entr{data.items.length === 1 ? 'y' : 'ies'}
            {data.generated_at ? ` · Generated ${new Date(data.generated_at).toLocaleString()}` : ''}
          </p>
          <FarmActivityTimeline items={data.items} />
        </>
      )}
    </div>
  );
}

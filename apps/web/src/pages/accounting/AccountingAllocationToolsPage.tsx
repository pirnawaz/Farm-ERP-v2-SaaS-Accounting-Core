import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { fetchBillRecognitionSchedules, fetchOverheadAllocations } from '../../api/phase5aAccounting';

/**
 * Farm-first accounting helpers: allocate posted overhead to projects, spread bills over time.
 * Create/post flows are API-driven; this page lists current drafts/posted rows for visibility.
 */
export default function AccountingAllocationToolsPage() {
  const { data: allocations, isLoading: aLoading, error: aErr } = useQuery({
    queryKey: ['overhead-allocations'],
    queryFn: fetchOverheadAllocations,
  });
  const { data: schedules, isLoading: sLoading, error: sErr } = useQuery({
    queryKey: ['bill-recognition-schedules'],
    queryFn: fetchBillRecognitionSchedules,
  });

  return (
    <div className="max-w-5xl mx-auto px-4 py-6 space-y-8">
      <PageHeader
        title="Allocation & recognition"
        description="Allocate overhead from cost centers into field cycles, or spread a posted bill over time (prepaid). Use the API for create/post actions; lists below are read-only."
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Reports', to: '/app/reports' },
          { label: 'Allocation & recognition' },
        ]}
      />

      <div className="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 space-y-2">
        <p>
          <strong>Allocate overhead</strong> — reclass part of a posted <strong>farm overhead</strong> bill into one or more{' '}
          <Link to="/app/projects" className="text-[#1F6F5C] font-medium hover:underline">
            field cycles
          </Link>
          . Original overhead stays auditable; allocation is a separate posting.
        </p>
        <p>
          <strong>Spread a bill over time</strong> — for a <strong>posted</strong> supplier bill, defer expense to prepaid asset then recognise by period (manual post per schedule line).
        </p>
      </div>

      <section className="space-y-2">
        <h2 className="text-sm font-semibold text-gray-900">Overhead allocations</h2>
        {aLoading && (
          <div className="flex justify-center py-8">
            <LoadingSpinner />
          </div>
        )}
        {aErr && <p className="text-red-600 text-sm">{(aErr as Error).message}</p>}
        {!aLoading && !aErr && (
          <pre className="text-xs bg-gray-50 border border-gray-100 rounded-md p-3 overflow-auto max-h-64">
            {JSON.stringify(allocations ?? [], null, 2)}
          </pre>
        )}
      </section>

      <section className="space-y-2">
        <h2 className="text-sm font-semibold text-gray-900">Bill recognition schedules</h2>
        {sLoading && (
          <div className="flex justify-center py-8">
            <LoadingSpinner />
          </div>
        )}
        {sErr && <p className="text-red-600 text-sm">{(sErr as Error).message}</p>}
        {!sLoading && !sErr && (
          <pre className="text-xs bg-gray-50 border border-gray-100 rounded-md p-3 overflow-auto max-h-64">
            {JSON.stringify(schedules ?? [], null, 2)}
          </pre>
        )}
      </section>
    </div>
  );
}

import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { PageHeader } from '../../../components/PageHeader';
import { PageContainer } from '../../../components/PageContainer';
import { useFormatting } from '../../../hooks/useFormatting';
import { useRole } from '../../../hooks/useRole';
import { fxRevaluationApi } from '../../../api/multiCurrency';
import type { FxRevaluationRun } from '@farm-erp/shared';
import toast from 'react-hot-toast';

export default function FXRevaluationRunsPage() {
  const navigate = useNavigate();
  const { formatDate } = useFormatting();
  const { canPost } = useRole();
  const [runs, setRuns] = useState<FxRevaluationRun[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('');
  const [asOfDate, setAsOfDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [creating, setCreating] = useState(false);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const rows = await fxRevaluationApi.list({ status: statusFilter || undefined });
      setRuns(rows);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load runs');
    } finally {
      setLoading(false);
    }
  }, [statusFilter]);

  useEffect(() => {
    load();
  }, [load]);

  const createDraft = async (e: FormEvent) => {
    e.preventDefault();
    if (!asOfDate) {
      toast.error('Choose an as-of date.');
      return;
    }
    try {
      setCreating(true);
      const run = await fxRevaluationApi.createDraft(asOfDate);
      toast.success('Draft run created');
      await load();
      navigate(`/app/accounting/fx-revaluation-runs/${run.id}`);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Create failed';
      toast.error(msg);
    } finally {
      setCreating(false);
    }
  };

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title="FX revaluation runs"
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'Multi-currency', to: '/app/accounting/exchange-rates' },
          { label: 'FX revaluation' },
        ]}
      />

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-lg font-semibold">Create draft run</h2>
        <p className="text-sm text-gray-600">
          Builds draft lines from open foreign-currency monetary balances (e.g. AP, loans) as of the selected date.
          Rates come from <Link to="/app/accounting/exchange-rates" className="text-[#1F6F5C] underline">exchange rates</Link>.
        </p>
        <form onSubmit={createDraft} className="flex flex-wrap gap-4 items-end max-w-xl">
          <label className="text-sm">
            <span className="text-gray-600 block mb-1">As-of date</span>
            <input
              type="date"
              className="border rounded-md px-3 py-2"
              value={asOfDate}
              onChange={(e) => setAsOfDate(e.target.value)}
              required
            />
          </label>
          <button
            type="submit"
            disabled={creating || !canPost}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {creating ? 'Creating…' : 'Generate draft'}
          </button>
        </form>
        {!canPost && (
          <p className="text-sm text-amber-800">Only tenant administrators and accountants can create runs.</p>
        )}
      </section>

      <section className="space-y-4">
        <div className="flex flex-wrap items-center gap-4">
          <label className="text-sm text-gray-600">
            Status
            <select
              className="ml-2 border rounded-md px-2 py-1"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
            >
              <option value="">All</option>
              <option value="DRAFT">DRAFT</option>
              <option value="POSTED">POSTED</option>
              <option value="VOID">VOID</option>
            </select>
          </label>
          <button type="button" onClick={() => load()} className="text-sm text-[#1F6F5C] hover:underline">
            Refresh
          </button>
        </div>

        {loading && <div className="text-gray-600">Loading…</div>}
        {error && (
          <div className="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4" role="alert">
            {error}
          </div>
        )}
        {!loading && !error && runs.length === 0 && (
          <div className="bg-gray-50 border border-gray-200 rounded-lg p-6 text-gray-600">
            No revaluation runs yet. Create a draft using the form above.
          </div>
        )}
        {!loading && runs.length > 0 && (
          <div className="overflow-x-auto bg-white rounded-lg shadow">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="border-b bg-gray-50 text-left">
                  <th className="p-3">Reference</th>
                  <th className="p-3">As-of</th>
                  <th className="p-3">Status</th>
                  <th className="p-3 text-right">Lines</th>
                  <th className="p-3" />
                </tr>
              </thead>
              <tbody>
                {runs.map((run) => (
                  <tr key={run.id} className="border-b border-gray-100">
                    <td className="p-3 font-mono text-xs">{run.reference_no}</td>
                    <td className="p-3 whitespace-nowrap">{formatDate(run.as_of_date)}</td>
                    <td className="p-3">
                      <span
                        className={
                          run.status === 'POSTED'
                            ? 'text-green-800'
                            : run.status === 'DRAFT'
                              ? 'text-amber-800'
                              : 'text-gray-600'
                        }
                      >
                        {run.status}
                      </span>
                    </td>
                    <td className="p-3 text-right tabular-nums">{run.lines_count ?? run.lines?.length ?? '—'}</td>
                    <td className="p-3">
                      <Link
                        to={`/app/accounting/fx-revaluation-runs/${run.id}`}
                        className="text-[#1F6F5C] font-medium hover:underline"
                      >
                        Open
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </PageContainer>
  );
}

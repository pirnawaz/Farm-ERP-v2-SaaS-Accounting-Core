import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import type { FixedAssetDepreciationRun } from '@farm-erp/shared';
import { fixedAssetsApi } from '../../../api/fixedAssets';
import { PageHeader } from '../../../components/PageHeader';
import { useFormatting } from '../../../hooks/useFormatting';
import { useRole } from '../../../hooks/useRole';
import toast from 'react-hot-toast';

export default function FixedAssetDepreciationRunsPage() {
  const navigate = useNavigate();
  const { formatDate } = useFormatting();
  const { canPost } = useRole();
  const [runs, setRuns] = useState<FixedAssetDepreciationRun[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<string>('');

  const [periodStart, setPeriodStart] = useState('');
  const [periodEnd, setPeriodEnd] = useState('');
  const [generating, setGenerating] = useState(false);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const rows = await fixedAssetsApi.listDepreciationRuns({ status: statusFilter || undefined });
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

  const generate = async (e: FormEvent) => {
    e.preventDefault();
    if (!periodStart || !periodEnd) {
      toast.error('Enter period start and end.');
      return;
    }
    try {
      setGenerating(true);
      const run = await fixedAssetsApi.createDepreciationRun({
        period_start: periodStart,
        period_end: periodEnd,
      });
      toast.success('Draft run generated');
      await load();
      navigate(`/app/accounting/fixed-assets/depreciation-runs/${run.id}`);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Generate failed');
    } finally {
      setGenerating(false);
    }
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Depreciation runs"
        backTo="/app/accounting/fixed-assets"
        breadcrumbs={[
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'Fixed assets', to: '/app/accounting/fixed-assets' },
          { label: 'Depreciation runs' },
        ]}
      />

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-lg font-semibold">Generate draft run</h2>
        <p className="text-sm text-gray-600">
          Creates a DRAFT depreciation run for the period. Review lines on the run detail page, then post.
        </p>
        <form onSubmit={generate} className="flex flex-wrap gap-4 items-end max-w-2xl">
          <label className="text-sm">
            <span className="text-gray-600 block mb-1">Period start</span>
            <input
              type="date"
              className="border rounded-md px-3 py-2"
              value={periodStart}
              onChange={(e) => setPeriodStart(e.target.value)}
              required
            />
          </label>
          <label className="text-sm">
            <span className="text-gray-600 block mb-1">Period end</span>
            <input
              type="date"
              className="border rounded-md px-3 py-2"
              value={periodEnd}
              onChange={(e) => setPeriodEnd(e.target.value)}
              required
            />
          </label>
          <button
            type="submit"
            disabled={generating || !canPost}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md disabled:opacity-50"
          >
            {generating ? 'Generating…' : 'Generate'}
          </button>
        </form>
        {!canPost && (
          <p className="text-sm text-amber-800">Only tenant administrators and accountants can generate runs.</p>
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
          <div className="bg-gray-50 border border-gray-200 rounded-lg p-6 text-gray-600">No depreciation runs yet.</div>
        )}
        {!loading && runs.length > 0 && (
          <div className="overflow-x-auto bg-white rounded-lg shadow">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="border-b bg-gray-50 text-left">
                  <th className="p-3">Reference</th>
                  <th className="p-3">Period</th>
                  <th className="p-3">Status</th>
                  <th className="p-3">Lines</th>
                </tr>
              </thead>
              <tbody>
                {runs.map((r) => (
                  <tr key={r.id} className="border-b border-gray-100 hover:bg-gray-50">
                    <td className="p-3">
                      <Link
                        className="text-[#1F6F5C] hover:underline"
                        to={`/app/accounting/fixed-assets/depreciation-runs/${r.id}`}
                      >
                        {r.reference_no}
                      </Link>
                    </td>
                    <td className="p-3">
                      {formatDate(r.period_start)} – {formatDate(r.period_end)}
                    </td>
                    <td className="p-3">
                      <span
                        className={`px-2 py-0.5 rounded text-xs ${
                          r.status === 'POSTED'
                            ? 'bg-green-100 text-green-900'
                            : r.status === 'DRAFT'
                              ? 'bg-amber-100 text-amber-900'
                              : 'bg-gray-100 text-gray-800'
                        }`}
                      >
                        {r.status}
                      </span>
                    </td>
                    <td className="p-3">{r.lines_count ?? r.lines?.length ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

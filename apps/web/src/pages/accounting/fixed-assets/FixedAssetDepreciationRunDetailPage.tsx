import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import type { FixedAssetDepreciationRun } from '@farm-erp/shared';
import { fixedAssetsApi } from '../../../api/fixedAssets';
import { PageHeader } from '../../../components/PageHeader';
import { useFormatting } from '../../../hooks/useFormatting';
import { useRole } from '../../../hooks/useRole';
import toast from 'react-hot-toast';

function newIdempotencyKey(): string {
  return typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : `fa-run-post-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export default function FixedAssetDepreciationRunDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { formatMoney, formatDate } = useFormatting();
  const { canPost } = useRole();

  const [run, setRun] = useState<FixedAssetDepreciationRun | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [postingDate, setPostingDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [posting, setPosting] = useState(false);

  const load = useCallback(async () => {
    if (!id) return;
    try {
      setLoading(true);
      setError(null);
      const r = await fixedAssetsApi.getDepreciationRun(id);
      setRun(r);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load run');
      setRun(null);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    load();
  }, [load]);

  const post = async () => {
    if (!id || !run || run.status !== 'DRAFT') return;
    try {
      setPosting(true);
      await fixedAssetsApi.postDepreciationRun(id, {
        posting_date: postingDate,
        idempotency_key: newIdempotencyKey(),
      });
      toast.success('Depreciation run posted');
      await load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Post failed');
    } finally {
      setPosting(false);
    }
  };

  if (loading && !run) {
    return <div className="text-gray-600 p-4">Loading…</div>;
  }

  if (error || !run) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">{error || 'Not found'}</p>
        <Link to="/app/accounting/fixed-assets/depreciation-runs" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
          ← Depreciation runs
        </Link>
      </div>
    );
  }

  const readOnly = run.status === 'POSTED' || run.status === 'VOID';
  const lines = run.lines ?? [];

  return (
    <div className="space-y-6">
      <PageHeader
        title={`Depreciation run ${run.reference_no}`}
        backTo="/app/accounting/fixed-assets/depreciation-runs"
        breadcrumbs={[
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'Fixed assets', to: '/app/accounting/fixed-assets' },
          { label: 'Depreciation runs', to: '/app/accounting/fixed-assets/depreciation-runs' },
          { label: run.reference_no },
        ]}
      />

      <div className="flex flex-wrap gap-4 text-sm text-gray-600">
        <span>
          Status:{' '}
          <strong className="text-gray-900">{run.status}</strong>
        </span>
        <span>
          Period: {formatDate(run.period_start)} – {formatDate(run.period_end)}
        </span>
        {run.posting_group_id && (
          <Link className="text-[#1F6F5C] hover:underline" to={`/app/posting-groups/${run.posting_group_id}`}>
            Posting group
          </Link>
        )}
      </div>

      {readOnly && (
        <div className="bg-gray-100 border border-gray-200 rounded-lg px-4 py-3 text-sm text-gray-800">
          This run is <strong>{run.status}</strong> — lines are read-only.
        </div>
      )}

      {!readOnly && run.status === 'DRAFT' && (
        <section className="bg-white rounded-lg shadow p-6 flex flex-wrap gap-4 items-end">
          <label className="text-sm">
            <span className="text-gray-600 block mb-1">Posting date</span>
            <input
              type="date"
              className="border rounded-md px-3 py-2"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              disabled={!canPost}
            />
          </label>
          <button
            type="button"
            onClick={() => post()}
            disabled={!canPost || posting}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md disabled:opacity-50"
          >
            {posting ? 'Posting…' : 'Post run'}
          </button>
          {!canPost && (
            <span className="text-sm text-amber-800">Only tenant administrators and accountants can post.</span>
          )}
        </section>
      )}

      <section className="bg-white rounded-lg shadow overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead>
            <tr className="border-b bg-gray-50 text-left">
              <th className="p-3">Asset</th>
              <th className="p-3">Period</th>
              <th className="p-3 text-right">Depreciation</th>
              <th className="p-3 text-right">Opening</th>
              <th className="p-3 text-right">Closing</th>
            </tr>
          </thead>
          <tbody>
            {lines.length === 0 && (
              <tr>
                <td colSpan={5} className="p-6 text-gray-600">
                  No lines on this run.
                </td>
              </tr>
            )}
            {lines.map((line) => {
              const fa = line.fixed_asset;
              const cc = fa?.currency_code ?? 'USD';
              return (
                <tr key={line.id} className="border-b border-gray-100">
                  <td className="p-3">
                    {fa ? (
                      <Link className="text-[#1F6F5C] hover:underline" to={`/app/accounting/fixed-assets/${fa.id}`}>
                        {fa.asset_code} · {fa.name}
                      </Link>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="p-3">
                    {formatDate(line.depreciation_start)} – {formatDate(line.depreciation_end)}
                  </td>
                  <td className="p-3 text-right tabular-nums">{formatMoney(line.depreciation_amount, { currencyCode: cc })}</td>
                  <td className="p-3 text-right tabular-nums">
                    {formatMoney(line.opening_carrying_amount, { currencyCode: cc })}
                  </td>
                  <td className="p-3 text-right tabular-nums">
                    {formatMoney(line.closing_carrying_amount, { currencyCode: cc })}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </section>
    </div>
  );
}

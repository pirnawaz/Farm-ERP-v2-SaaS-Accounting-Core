import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { PageHeader } from '../../../components/PageHeader';
import { PageContainer } from '../../../components/PageContainer';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { useFormatting } from '../../../hooks/useFormatting';
import { useTenantSettings } from '../../../hooks/useTenantSettings';
import { useRole } from '../../../hooks/useRole';
import { fxRevaluationApi } from '../../../api/multiCurrency';
import type { FxRevaluationRun } from '@farm-erp/shared';
import toast from 'react-hot-toast';

function parseValidationMessage(err: unknown): string {
  if (!(err instanceof Error)) return 'Request failed';
  const m = err.message;
  if (/exchange_rate:/i.test(m) || /No exchange rate/i.test(m)) {
    return m;
  }
  return m;
}

export default function FXRevaluationRunDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { formatMoney, formatDate, formatDateTime } = useFormatting();
  const { settings } = useTenantSettings();
  const baseCc = (settings?.currency_code || 'GBP').toUpperCase();
  const { canPost } = useRole();

  const [run, setRun] = useState<FxRevaluationRun | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [posting, setPosting] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [postingDate, setPostingDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [idempotencyKey, setIdempotencyKey] = useState('');

  const load = useCallback(async () => {
    if (!id) return;
    try {
      setLoading(true);
      setError(null);
      const data = await fxRevaluationApi.get(id);
      setRun(data);
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

  const isDraft = run?.status === 'DRAFT';
  const isPosted = run?.status === 'POSTED';

  const lines = run?.lines ?? [];
  const sumDelta = lines.reduce((s, l) => s + Number(l.delta_amount), 0);
  const sumOrig = lines.reduce((s, l) => s + Number(l.original_base_amount), 0);
  const sumReval = lines.reduce((s, l) => s + Number(l.revalued_base_amount), 0);

  const onRefresh = async () => {
    if (!id || !isDraft) return;
    try {
      setRefreshing(true);
      const data = await fxRevaluationApi.refresh(id);
      setRun(data);
      toast.success('Lines refreshed from current open balances and rates');
    } catch (err) {
      toast.error(parseValidationMessage(err));
    } finally {
      setRefreshing(false);
    }
  };

  const onPost = async (e: FormEvent) => {
    e.preventDefault();
    if (!id || !postingDate) return;
    try {
      setPosting(true);
      await fxRevaluationApi.post(id, {
        posting_date: postingDate,
        idempotency_key: idempotencyKey.trim() || undefined,
      });
      toast.success('Revaluation posted');
      await load();
    } catch (err) {
      toast.error(parseValidationMessage(err));
    } finally {
      setPosting(false);
    }
  };

  if (!id) {
    return (
      <PageContainer>
        <p className="text-red-800">Missing run id.</p>
      </PageContainer>
    );
  }

  if (loading && !run) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (error && !run) {
    return (
      <PageContainer className="space-y-4">
        <PageHeader title="FX revaluation" backTo="/app/accounting/fx-revaluation-runs" />
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4">{error}</div>
        <Link to="/app/accounting/fx-revaluation-runs" className="text-[#1F6F5C] underline">
          Back to list
        </Link>
      </PageContainer>
    );
  }

  if (!run) {
    return null;
  }

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title={`FX revaluation · ${run.reference_no}`}
        backTo="/app/accounting/fx-revaluation-runs"
        breadcrumbs={[
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'FX revaluation', to: '/app/accounting/fx-revaluation-runs' },
          { label: run.reference_no },
        ]}
      />

      <div className="bg-slate-50 border border-slate-200 rounded-lg p-4 text-sm text-slate-800">
        <strong className="font-semibold">Amounts in functional (base) currency:</strong>{' '}
        <span className="font-mono">{baseCc}</span>. Exposure <strong>currency</strong> per line is the foreign
        monetary unit being revalued; <strong>original / revalued / delta</strong> are in {baseCc}.
      </div>

      {isPosted && (
        <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">
          This run is <strong>POSTED</strong> — lines and amounts are read-only. GL impact is in the linked posting
          group.
        </div>
      )}

      <div className="bg-white rounded-lg shadow p-6">
        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <div>
            <dt className="text-gray-500">Reference</dt>
            <dd className="font-mono font-medium">{run.reference_no}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Status</dt>
            <dd className="font-semibold">{run.status}</dd>
          </div>
          <div>
            <dt className="text-gray-500">As-of date (rate lookup)</dt>
            <dd>{formatDate(run.as_of_date)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Posting date</dt>
            <dd>{run.posting_date ? formatDate(run.posting_date) : '—'}</dd>
          </div>
          {run.posted_at && (
            <div>
              <dt className="text-gray-500">Posted at</dt>
              <dd>{formatDateTime(run.posted_at)}</dd>
            </div>
          )}
          {run.posting_group_id && (
            <div>
              <dt className="text-gray-500">Posting group</dt>
              <dd>
                <Link
                  to={`/app/posting-groups/${run.posting_group_id}`}
                  className="text-[#1F6F5C] font-mono text-xs hover:underline break-all"
                >
                  {run.posting_group_id}
                </Link>
              </dd>
            </div>
          )}
        </dl>
      </div>

      <section className="bg-white rounded-lg shadow p-6 space-y-2">
        <h2 className="text-lg font-semibold">Summary ({baseCc})</h2>
        <p className="text-sm text-gray-600">
          Unrealized FX adjustment implied by re-measuring open foreign balances at the as-of rate.
        </p>
        <dl className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm mt-2">
          <div>
            <dt className="text-gray-500">Carrying (original base)</dt>
            <dd className="tabular-nums font-medium">{formatMoney(sumOrig)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Revalued (closing base)</dt>
            <dd className="tabular-nums font-medium">{formatMoney(sumReval)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Net delta</dt>
            <dd className="tabular-nums font-semibold">{formatMoney(sumDelta)}</dd>
          </div>
        </dl>
      </section>

      {isDraft && (
        <div className="flex flex-wrap gap-3">
          <button
            type="button"
            onClick={() => onRefresh()}
            disabled={refreshing || !canPost}
            className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50"
          >
            {refreshing ? 'Refreshing…' : 'Refresh lines'}
          </button>
          {!canPost && (
            <span className="text-sm text-amber-800 self-center">Refresh/post requires accountant or admin.</span>
          )}
        </div>
      )}

      {isDraft && lines.length > 0 && canPost && (
        <section className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold mb-4">Post to ledger</h2>
          <form onSubmit={onPost} className="flex flex-wrap gap-4 items-end max-w-xl">
            <label className="text-sm">
              <span className="text-gray-600 block mb-1">Posting date</span>
              <input
                type="date"
                className="border rounded-md px-3 py-2"
                value={postingDate}
                onChange={(e) => setPostingDate(e.target.value)}
                required
              />
            </label>
            <label className="text-sm flex-1 min-w-[200px]">
              <span className="text-gray-600 block mb-1">Idempotency key (optional)</span>
              <input
                type="text"
                className="w-full border rounded-md px-3 py-2"
                value={idempotencyKey}
                onChange={(e) => setIdempotencyKey(e.target.value)}
                placeholder="e.g. fx-rev-2024-Q1"
              />
            </label>
            <button
              type="submit"
              disabled={posting}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {posting ? 'Posting…' : 'Post revaluation'}
            </button>
          </form>
        </section>
      )}

      <section className="bg-white rounded-lg shadow overflow-hidden">
        <h2 className="text-lg font-semibold p-6 pb-0">Lines</h2>
        {lines.length === 0 ? (
          <div className="p-6 text-gray-600">
            No lines. If you expected balances, ensure{' '}
            <Link to="/app/accounting/exchange-rates" className="text-[#1F6F5C] underline">
              exchange rates
            </Link>{' '}
            exist for each foreign currency on or before the as-of date, then use Refresh lines.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="border-b bg-gray-50 text-left">
                  <th className="p-3">Source</th>
                  <th className="p-3">Exposure CCY</th>
                  <th className="p-3 text-right">Original ({baseCc})</th>
                  <th className="p-3 text-right">Revalued ({baseCc})</th>
                  <th className="p-3 text-right">Delta ({baseCc})</th>
                </tr>
              </thead>
              <tbody>
                {lines.map((line) => (
                  <tr key={line.id} className="border-b border-gray-100">
                    <td className="p-3">
                      <span className="text-gray-600">{line.source_type}</span>
                      <span className="font-mono text-xs block break-all">{line.source_id}</span>
                    </td>
                    <td className="p-3 font-mono">{line.currency_code}</td>
                    <td className="p-3 text-right tabular-nums">{formatMoney(line.original_base_amount)}</td>
                    <td className="p-3 text-right tabular-nums">{formatMoney(line.revalued_base_amount)}</td>
                    <td className="p-3 text-right tabular-nums font-medium">{formatMoney(line.delta_amount)}</td>
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

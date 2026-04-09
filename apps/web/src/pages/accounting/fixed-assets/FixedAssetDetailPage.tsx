import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import type { FixedAsset, FixedAssetDepreciationLine, FixedAssetDisposal } from '@farm-erp/shared';
import { fixedAssetsApi } from '../../../api/fixedAssets';
import { PageHeader } from '../../../components/PageHeader';
import { useFormatting } from '../../../hooks/useFormatting';
import { useRole } from '../../../hooks/useRole';
import toast from 'react-hot-toast';
import { isReadOnlyAsset, moneyNum, primaryBook } from './fixedAssetUi';

function newIdempotencyKey(): string {
  return typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : `fa-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export default function FixedAssetDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { formatMoney, formatDate } = useFormatting();
  const { canPost } = useRole();

  const [asset, setAsset] = useState<FixedAsset | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [histLoading, setHistLoading] = useState(false);
  const [histError, setHistError] = useState<string | null>(null);
  const [depreciationLines, setDepreciationLines] = useState<FixedAssetDepreciationLine[]>([]);

  const [postingDate, setPostingDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [sourceAccount, setSourceAccount] = useState<'BANK' | 'CASH' | 'AP_CLEARING' | 'EQUITY_INJECTION'>('BANK');
  const [activateBusy, setActivateBusy] = useState(false);

  const [disposalDate, setDisposalDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [proceedsAmount, setProceedsAmount] = useState('');
  const [proceedsAccount, setProceedsAccount] = useState<'BANK' | 'CASH' | ''>('');
  const [disposalNotes, setDisposalNotes] = useState('');
  const [disposalBusy, setDisposalBusy] = useState(false);

  const [postDisposalDate, setPostDisposalDate] = useState(() => new Date().toISOString().split('T')[0]);

  const load = useCallback(async () => {
    if (!id) return;
    try {
      setLoading(true);
      setError(null);
      const a = await fixedAssetsApi.get(id);
      setAsset(a);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load asset');
      setAsset(null);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    load();
  }, [load]);

  const loadDepreciationHistory = useCallback(async () => {
    if (!id) return;
    try {
      setHistLoading(true);
      setHistError(null);
      const runs = await fixedAssetsApi.listDepreciationRuns();
      const details = await Promise.all(runs.map((r) => fixedAssetsApi.getDepreciationRun(r.id)));
      const lines = details
        .flatMap((r) => r.lines ?? [])
        .filter((l) => l.fixed_asset_id === id)
        .sort((a, b) => a.depreciation_end.localeCompare(b.depreciation_end));
      setDepreciationLines(lines);
    } catch (e) {
      setHistError(e instanceof Error ? e.message : 'Failed to load depreciation history');
    } finally {
      setHistLoading(false);
    }
  }, [id]);

  useEffect(() => {
    if (asset?.status === 'ACTIVE' || asset?.status === 'DISPOSED') {
      loadDepreciationHistory();
    }
  }, [asset?.status, loadDepreciationHistory]);

  const pb = asset ? primaryBook(asset) : undefined;
  const readOnly = asset ? isReadOnlyAsset(asset) : false;

  const activate = async () => {
    if (!id || !asset || asset.status !== 'DRAFT') return;
    try {
      setActivateBusy(true);
      await fixedAssetsApi.activate(id, {
        posting_date: postingDate,
        idempotency_key: newIdempotencyKey(),
        source_account: sourceAccount,
      });
      toast.success('Asset activated');
      await load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Activation failed');
    } finally {
      setActivateBusy(false);
    }
  };

  const createDisposal = async () => {
    if (!id || !asset || asset.status !== 'ACTIVE') return;
    const amt = parseFloat(proceedsAmount);
    if (!Number.isFinite(amt) || amt < 0) {
      toast.error('Proceeds amount must be a valid non-negative number.');
      return;
    }
    if (amt > 0 && !proceedsAccount) {
      toast.error('Select a proceeds account when proceeds are greater than zero.');
      return;
    }
    try {
      setDisposalBusy(true);
      await fixedAssetsApi.createDisposal(id, {
        disposal_date: disposalDate,
        proceeds_amount: amt,
        proceeds_account: amt > 0 ? (proceedsAccount as 'BANK' | 'CASH') : undefined,
        notes: disposalNotes.trim() || null,
      });
      toast.success('Disposal draft created');
      await load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Failed to create disposal');
    } finally {
      setDisposalBusy(false);
    }
  };

  const postDisposal = async (d: FixedAssetDisposal) => {
    try {
      setDisposalBusy(true);
      await fixedAssetsApi.postDisposal(d.id, {
        posting_date: postDisposalDate,
        idempotency_key: newIdempotencyKey(),
      });
      toast.success('Disposal posted');
      await load();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Post failed');
    } finally {
      setDisposalBusy(false);
    }
  };

  if (loading && !asset) {
    return <div className="text-gray-600 p-4">Loading…</div>;
  }

  if (error || !asset) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">{error || 'Not found'}</p>
        <Link to="/app/accounting/fixed-assets" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
          ← Back to fixed assets
        </Link>
      </div>
    );
  }

  const cc = asset.currency_code;
  const disposals = [...(asset.disposals ?? [])].sort((a, b) => a.disposal_date.localeCompare(b.disposal_date));

  return (
    <div className="space-y-6">
      <PageHeader
        title={`${asset.asset_code} · ${asset.name}`}
        backTo="/app/accounting/fixed-assets"
        breadcrumbs={[
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'Fixed assets', to: '/app/accounting/fixed-assets' },
          { label: asset.asset_code },
        ]}
      />

      {readOnly && (
        <div
          className="bg-gray-100 border border-gray-200 text-gray-800 rounded-lg px-4 py-3 text-sm"
          role="status"
        >
          This asset is <strong>{asset.status}</strong> — capitalisation, activation, and disposal actions are closed.
          Values are read-only.
        </div>
      )}

      <div className="flex flex-wrap gap-4 text-sm text-gray-600">
        <span>
          Status:{' '}
          <strong className="text-gray-900">{asset.status}</strong>
        </span>
        {asset.project?.name && (
          <span>
            Project: <strong className="text-gray-900">{asset.project.name}</strong>
          </span>
        )}
      </div>

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-lg font-semibold">Summary</h2>
        <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
          <div>
            <dt className="text-gray-500">Category</dt>
            <dd className="mt-1 font-medium">{asset.category}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Acquisition date</dt>
            <dd className="mt-1">{formatDate(asset.acquisition_date)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">In service</dt>
            <dd className="mt-1">{asset.in_service_date ? formatDate(asset.in_service_date) : '—'}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Acquisition cost</dt>
            <dd className="mt-1 tabular-nums">{formatMoney(asset.acquisition_cost, { currencyCode: cc })}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Residual / useful life</dt>
            <dd className="mt-1">
              {formatMoney(asset.residual_value, { currencyCode: cc })} / {asset.useful_life_months} mo
            </dd>
          </div>
          <div>
            <dt className="text-gray-500">Depreciation</dt>
            <dd className="mt-1">{asset.depreciation_method}</dd>
          </div>
        </dl>
        {asset.notes && (
          <div className="text-sm">
            <span className="text-gray-500">Notes</span>
            <p className="mt-1 whitespace-pre-wrap">{asset.notes}</p>
          </div>
        )}
      </section>

      {pb && (
        <section className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold mb-4">PRIMARY book</h2>
          <dl className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div>
              <dt className="text-gray-500">Asset cost</dt>
              <dd className="mt-1 tabular-nums">{formatMoney(pb.asset_cost, { currencyCode: cc })}</dd>
            </div>
            <div>
              <dt className="text-gray-500">Accumulated depreciation</dt>
              <dd className="mt-1 tabular-nums">{formatMoney(pb.accumulated_depreciation, { currencyCode: cc })}</dd>
            </div>
            <div>
              <dt className="text-gray-500">Carrying amount</dt>
              <dd className="mt-1 tabular-nums font-semibold">{formatMoney(pb.carrying_amount, { currencyCode: cc })}</dd>
            </div>
          </dl>
        </section>
      )}

      {asset.status === 'DRAFT' && !readOnly && (
        <section className="bg-white rounded-lg shadow p-6 space-y-4">
          <h2 className="text-lg font-semibold">Activate asset</h2>
          <p className="text-sm text-gray-600">
            Posts the acquisition to the ledger. Cost and useful life cannot be changed after activation.
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 max-w-xl">
            <label className="text-sm">
              <span className="text-gray-600 block mb-1">Posting date</span>
              <input
                type="date"
                className="w-full border rounded-md px-3 py-2"
                value={postingDate}
                onChange={(e) => setPostingDate(e.target.value)}
                disabled={!canPost}
              />
            </label>
            <label className="text-sm sm:col-span-2">
              <span className="text-gray-600 block mb-1">Source account</span>
              <select
                className="w-full border rounded-md px-3 py-2"
                value={sourceAccount}
                onChange={(e) =>
                  setSourceAccount(e.target.value as typeof sourceAccount)
                }
                disabled={!canPost}
              >
                <option value="BANK">BANK</option>
                <option value="CASH">CASH</option>
                <option value="AP_CLEARING">AP_CLEARING</option>
                <option value="EQUITY_INJECTION">EQUITY_INJECTION</option>
              </select>
            </label>
          </div>
          {!canPost && (
            <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
              Only tenant administrators and accountants can post activation.
            </p>
          )}
          <button
            type="button"
            onClick={() => activate()}
            disabled={!canPost || activateBusy}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {activateBusy ? 'Posting…' : 'Activate & post'}
          </button>
        </section>
      )}

      {asset.activation_posting_group && (
        <section className="text-sm">
          <span className="text-gray-500">Activation posting: </span>
          <Link
            className="text-[#1F6F5C] hover:underline"
            to={`/app/posting-groups/${asset.activation_posting_group.id}`}
          >
            View posting group
          </Link>
        </section>
      )}

      {(asset.status === 'ACTIVE' || asset.status === 'DISPOSED') && (
        <section className="bg-white rounded-lg shadow p-6 space-y-3">
          <div className="flex flex-wrap justify-between gap-2 items-center">
            <h2 className="text-lg font-semibold">Depreciation history</h2>
            <button
              type="button"
              onClick={() => loadDepreciationHistory()}
              className="text-sm text-[#1F6F5C] hover:underline"
            >
              Refresh
            </button>
          </div>
          {histLoading && <div className="text-gray-600 text-sm">Loading lines…</div>}
          {histError && (
            <div className="bg-red-50 border border-red-200 text-red-800 rounded-md p-3 text-sm">{histError}</div>
          )}
          {!histLoading && !histError && depreciationLines.length === 0 && (
            <p className="text-gray-600 text-sm">No depreciation lines for this asset yet.</p>
          )}
          {depreciationLines.length > 0 && (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b bg-gray-50 text-left">
                    <th className="p-2">Period</th>
                    <th className="p-2">Run</th>
                    <th className="p-2 text-right">Depreciation</th>
                    <th className="p-2 text-right">Closing carrying</th>
                  </tr>
                </thead>
                <tbody>
                  {depreciationLines.map((line) => (
                    <tr key={line.id} className="border-b border-gray-100">
                      <td className="p-2">
                        {formatDate(line.depreciation_start)} – {formatDate(line.depreciation_end)}
                      </td>
                      <td className="p-2">
                        <Link
                          className="text-[#1F6F5C] hover:underline"
                          to={`/app/accounting/fixed-assets/depreciation-runs/${line.depreciation_run_id}`}
                        >
                          Open run
                        </Link>
                      </td>
                      <td className="p-2 text-right tabular-nums">
                        {formatMoney(line.depreciation_amount, { currencyCode: cc })}
                      </td>
                      <td className="p-2 text-right tabular-nums">
                        {formatMoney(line.closing_carrying_amount, { currencyCode: cc })}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <p className="text-xs text-gray-500">
            <Link className="text-[#1F6F5C] hover:underline" to="/app/accounting/fixed-assets/depreciation-runs">
              All depreciation runs →
            </Link>
          </p>
        </section>
      )}

      {(asset.status === 'ACTIVE' || asset.status === 'DISPOSED' || disposals.length > 0) && (
        <section className="bg-white rounded-lg shadow p-6 space-y-4">
          <h2 className="text-lg font-semibold">Disposal</h2>
          {disposals.length === 0 && asset.status === 'ACTIVE' && (
            <>
              <p className="text-sm text-gray-600">Create a draft disposal when you are ready to derecognise the asset.</p>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
                <label className="text-sm">
                  <span className="text-gray-600 block mb-1">Disposal date</span>
                  <input
                    type="date"
                    className="w-full border rounded-md px-3 py-2"
                    value={disposalDate}
                    onChange={(e) => setDisposalDate(e.target.value)}
                    disabled={!canPost || readOnly}
                  />
                </label>
                <label className="text-sm">
                  <span className="text-gray-600 block mb-1">Proceeds amount</span>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    className="w-full border rounded-md px-3 py-2"
                    value={proceedsAmount}
                    onChange={(e) => setProceedsAmount(e.target.value)}
                    disabled={!canPost || readOnly}
                  />
                </label>
                <label className="text-sm sm:col-span-2">
                  <span className="text-gray-600 block mb-1">Proceeds account (required if proceeds &gt; 0)</span>
                  <select
                    className="w-full border rounded-md px-3 py-2"
                    value={proceedsAccount}
                    onChange={(e) => setProceedsAccount(e.target.value as 'BANK' | 'CASH' | '')}
                    disabled={!canPost || readOnly}
                  >
                    <option value="">—</option>
                    <option value="BANK">BANK</option>
                    <option value="CASH">CASH</option>
                  </select>
                </label>
                <label className="text-sm sm:col-span-2">
                  <span className="text-gray-600 block mb-1">Notes</span>
                  <textarea
                    className="w-full border rounded-md px-3 py-2"
                    rows={2}
                    value={disposalNotes}
                    onChange={(e) => setDisposalNotes(e.target.value)}
                    disabled={!canPost || readOnly}
                  />
                </label>
              </div>
              {!canPost && (
                <p className="text-sm text-amber-800">Only tenant administrators and accountants can create disposals.</p>
              )}
              <button
                type="button"
                onClick={() => createDisposal()}
                disabled={!canPost || disposalBusy || readOnly}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md disabled:opacity-50"
              >
                {disposalBusy ? 'Working…' : 'Create draft disposal'}
              </button>
            </>
          )}

          {disposals.length > 0 && (
            <div className="space-y-4">
              {disposals.map((d) => (
                <div
                  key={d.id}
                  className="border border-gray-200 rounded-lg p-4 space-y-2"
                  data-testid={`disposal-${d.id}`}
                >
                  <div className="flex flex-wrap gap-3 text-sm">
                    <span
                      className={`px-2 py-0.5 rounded text-xs ${
                        d.status === 'POSTED' ? 'bg-green-100 text-green-900' : 'bg-amber-100 text-amber-900'
                      }`}
                    >
                      {d.status}
                    </span>
                    <span>Date: {formatDate(d.disposal_date)}</span>
                    <span>
                      Proceeds: {formatMoney(d.proceeds_amount, { currencyCode: cc })}
                      {d.proceeds_account ? ` (${d.proceeds_account})` : ''}
                    </span>
                  </div>
                  {d.carrying_amount_at_post != null && (
                    <p className="text-sm text-gray-600">
                      Carrying at post: {formatMoney(d.carrying_amount_at_post, { currencyCode: cc })}
                    </p>
                  )}
                  {(moneyNum(d.gain_amount) > 0 || moneyNum(d.loss_amount) > 0) && (
                    <p className="text-sm">
                      {moneyNum(d.gain_amount) > 0 && (
                        <span className="text-green-800">
                          Gain: {formatMoney(d.gain_amount ?? 0, { currencyCode: cc })}
                        </span>
                      )}
                      {moneyNum(d.loss_amount) > 0 && (
                        <span className="text-red-800 ml-2">
                          Loss: {formatMoney(d.loss_amount ?? 0, { currencyCode: cc })}
                        </span>
                      )}
                    </p>
                  )}
                  {d.posting_group_id && (
                    <Link className="text-sm text-[#1F6F5C] hover:underline" to={`/app/posting-groups/${d.posting_group_id}`}>
                      View disposal posting group
                    </Link>
                  )}
                  {d.status === 'DRAFT' && canPost && !readOnly && (
                    <div className="flex flex-wrap items-end gap-3 pt-2">
                      <label className="text-sm">
                        <span className="text-gray-600 block mb-1">Posting date</span>
                        <input
                          type="date"
                          className="border rounded-md px-3 py-2"
                          value={postDisposalDate}
                          onChange={(e) => setPostDisposalDate(e.target.value)}
                        />
                      </label>
                      <button
                        type="button"
                        onClick={() => postDisposal(d)}
                        disabled={disposalBusy}
                        className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md disabled:opacity-50"
                      >
                        Post disposal
                      </button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </section>
      )}
    </div>
  );
}

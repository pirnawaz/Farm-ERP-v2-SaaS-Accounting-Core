import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import type { FixedAsset, FixedAssetDepreciationLine, FixedAssetDepreciationRun } from '@farm-erp/shared';
import { fixedAssetsApi } from '../../../api/fixedAssets';
import { PageHeader } from '../../../components/PageHeader';
import { useFormatting } from '../../../hooks/useFormatting';
import { moneyNum, primaryBook } from './fixedAssetUi';

type ReportTab = 'register' | 'schedule' | 'carrying';

export default function FixedAssetsPage() {
  const { formatMoney, formatDate } = useFormatting();
  const [tab, setTab] = useState<ReportTab>('register');
  const [assets, setAssets] = useState<FixedAsset[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<string>('');

  const [scheduleRuns, setScheduleRuns] = useState<FixedAssetDepreciationRun[]>([]);
  const [scheduleLoading, setScheduleLoading] = useState(false);
  const [scheduleError, setScheduleError] = useState<string | null>(null);
  const [scheduleLines, setScheduleLines] = useState<
    { run: FixedAssetDepreciationRun; line: FixedAssetDepreciationLine }[]
  >([]);

  const loadAssets = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const rows = await fixedAssetsApi.list({ status: statusFilter || undefined });
      setAssets(rows);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load fixed assets');
    } finally {
      setLoading(false);
    }
  }, [statusFilter]);

  useEffect(() => {
    loadAssets();
  }, [loadAssets]);

  useEffect(() => {
    if (tab !== 'schedule') return;
    let cancelled = false;
    (async () => {
      try {
        setScheduleLoading(true);
        setScheduleError(null);
        const runs = await fixedAssetsApi.listDepreciationRuns();
        if (cancelled) return;
        setScheduleRuns(runs);
        const posted = runs.filter((r) => r.status === 'POSTED');
        const details = await Promise.all(posted.map((r) => fixedAssetsApi.getDepreciationRun(r.id)));
        if (cancelled) return;
        const merged: { run: FixedAssetDepreciationRun; line: FixedAssetDepreciationLine }[] = [];
        for (const run of details) {
          for (const line of run.lines ?? []) {
            merged.push({ run, line });
          }
        }
        merged.sort((a, b) => a.line.depreciation_end.localeCompare(b.line.depreciation_end));
        setScheduleLines(merged);
      } catch (e) {
        if (!cancelled) {
          setScheduleError(e instanceof Error ? e.message : 'Failed to load depreciation schedule');
        }
      } finally {
        if (!cancelled) setScheduleLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [tab]);

  const carryingRows = useMemo(() => {
    return assets.map((a) => {
      const pb = primaryBook(a);
      return {
        asset: a,
        carrying: pb ? moneyNum(pb.carrying_amount) : null,
        cost: moneyNum(a.acquisition_cost),
      };
    });
  }, [assets]);

  const totalCarrying = useMemo(
    () => carryingRows.reduce((s, r) => s + (r.carrying ?? 0), 0),
    [carryingRows]
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title="Fixed assets"
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'Fixed assets' },
        ]}
        right={
          <div className="flex flex-wrap gap-2">
            <Link
              to="/app/accounting/fixed-assets/depreciation-runs"
              className="px-4 py-2 border border-gray-300 rounded-md text-gray-800 hover:bg-gray-50"
            >
              Depreciation runs
            </Link>
            <Link
              to="/app/accounting/fixed-assets/new"
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
            >
              New asset
            </Link>
          </div>
        }
      />

      <div className="flex flex-wrap gap-2 border-b border-gray-200 pb-2">
        {(
          [
            ['register', 'Asset register'],
            ['schedule', 'Depreciation schedule'],
            ['carrying', 'Carrying amount summary'],
          ] as const
        ).map(([k, label]) => (
          <button
            key={k}
            type="button"
            onClick={() => setTab(k)}
            className={`px-3 py-1.5 rounded-md text-sm ${
              tab === k ? 'bg-[#1F6F5C] text-white' : 'bg-gray-100 text-gray-800 hover:bg-gray-200'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'register' && (
        <section className="space-y-4">
          <div className="flex flex-wrap items-end gap-4">
            <label className="text-sm text-gray-600">
              Status
              <select
                className="ml-2 border rounded-md px-2 py-1"
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
              >
                <option value="">All</option>
                <option value="DRAFT">DRAFT</option>
                <option value="ACTIVE">ACTIVE</option>
                <option value="DISPOSED">DISPOSED</option>
                <option value="RETIRED">RETIRED</option>
              </select>
            </label>
            <button
              type="button"
              onClick={() => loadAssets()}
              className="text-sm text-[#1F6F5C] hover:underline"
            >
              Refresh
            </button>
          </div>

          {loading && <div className="text-gray-600">Loading…</div>}
          {error && (
            <div className="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4" role="alert">
              {error}
            </div>
          )}
          {!loading && !error && assets.length === 0 && (
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-6 text-gray-600">
              No fixed assets yet. Create a draft asset to get started.
            </div>
          )}
          {!loading && !error && assets.length > 0 && (
            <div className="overflow-x-auto bg-white rounded-lg shadow">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b bg-gray-50 text-left">
                    <th className="p-3">Code</th>
                    <th className="p-3">Name</th>
                    <th className="p-3">Status</th>
                    <th className="p-3 text-right">Cost</th>
                    <th className="p-3 text-right">Carrying (PRIMARY)</th>
                  </tr>
                </thead>
                <tbody>
                  {assets.map((a) => {
                    const pb = primaryBook(a);
                    const cc = a.currency_code;
                    return (
                      <tr key={a.id} className="border-b border-gray-100 hover:bg-gray-50">
                        <td className="p-3">
                          <Link className="text-[#1F6F5C] hover:underline" to={`/app/accounting/fixed-assets/${a.id}`}>
                            {a.asset_code}
                          </Link>
                        </td>
                        <td className="p-3">{a.name}</td>
                        <td className="p-3">
                          <span
                            className={`px-2 py-0.5 rounded text-xs ${
                              a.status === 'ACTIVE'
                                ? 'bg-green-100 text-green-900'
                                : a.status === 'DRAFT'
                                  ? 'bg-amber-100 text-amber-900'
                                  : 'bg-gray-100 text-gray-800'
                            }`}
                          >
                            {a.status}
                          </span>
                        </td>
                        <td className="p-3 text-right tabular-nums">
                          {formatMoney(a.acquisition_cost, { currencyCode: cc })}
                        </td>
                        <td className="p-3 text-right tabular-nums">
                          {pb ? formatMoney(pb.carrying_amount, { currencyCode: cc }) : '—'}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>
      )}

      {tab === 'schedule' && (
        <section className="space-y-4">
          {scheduleLoading && <div className="text-gray-600">Loading schedule…</div>}
          {scheduleError && (
            <div className="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4">{scheduleError}</div>
          )}
          {!scheduleLoading && !scheduleError && scheduleLines.length === 0 && (
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-6 text-gray-600">
              No posted depreciation lines yet. Generate and post a depreciation run first.
            </div>
          )}
          {!scheduleLoading && scheduleLines.length > 0 && (
            <div className="overflow-x-auto bg-white rounded-lg shadow text-sm">
              <table className="min-w-full">
                <thead>
                  <tr className="border-b bg-gray-50 text-left">
                    <th className="p-3">Run</th>
                    <th className="p-3">Period</th>
                    <th className="p-3">Asset</th>
                    <th className="p-3 text-right">Depreciation</th>
                    <th className="p-3 text-right">Closing carrying</th>
                  </tr>
                </thead>
                <tbody>
                  {scheduleLines.map(({ run, line }) => {
                    const fa = line.fixed_asset;
                    const cc = fa?.currency_code ?? 'USD';
                    return (
                      <tr key={line.id} className="border-b border-gray-100">
                        <td className="p-3">
                          <Link
                            className="text-[#1F6F5C] hover:underline"
                            to={`/app/accounting/fixed-assets/depreciation-runs/${run.id}`}
                          >
                            {run.reference_no}
                          </Link>
                        </td>
                        <td className="p-3">
                          {formatDate(line.depreciation_start)} – {formatDate(line.depreciation_end)}
                        </td>
                        <td className="p-3">
                          {fa ? (
                            <Link className="text-[#1F6F5C] hover:underline" to={`/app/accounting/fixed-assets/${fa.id}`}>
                              {fa.asset_code}
                            </Link>
                          ) : (
                            '—'
                          )}
                        </td>
                        <td className="p-3 text-right tabular-nums">
                          {formatMoney(line.depreciation_amount, { currencyCode: cc })}
                        </td>
                        <td className="p-3 text-right tabular-nums">
                          {formatMoney(line.closing_carrying_amount, { currencyCode: cc })}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
          {!scheduleLoading && scheduleRuns.length > 0 && (
            <p className="text-xs text-gray-500">
              Posted runs: {scheduleRuns.filter((r) => r.status === 'POSTED').length} of {scheduleRuns.length}.
            </p>
          )}
        </section>
      )}

      {tab === 'carrying' && (
        <section className="space-y-4">
          {loading && <div className="text-gray-600">Loading…</div>}
          {error && (
            <div className="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4">{error}</div>
          )}
          {!loading && !error && carryingRows.length === 0 && (
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-6 text-gray-600">No assets to summarise.</div>
          )}
          {!loading && carryingRows.length > 0 && (
            <>
              <div className="bg-white rounded-lg shadow p-4 flex flex-wrap gap-6">
                <div>
                  <div className="text-xs text-gray-500 uppercase tracking-wide">Total carrying (PRIMARY)</div>
                  <div className="text-xl font-semibold tabular-nums">
                    {formatMoney(totalCarrying, { currencyCode: carryingRows[0]?.asset.currency_code ?? 'USD' })}
                  </div>
                </div>
                <div>
                  <div className="text-xs text-gray-500 uppercase tracking-wide">Assets</div>
                  <div className="text-xl font-semibold">{carryingRows.length}</div>
                </div>
              </div>
              <div className="overflow-x-auto bg-white rounded-lg shadow">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b bg-gray-50 text-left">
                      <th className="p-3">Code</th>
                      <th className="p-3">Status</th>
                      <th className="p-3 text-right">Acquisition cost</th>
                      <th className="p-3 text-right">Carrying</th>
                    </tr>
                  </thead>
                  <tbody>
                    {carryingRows.map(({ asset, carrying, cost }) => {
                      const cc = asset.currency_code;
                      return (
                        <tr key={asset.id} className="border-b border-gray-100">
                          <td className="p-3">
                            <Link className="text-[#1F6F5C] hover:underline" to={`/app/accounting/fixed-assets/${asset.id}`}>
                              {asset.asset_code}
                            </Link>
                          </td>
                          <td className="p-3">{asset.status}</td>
                          <td className="p-3 text-right tabular-nums">{formatMoney(cost, { currencyCode: cc })}</td>
                          <td className="p-3 text-right tabular-nums">
                            {carrying != null ? formatMoney(carrying, { currencyCode: cc }) : '—'}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </section>
      )}
    </div>
  );
}

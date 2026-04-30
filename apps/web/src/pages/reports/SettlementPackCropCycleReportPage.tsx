import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { PageHeader } from '../../components/PageHeader';
import { ReportPage, ReportSectionCard } from '../../components/report';
import { useFormatting } from '../../hooks/useFormatting';
import { useCropCycles } from '../../hooks/useCropCycles';
import { settlementPackPhase1Api, type IncludeRegister, type SettlementPackPhase1Response } from '../../api/settlementPackPhase1';

function startOfYear(): string {
  const d = new Date();
  return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

export default function SettlementPackCropCycleReportPage() {
  const { formatMoney } = useFormatting();
  const { data: cropCycles = [] } = useCropCycles();
  const [searchParams] = useSearchParams();

  const [cropCycleId, setCropCycleId] = useState('');
  const [from, setFrom] = useState(startOfYear);
  const [to, setTo] = useState(today);
  const [includeRegister, setIncludeRegister] = useState<IncludeRegister>('allocation');
  const [allocationPage, setAllocationPage] = useState(1);
  const [ledgerPage, setLedgerPage] = useState(1);

  useEffect(() => {
    const cc = searchParams.get('crop_cycle_id');
    const f = searchParams.get('from');
    const t = searchParams.get('to');
    if (cc) setCropCycleId(cc);
    if (f) setFrom(f);
    if (t) setTo(t);
  }, [searchParams]);

  const params = useMemo(
    () => ({
      crop_cycle_id: cropCycleId,
      from,
      to,
      include_register: includeRegister,
      allocation_page: allocationPage,
      allocation_per_page: 200,
      ledger_page: ledgerPage,
      ledger_per_page: 200,
      register_order: 'date_asc' as const,
      bucket: 'total' as const,
      include_projects_breakdown: true,
    }),
    [cropCycleId, from, to, includeRegister, allocationPage, ledgerPage]
  );

  const { data, isLoading, error } = useQuery<SettlementPackPhase1Response, Error>({
    queryKey: ['reports', 'settlement-pack-phase1', 'crop-cycle', params],
    queryFn: async () => {
      const res = await settlementPackPhase1Api.getCropCycle(params);
      return (res as unknown as { data?: SettlementPackPhase1Response }).data ?? res;
    },
    enabled: Boolean(cropCycleId && from && to),
    placeholderData: keepPreviousData,
  });

  const totals = data?.totals;
  const alloc = data?.register?.allocation_rows;
  const ledger = data?.register?.ledger_lines;

  const downloadBlob = async (blob: Blob, filename: string) => {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };
  const exportCsv = async (url: string, filename: string) => downloadBlob(await settlementPackPhase1Api.downloadCsv(url), filename);
  const exportPdf = async (url: string, filename: string) => downloadBlob(await settlementPackPhase1Api.downloadPdf(url), filename);

  return (
    <ReportPage>
      <PageHeader
        title="Settlement pack (Phase 1) — crop cycle"
        description="Read-only rollup across all projects in the crop cycle."
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Reports', to: '/app/reports' },
          { label: 'Settlement pack (crop cycle)' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm text-gray-700 mb-1">Crop cycle</label>
            <select
              value={cropCycleId}
              onChange={(e) => {
                setCropCycleId(e.target.value);
                setAllocationPage(1);
                setLedgerPage(1);
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            >
              <option value="">Select…</option>
              {cropCycles.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">From</label>
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-full px-3 py-2 border border-gray-300 rounded-md" />
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">To</label>
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-full px-3 py-2 border border-gray-300 rounded-md" />
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">Register</label>
            <select
              value={includeRegister}
              onChange={(e) => {
                setIncludeRegister(e.target.value as IncludeRegister);
                setAllocationPage(1);
                setLedgerPage(1);
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            >
              <option value="none">None</option>
              <option value="allocation">Allocation register</option>
              <option value="ledger">Ledger audit register</option>
              <option value="both">Both</option>
            </select>
          </div>
        </div>
      </div>

      {!cropCycleId ? <div className="text-center text-sm text-gray-500 py-8">Select a crop cycle to load the pack.</div> : null}
      {isLoading ? <div className="text-gray-600">Loading…</div> : null}
      {error ? <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-red-800">{error.message}</div> : null}

      {data && totals ? (
        <>
          <section className="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div className="rounded-lg bg-white shadow p-4">
              <div className="text-xs text-gray-500 uppercase">Harvest production value</div>
              <div className="text-xl font-semibold mt-1 tabular-nums">
                {totals.harvest_production.value ? formatMoney(Number(totals.harvest_production.value)) : '—'}
              </div>
              <div className="text-xs text-gray-500 mt-1 tabular-nums">{totals.harvest_production.qty ? `${totals.harvest_production.qty} qty` : ''}</div>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <div className="text-xs text-gray-500 uppercase">Ledger revenue (total)</div>
              <div className="text-xl font-semibold mt-1 tabular-nums">{formatMoney(Number(totals.ledger_revenue.total))}</div>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <div className="text-xs text-gray-500 uppercase">Total cost</div>
              <div className="text-xl font-semibold mt-1 tabular-nums">{formatMoney(Number(totals.costs.total))}</div>
              <div className="text-xs text-gray-500 mt-1 tabular-nums">Premium {formatMoney(Number(totals.costs.credit_premium))}</div>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <div className="text-xs text-gray-500 uppercase">Net (ledger)</div>
              <div className="text-xl font-semibold mt-1 tabular-nums">{formatMoney(Number(totals.net.net_ledger_result))}</div>
              <div className="text-xs text-gray-500 mt-1 tabular-nums">
                Net (harvest) {totals.net.net_harvest_production_result ? formatMoney(Number(totals.net.net_harvest_production_result)) : '—'}
              </div>
            </div>
          </section>

          <ReportSectionCard>
            <div className="p-4 border-b flex items-center justify-between gap-3">
              <h2 className="text-sm font-semibold text-gray-900">Exports</h2>
              <div className="flex items-center gap-2">
                <button type="button" className="px-3 py-2 border rounded text-sm hover:bg-gray-50" onClick={() => exportCsv(data.exports.csv.summary_url, 'settlement-pack-crop-cycle-summary.csv')}>
                  Download summary CSV
                </button>
                <button
                  type="button"
                  className="px-3 py-2 border rounded text-sm hover:bg-gray-50"
                  onClick={() => exportCsv(data.exports.csv.allocation_register_url, 'settlement-pack-crop-cycle-allocation-register.csv')}
                >
                  Allocation CSV
                </button>
                <button
                  type="button"
                  className="px-3 py-2 border rounded text-sm hover:bg-gray-50"
                  onClick={() => exportCsv(data.exports.csv.ledger_audit_register_url, 'settlement-pack-crop-cycle-ledger-audit-register.csv')}
                >
                  Ledger CSV
                </button>
                <button type="button" className="px-3 py-2 bg-gray-800 text-white rounded text-sm hover:bg-gray-900" onClick={() => exportPdf(data.exports.pdf.url, 'settlement-pack-crop-cycle.pdf')}>
                  PDF
                </button>
              </div>
            </div>
            <div className="p-4 text-sm text-gray-600">Note: harvest production value is not sales revenue. This pack is read-only and excludes reversed posting groups.</div>
          </ReportSectionCard>

          <ReportSectionCard>
            <div className="p-4 border-b">
              <h2 className="text-sm font-semibold text-gray-900">Cost buckets</h2>
            </div>
            <div className="p-4 overflow-x-auto">
              <table className="min-w-[700px] w-full text-sm">
                <thead className="bg-gray-50 text-gray-600">
                  <tr>
                    <th className="text-left px-3 py-2">Bucket</th>
                    <th className="text-right px-3 py-2">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {[
                    ['Inputs', totals.costs.inputs],
                    ['Labour', totals.costs.labour],
                    ['Machinery', totals.costs.machinery],
                    ['Credit premium', totals.costs.credit_premium],
                    ['Other', totals.costs.other],
                    ['Total', totals.costs.total],
                  ].map(([label, amt]) => (
                    <tr key={label} className="border-t">
                      <td className="px-3 py-2">{label}</td>
                      <td className="px-3 py-2 text-right tabular-nums font-medium">{amt}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </ReportSectionCard>

          {alloc ? (
            <ReportSectionCard>
              <div className="p-4 border-b flex items-center justify-between">
                <h2 className="text-sm font-semibold text-gray-900">Allocation register</h2>
                <div className="text-xs text-gray-500 tabular-nums">
                  Page {alloc.page} · {alloc.total_rows} rows
                </div>
              </div>
              <div className="overflow-x-auto border rounded">
                <table className="min-w-[980px] w-full text-sm">
                  <thead className="bg-gray-50 text-gray-600">
                    <tr>
                      <th className="text-left px-3 py-2">Date</th>
                      <th className="text-left px-3 py-2">Project</th>
                      <th className="text-left px-3 py-2">Source</th>
                      <th className="text-left px-3 py-2">Allocation type</th>
                      <th className="text-right px-3 py-2">Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    {alloc.rows.map((r) => (
                      <tr key={r.allocation_row_id} className="border-t">
                        <td className="px-3 py-2">{r.posting_date}</td>
                        <td className="px-3 py-2 tabular-nums">{r.project_id ?? '—'}</td>
                        <td className="px-3 py-2">{r.source_type}</td>
                        <td className="px-3 py-2">{r.allocation_type}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{r.amount}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="p-4 flex items-center justify-end gap-2">
                <button type="button" className="px-3 py-2 border rounded text-sm disabled:opacity-50" disabled={alloc.page <= 1} onClick={() => setAllocationPage((p) => Math.max(1, p - 1))}>
                  Prev
                </button>
                <button
                  type="button"
                  className="px-3 py-2 border rounded text-sm disabled:opacity-50"
                  disabled={alloc.page * alloc.per_page >= alloc.total_rows}
                  onClick={() => setAllocationPage((p) => p + 1)}
                >
                  Next
                </button>
              </div>
            </ReportSectionCard>
          ) : null}

          {ledger ? (
            <ReportSectionCard>
              <div className="p-4 border-b flex items-center justify-between">
                <h2 className="text-sm font-semibold text-gray-900">Ledger audit register</h2>
                <div className="text-xs text-gray-500 tabular-nums">
                  Page {ledger.page} · {ledger.total_rows} rows
                </div>
              </div>
              <div className="overflow-x-auto border rounded">
                <table className="min-w-[1180px] w-full text-sm">
                  <thead className="bg-gray-50 text-gray-600">
                    <tr>
                      <th className="text-left px-3 py-2">Date</th>
                      <th className="text-left px-3 py-2">Project</th>
                      <th className="text-left px-3 py-2">Source</th>
                      <th className="text-left px-3 py-2">Account</th>
                      <th className="text-right px-3 py-2">Debit</th>
                      <th className="text-right px-3 py-2">Credit</th>
                      <th className="text-left px-3 py-2">Allocation type</th>
                    </tr>
                  </thead>
                  <tbody>
                    {ledger.rows.map((r) => (
                      <tr key={`${r.ledger_entry_id}-${r.allocation_row_id}`} className="border-t">
                        <td className="px-3 py-2">{r.posting_date}</td>
                        <td className="px-3 py-2 tabular-nums">{r.project_id}</td>
                        <td className="px-3 py-2">{r.source_type}</td>
                        <td className="px-3 py-2 tabular-nums">
                          {r.account_code} <span className="text-gray-500">{r.account_name}</span>
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">{r.debit_amount}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{r.credit_amount}</td>
                        <td className="px-3 py-2">{r.allocation_type}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="p-4 flex items-center justify-end gap-2">
                <button type="button" className="px-3 py-2 border rounded text-sm disabled:opacity-50" disabled={ledger.page <= 1} onClick={() => setLedgerPage((p) => Math.max(1, p - 1))}>
                  Prev
                </button>
                <button
                  type="button"
                  className="px-3 py-2 border rounded text-sm disabled:opacity-50"
                  disabled={ledger.page * ledger.per_page >= ledger.total_rows}
                  onClick={() => setLedgerPage((p) => p + 1)}
                >
                  Next
                </button>
              </div>
            </ReportSectionCard>
          ) : null}
        </>
      ) : null}
    </ReportPage>
  );
}


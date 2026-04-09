import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  SettlementPackResponse,
  SettlementPackRegisterRow,
  SettlementPackSummary,
} from '@farm-erp/shared';
import { settlementPackApi } from '../api/settlementPack';
import { PageHeader } from '../components/PageHeader';
import { useFormatting } from '../hooks/useFormatting';
import { term } from '../config/terminology';
import toast from 'react-hot-toast';

function isFinalizedStatus(status: string): boolean {
  return status === 'FINALIZED' || status === 'FINAL';
}

export default function SettlementPackDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { formatMoney, formatDate } = useFormatting();
  const [pack, setPack] = useState<SettlementPackResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [generatingVersion, setGeneratingVersion] = useState(false);
  const [finalizing, setFinalizing] = useState(false);
  const [exportingPdf, setExportingPdf] = useState(false);
  const [downloadingPdf, setDownloadingPdf] = useState(false);

  const loadPack = useCallback(async () => {
    if (!id) return;
    try {
      setLoading(true);
      setError(null);
      const data = await settlementPackApi.get(id);
      setPack(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load settlement pack');
    } finally {
      setLoading(false);
    }
  }, [id]);

  const refreshPack = useCallback(async () => {
    if (!id) return;
    try {
      const data = await settlementPackApi.get(id);
      setPack(data);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to refresh pack');
    }
  }, [id]);

  useEffect(() => {
    loadPack();
  }, [loadPack]);

  const handleGenerateVersion = async () => {
    if (!id || !pack || pack.is_read_only) return;
    setGeneratingVersion(true);
    try {
      await settlementPackApi.generateVersion(id);
      toast.success('New snapshot version created');
      await refreshPack();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to generate version');
    } finally {
      setGeneratingVersion(false);
    }
  };

  const handleFinalize = async () => {
    if (!id || !pack || pack.is_read_only) return;
    setFinalizing(true);
    try {
      await settlementPackApi.finalize(id);
      toast.success('Settlement pack finalized');
      await refreshPack();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to finalize');
    } finally {
      setFinalizing(false);
    }
  };

  const handleExportPdf = async () => {
    if (!id || !pack || !isFinalizedStatus(pack.status)) return;
    setExportingPdf(true);
    try {
      await settlementPackApi.exportPdf(id);
      toast.success('PDF bundle generated');
      await refreshPack();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to export PDF');
    } finally {
      setExportingPdf(false);
    }
  };

  const handleDownloadPdf = async () => {
    if (!id) return;
    setDownloadingPdf(true);
    try {
      const blob = await settlementPackApi.downloadPdfBlob(id);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `settlement-pack-${id.slice(0, 8)}.pdf`;
      a.rel = 'noopener';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      toast.success('PDF downloaded');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Could not download PDF');
    } finally {
      setDownloadingPdf(false);
    }
  };

  if (loading) {
    return <div className="text-gray-600 p-4">Loading settlement pack…</div>;
  }

  if (error || !pack) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">{error || 'Settlement pack not found'}</p>
        <Link to="/app/settlement-packs" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
          ← Back to settlement packs
        </Link>
      </div>
    );
  }

  const summary = (pack.summary_json || {}) as SettlementPackSummary;
  const rows = (pack.register_rows || []) as SettlementPackRegisterRow[];
  const versions = pack.versions ?? [];
  const finalized = isFinalizedStatus(pack.status);
  const canMutate = !pack.is_read_only && !finalized;
  const canGenerateVersion = canMutate;
  const canFinalize = canMutate;

  return (
    <div className="space-y-6">
      <PageHeader
        title={pack.register_version ? `Settlement pack · ${pack.register_version}` : 'Settlement pack'}
        backTo="/app/settlement-packs"
        breadcrumbs={[
          { label: 'Governance', to: '/app/governance' },
          { label: 'Settlement packs', to: '/app/settlement-packs' },
          { label: 'Detail' },
        ]}
        right={
          <div className="flex flex-wrap items-center justify-end gap-2">
            {canGenerateVersion && (
              <button
                type="button"
                onClick={handleGenerateVersion}
                disabled={generatingVersion}
                className="px-3 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50 text-sm font-medium"
              >
                {generatingVersion ? 'Generating…' : 'Generate snapshot version'}
              </button>
            )}
            {canFinalize && (
              <button
                type="button"
                onClick={handleFinalize}
                disabled={finalizing}
                className="px-3 py-2 border border-[#1F6F5C] text-[#1F6F5C] rounded hover:bg-emerald-50 disabled:opacity-50 text-sm font-medium"
              >
                {finalizing ? 'Finalizing…' : 'Finalize'}
              </button>
            )}
            {finalized && (
              <button
                type="button"
                onClick={handleExportPdf}
                disabled={exportingPdf}
                className="px-3 py-2 bg-gray-800 text-white rounded hover:bg-gray-900 disabled:opacity-50 text-sm font-medium"
              >
                {exportingPdf ? 'Exporting…' : 'Export PDF bundle'}
              </button>
            )}
            {finalized && (
              <button
                type="button"
                onClick={handleDownloadPdf}
                disabled={downloadingPdf}
                className="px-3 py-2 border border-gray-400 text-gray-800 rounded hover:bg-gray-50 disabled:opacity-50 text-sm font-medium"
              >
                {downloadingPdf ? 'Downloading…' : 'Download PDF'}
              </button>
            )}
          </div>
        }
      />

      <div className="flex flex-wrap items-center gap-3 -mt-2">
        <span className="text-sm text-gray-500">Status</span>
        <span
          className={`inline-flex items-center rounded-full px-3 py-0.5 text-sm font-medium ${
            finalized
              ? 'bg-emerald-100 text-emerald-900'
              : pack.status === 'VOID'
                ? 'bg-gray-200 text-gray-800'
                : 'bg-amber-100 text-amber-900'
          }`}
        >
          {pack.status}
        </span>
        {pack.is_read_only && (
          <span className="text-xs text-gray-500">Read-only</span>
        )}
      </div>

      {pack.project?.name && (
        <p className="text-sm text-gray-500 -mt-2">
          {term('fieldCycle')}: {pack.project.name}
        </p>
      )}

      <section className="bg-white rounded-lg shadow p-6" data-testid="settlement-pack-summary">
        <h3 className="text-lg font-semibold mb-4">Summary</h3>
        <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Total amount</dt>
            <dd className="mt-1 text-gray-900 tabular-nums font-medium">
              {formatMoney(summary.total_amount ?? '0')}
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Register rows</dt>
            <dd className="mt-1 text-gray-900 tabular-nums">
              {summary.row_count ?? rows.length}
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">As of</dt>
            <dd className="mt-1 text-gray-900">
              {pack.as_of_date ? formatDate(pack.as_of_date) : '—'}
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Generated</dt>
            <dd className="mt-1 text-gray-900">
              {pack.generated_at ? formatDate(pack.generated_at) : '—'}
            </dd>
          </div>
        </dl>
        {summary.by_allocation_type && Object.keys(summary.by_allocation_type).length > 0 && (
          <div className="mt-4 pt-4 border-t border-gray-200">
            <dt className="text-sm font-medium text-gray-500 mb-2">By allocation type</dt>
            <div className="flex flex-wrap gap-x-6 gap-y-1">
              {Object.entries(summary.by_allocation_type).map(([type, amount]) => (
                <span key={type} className="tabular-nums text-sm">
                  {type}: {formatMoney(amount)}
                </span>
              ))}
            </div>
          </div>
        )}
      </section>

      {versions.length > 0 && (
        <section className="bg-white rounded-lg shadow overflow-hidden">
          <h3 className="text-lg font-semibold p-4 border-b border-gray-200">Version history</h3>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Version
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Generated
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    Content hash
                  </th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                    PDF
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {versions.map((v) => (
                  <tr key={v.version_no} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm tabular-nums font-medium">{v.version_no}</td>
                    <td className="px-4 py-2 text-sm">
                      {v.generated_at ? formatDate(v.generated_at) : '—'}
                    </td>
                    <td className="px-4 py-2 text-sm font-mono text-gray-600">
                      {v.content_hash
                        ? `${v.content_hash.slice(0, 12)}…`
                        : '—'}
                    </td>
                    <td className="px-4 py-2 text-sm">{v.has_pdf ? 'Yes' : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <section className="bg-white rounded-lg shadow overflow-hidden" data-testid="settlement-pack-register">
        <h3 className="text-lg font-semibold p-4 border-b border-gray-200">Transaction register</h3>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th
                  scope="col"
                  className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                >
                  Posting date
                </th>
                <th
                  scope="col"
                  className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                >
                  Source type
                </th>
                <th
                  scope="col"
                  className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                >
                  Source ID
                </th>
                <th
                  scope="col"
                  className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                >
                  Allocation type
                </th>
                <th
                  scope="col"
                  className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"
                >
                  Amount
                </th>
                <th
                  scope="col"
                  className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                >
                  Posting group
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-6 text-center text-gray-500">
                    No register rows
                  </td>
                </tr>
              ) : (
                rows.map((row, idx) => (
                  <tr
                    key={row.allocation_row_id || `row-${idx}`}
                    className="hover:bg-gray-50"
                  >
                    <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                      {formatDate(row.posting_date)}
                    </td>
                    <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                      {row.source_type}
                    </td>
                    <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-600 font-mono">
                      {row.source_id.length > 8 ? `${row.source_id.slice(0, 8)}…` : row.source_id}
                    </td>
                    <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                      {row.allocation_type}
                    </td>
                    <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                      {formatMoney(row.amount)}
                    </td>
                    <td className="px-4 py-2 whitespace-nowrap text-sm">
                      <Link
                        to={`/app/posting-groups/${row.posting_group_id}`}
                        className="text-[#1F6F5C] hover:underline font-mono"
                      >
                        {row.posting_group_id.length > 8
                          ? `${row.posting_group_id.slice(0, 8)}…`
                          : row.posting_group_id}
                      </Link>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

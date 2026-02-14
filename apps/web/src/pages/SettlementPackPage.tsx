import { useEffect, useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import {
  SettlementPackResponse,
  SettlementPackRegisterRow,
  SettlementPackSummary,
} from '@farm-erp/shared';
import { settlementPackApi } from '../api/settlementPack';
import { useFormatting } from '../hooks/useFormatting';

export default function SettlementPackPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { formatMoney, formatDate } = useFormatting();
  const [pack, setPack] = useState<SettlementPackResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [regenerating, setRegenerating] = useState(false);

  useEffect(() => {
    if (!id) return;
    const fetchPack = async () => {
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
    };
    fetchPack();
  }, [id]);

  const handleRegenerate = async () => {
    if (!pack?.project_id || pack.status === 'FINAL') return;
    setRegenerating(true);
    try {
      const version = `v_${Date.now()}`;
      const created = await settlementPackApi.generate(pack.project_id, version);
      navigate(`/app/settlement-packs/${created.id}`, { replace: true });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to re-generate pack');
    } finally {
      setRegenerating(false);
    }
  };

  if (loading) {
    return (
      <div className="text-gray-600 p-4">Loading settlement pack...</div>
    );
  }

  if (error || !pack) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">{error || 'Settlement pack not found'}</p>
        <Link to="/app/settlement" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
          ← Back to Settlement
        </Link>
      </div>
    );
  }

  const summary = (pack.summary_json || {}) as SettlementPackSummary;
  const rows = (pack.register_rows || []) as SettlementPackRegisterRow[];
  const canRegenerate = pack.status !== 'FINAL' && !!pack.project_id;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap justify-between items-start gap-4">
        <div>
          <h2 className="text-2xl font-bold">Settlement Pack</h2>
          <p className="text-sm text-gray-500 mt-1">
            {pack.project?.name && (
              <>Project: {pack.project.name}</>
            )}
            {pack.register_version && (
              <> · Version: {pack.register_version}</>
            )}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to="/app/settlement"
            className="text-[#1F6F5C] hover:text-[#1a5a4a] font-medium"
          >
            ← Settlement
          </Link>
          {canRegenerate && (
            <button
              type="button"
              onClick={handleRegenerate}
              disabled={regenerating}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50 text-sm font-medium"
            >
              {regenerating ? 'Generating…' : 'Re-generate pack'}
            </button>
          )}
        </div>
      </div>

      {/* Summary totals */}
      <section className="bg-white rounded-lg shadow p-6" data-testid="settlement-pack-summary">
        <h3 className="text-lg font-semibold mb-4">Summary</h3>
        <dl className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="mt-1 text-gray-900 font-medium">{pack.status}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Total amount</dt>
            <dd className="mt-1 text-gray-900 tabular-nums">
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
                <span key={type} className="tabular-nums">
                  {type}: {formatMoney(amount)}
                </span>
              ))}
            </div>
          </div>
        )}
      </section>

      {/* Transaction register table */}
      <section className="bg-white rounded-lg shadow overflow-hidden" data-testid="settlement-pack-register">
        <h3 className="text-lg font-semibold p-4 border-b border-gray-200">Transaction register</h3>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th scope="col" className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Posting date
                </th>
                <th scope="col" className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Source type
                </th>
                <th scope="col" className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Source ID
                </th>
                <th scope="col" className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Allocation type
                </th>
                <th scope="col" className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Amount
                </th>
                <th scope="col" className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
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
                rows.map((row) => (
                  <tr key={row.allocation_row_id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                      {formatDate(row.posting_date)}
                    </td>
                    <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                      {row.source_type}
                    </td>
                    <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-600 font-mono">
                      {row.source_id.slice(0, 8)}…
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
                        {row.posting_group_id.slice(0, 8)}…
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

import { Link } from 'react-router-dom';
import type { Harvest } from '../../types';
import { useFormatting } from '../../hooks/useFormatting';
import { buildPostedShareSummary } from '../../utils/harvestSharePosted';

type Props = {
  harvest: Harvest;
};

/**
 * Concise read-only summary of posted share outcomes from persisted API snapshots (Phase 3C).
 */
export function HarvestSharePostedSummaryCard({ harvest }: Props) {
  const { formatDate, formatDateTime, formatNumber, formatMoney } = useFormatting();
  const { rows, totalHarvestQty, hasPostedSnapshots, shareLineCount } = buildPostedShareSummary(harvest);
  const isReversed = harvest.status === 'REVERSED';

  if (harvest.status !== 'POSTED' && harvest.status !== 'REVERSED') {
    return null;
  }

  return (
    <div className="bg-white rounded-lg shadow p-6 space-y-4">
      <div>
        <h3 className="font-semibold text-gray-900">Harvest share summary</h3>
        <p className="text-sm text-gray-600 mt-1">
          Figures below come from the system posting run — they are not recalculated in the browser.
        </p>
      </div>

      {isReversed && (
        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">
          <strong>Reversed.</strong>{' '}
          {harvest.reversed_at ? (
            <>Reversal booked {formatDateTime(harvest.reversed_at)}.</>
          ) : (
            <>This harvest was reversed.</>
          )}{' '}
          The split shown is the historical posting before reversal (for traceability). Inventory and accounts were
          unwound by the reversal entry.
        </div>
      )}

      <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm border-t border-gray-100 pt-4">
        {harvest.posted_at && (
          <div>
            <dt className="text-gray-500">Posted</dt>
            <dd className="font-medium tabular-nums">{formatDateTime(harvest.posted_at)}</dd>
          </div>
        )}
        {harvest.posting_date && (
          <div>
            <dt className="text-gray-500">Posting date</dt>
            <dd className="tabular-nums">{formatDate(harvest.posting_date, { variant: 'medium' })}</dd>
          </div>
        )}
        {harvest.posting_group_id && (
          <div className="sm:col-span-2">
            <dt className="text-gray-500">Harvest posting group</dt>
            <dd>
              <Link to={`/app/posting-groups/${harvest.posting_group_id}`} className="text-[#1F6F5C] hover:underline">
                {harvest.posting_group_id}
              </Link>
            </dd>
          </div>
        )}
        {isReversed && harvest.reversal_posting_group_id && (
          <div className="sm:col-span-2">
            <dt className="text-gray-500">Reversal posting group</dt>
            <dd>
              <Link
                to={`/app/posting-groups/${harvest.reversal_posting_group_id}`}
                className="text-[#1F6F5C] hover:underline"
              >
                {harvest.reversal_posting_group_id}
              </Link>
            </dd>
          </div>
        )}
      </dl>

      <div className="overflow-x-auto border border-gray-200 rounded-lg">
        <table className="min-w-full text-sm">
          <thead className="bg-[#E6ECEA] text-left text-xs text-gray-600 uppercase tracking-wide">
            <tr>
              <th className="px-3 py-2">Bucket</th>
              <th className="px-3 py-2 text-right">Posted qty</th>
              <th className="px-3 py-2 text-right">
                <span title="Unit cost snapshot at posting (from server)">Unit cost</span>
              </th>
              <th className="px-3 py-2 text-right">
                <span title="Inventory value at posting (from server)">Posted value</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.key} className="border-t border-gray-100">
                <td className="px-3 py-2">
                  <span className="font-medium text-gray-900">{r.label}</span>
                  {r.hint && <div className="text-xs text-gray-500 mt-0.5">{r.hint}</div>}
                </td>
                <td className="px-3 py-2 text-right tabular-nums">
                  {r.qty != null ? formatNumber(r.qty, { maximumFractionDigits: 3 }) : '—'}
                </td>
                <td className="px-3 py-2 text-right tabular-nums text-gray-700">
                  {r.unitCost != null ? formatMoney(r.unitCost) : '—'}
                </td>
                <td className="px-3 py-2 text-right tabular-nums text-gray-900">
                  {r.value != null ? formatMoney(r.value) : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {!hasPostedSnapshots && shareLineCount === 0 && totalHarvestQty > 0 && (
        <p className="text-xs text-gray-500">
          This harvest was posted without an output split — stock and costs follow the standard single-bucket harvest
          posting.
        </p>
      )}

      {hasPostedSnapshots && (
        <p className="text-xs text-gray-500">
          Detailed lines and beneficiary links are in <strong>Output shares</strong> below.{' '}
          <span className="hidden sm:inline">Use posting groups above for ledger drill-down.</span>
        </p>
      )}
    </div>
  );
}

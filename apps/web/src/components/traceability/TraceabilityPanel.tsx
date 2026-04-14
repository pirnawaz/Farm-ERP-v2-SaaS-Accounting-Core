import { Link } from 'react-router-dom';
import type { OperationalTraceabilityPayload } from '../../types';

function hasTraceabilityContent(t: OperationalTraceabilityPayload): boolean {
  if (t.posting_group_id) return true;
  if (t.reversal_posting_group_id) return true;
  if (
    t.overlap_signals &&
    (t.overlap_signals.stock_movements_count > 0 ||
      t.overlap_signals.machinery_lines_from_machine_usage_count > 0 ||
      t.overlap_signals.machinery_lines_from_machinery_charge_count > 0)
  ) {
    return true;
  }
  if ((t.linked_harvests?.length ?? 0) > 0) return true;
  if ((t.linked_field_jobs?.length ?? 0) > 0) return true;
  if ((t.source_machine_work_logs?.length ?? 0) > 0) return true;
  if ((t.linked_field_job_machines?.length ?? 0) > 0) return true;
  if (t.parent_machinery_charge) return true;
  if ((t.stock_movements?.length ?? 0) > 0) return true;
  if ((t.labour_lines ?? []).some((x) => x.amount != null)) return true;
  if (
    (t.machinery_sources ?? []).some(
      (m) => m.source_work_log != null || m.source_machinery_charge != null,
    )
  ) {
    return true;
  }
  if ((t.share_line_source_ids?.length ?? 0) > 0) return true;
  return false;
}

/**
 * Read-only cross-links between field jobs, harvests, machine usage, and machinery charges.
 */
export function TraceabilityPanel({
  traceability,
}: {
  traceability?: OperationalTraceabilityPayload | null;
}) {
  if (traceability == null) {
    return null;
  }

  const hasAny = hasTraceabilityContent(traceability);
  const overlap =
    traceability.overlap_signals &&
    (traceability.overlap_signals.stock_movements_count > 0 ||
      traceability.overlap_signals.machinery_lines_from_machine_usage_count > 0 ||
      traceability.overlap_signals.machinery_lines_from_machinery_charge_count > 0)
      ? traceability.overlap_signals
      : null;

  return (
    <section
      className="rounded-xl border border-slate-200 bg-slate-50/90 p-4 shadow-sm"
      aria-label="Traceability"
    >
      <h2 className="text-sm font-semibold text-gray-900">Traceability</h2>
      <p className="mt-1 text-xs text-gray-600">
        Where related numbers and allocations come from—links are read-only.
      </p>

      {overlap ? (
        <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950">
          <p className="font-medium">Notice: linked downstream records exist</p>
          <p className="mt-1 text-amber-900/90">
            This entry already has linked stock movements / machinery documents. Avoid recording the same real-world event
            again in a manual/legacy workflow, or you may create duplicate operational and accounting records.
          </p>
        </div>
      ) : null}

      {!hasAny ? (
        <p className="mt-3 text-sm text-gray-600">No linked documents recorded for this entry yet.</p>
      ) : (
        <div className="mt-4 space-y-5 text-sm">
          {traceability.overlap_signals ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">Overlap signals</h3>
              <p className="mt-2 text-gray-700">
                Stock movements:{' '}
                <span className="font-mono tabular-nums">{traceability.overlap_signals.stock_movements_count}</span>
                <span className="text-gray-500"> · </span>
                Machine usage links:{' '}
                <span className="font-mono tabular-nums">
                  {traceability.overlap_signals.machinery_lines_from_machine_usage_count}
                </span>
                <span className="text-gray-500"> · </span>
                Machinery charge links:{' '}
                <span className="font-mono tabular-nums">
                  {traceability.overlap_signals.machinery_lines_from_machinery_charge_count}
                </span>
              </p>
              {traceability.overlap_signals.note ? (
                <p className="mt-1 text-xs text-gray-600">{traceability.overlap_signals.note}</p>
              ) : null}
            </div>
          ) : null}

          {traceability.posting_group_id ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">Posting group</h3>
              <p className="mt-2">
                <Link
                  to={`/app/posting-groups/${traceability.posting_group_id}`}
                  className="font-medium text-[#1F6F5C] hover:underline"
                >
                  View posting group
                </Link>
                <span className="text-gray-600"> — {traceability.posting_group_id}</span>
              </p>
              {traceability.reversal_posting_group_id ? (
                <p className="mt-1 text-xs text-gray-600">
                  Reversal posting group:{' '}
                  <Link
                    to={`/app/posting-groups/${traceability.reversal_posting_group_id}`}
                    className="font-medium text-[#1F6F5C] hover:underline"
                  >
                    {traceability.reversal_posting_group_id}
                  </Link>
                </p>
              ) : null}
            </div>
          ) : null}

          {traceability.linked_harvests && traceability.linked_harvests.length > 0 ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">Harvests</h3>
              <ul className="mt-2 space-y-1.5">
                {traceability.linked_harvests.map((h) => (
                  <li key={h.id}>
                    <Link
                      to={`/app/harvests/${h.id}`}
                      className="font-medium text-[#1F6F5C] hover:text-[#1a5a4a] hover:underline"
                    >
                      View harvest
                    </Link>
                    <span className="text-gray-600">
                      {' '}
                      — {h.harvest_no || h.id.slice(0, 8)}
                      {h.harvest_date ? ` · ${h.harvest_date}` : ''}
                      {h.status ? ` · ${h.status}` : ''}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {traceability.linked_field_jobs && traceability.linked_field_jobs.length > 0 ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">Field jobs</h3>
              <ul className="mt-2 space-y-1.5">
                {traceability.linked_field_jobs.map((fj) => (
                  <li key={fj.id}>
                    <Link
                      to={`/app/crop-ops/field-jobs/${fj.id}`}
                      className="font-medium text-[#1F6F5C] hover:text-[#1a5a4a] hover:underline"
                    >
                      View related field job
                    </Link>
                    <span className="text-gray-600">
                      {' '}
                      — {fj.doc_no || fj.id.slice(0, 8)}
                      {fj.job_date ? ` · ${fj.job_date}` : ''}
                      {fj.status ? ` · ${fj.status}` : ''}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {traceability.machinery_sources && traceability.machinery_sources.length > 0 ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">
                Machinery lines on this job
              </h3>
              <ul className="mt-2 space-y-2">
                {traceability.machinery_sources.map((row) => (
                  <li key={row.field_job_machine_id} className="border-l-2 border-[#1F6F5C]/30 pl-3">
                    <div className="text-gray-700">{row.machine_label ?? 'Machine line'}</div>
                    {row.pricing_basis || row.rate_card_id || row.rate_snapshot || row.amount ? (
                      <div className="mt-1 text-xs text-gray-600">
                        {row.pricing_basis ? <span className="mr-2">Pricing: {row.pricing_basis}</span> : null}
                        {row.rate_snapshot ? <span className="mr-2">Rate: {row.rate_snapshot}</span> : null}
                        {row.amount ? <span className="mr-2">Amount: {row.amount}</span> : null}
                        {row.rate_card_id ? (
                          <span>
                            Rate card:{' '}
                            <Link
                              to={`/app/machinery/rate-cards/${row.rate_card_id}`}
                              className="font-medium text-[#1F6F5C] hover:underline"
                            >
                              {row.rate_card_id.slice(0, 8)}…
                            </Link>
                          </span>
                        ) : null}
                      </div>
                    ) : null}
                    {row.source_work_log ? (
                      <div className="mt-1">
                        <Link
                          to={`/app/machinery/work-logs/${row.source_work_log.id}`}
                          className="font-medium text-[#1F6F5C] hover:underline"
                        >
                          View machine usage
                        </Link>
                        <span className="text-gray-600">
                          {' '}
                          — {row.source_work_log.work_log_no ?? row.source_work_log.id.slice(0, 8)}
                          {row.source_work_log.work_date ? ` · ${row.source_work_log.work_date}` : ''}
                        </span>
                      </div>
                    ) : null}
                    {row.source_machinery_charge ? (
                      <div className="mt-1">
                        <Link
                          to={`/app/machinery/charges/${row.source_machinery_charge.id}`}
                          className="font-medium text-[#1F6F5C] hover:underline"
                        >
                          View machinery charge
                        </Link>
                        <span className="text-gray-600">
                          {' '}
                          — {row.source_machinery_charge.charge_no ?? row.source_machinery_charge.id.slice(0, 8)}
                          {row.source_machinery_charge.charge_date
                            ? ` · ${row.source_machinery_charge.charge_date}`
                            : ''}
                        </span>
                      </div>
                    ) : null}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {traceability.stock_movements && traceability.stock_movements.length > 0 ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">Stock movements</h3>
              <p className="mt-2 text-gray-700">
                {traceability.stock_movements.length} movement{traceability.stock_movements.length === 1 ? '' : 's'} recorded from this field job posting.
                <span className="text-gray-600">
                  {' '}
                  View full history in{' '}
                  <Link to="/app/inventory/stock-movements" className="font-medium text-[#1F6F5C] hover:underline">
                    Stock History
                  </Link>
                  .
                </span>
              </p>
              <ul className="mt-2 space-y-1.5 text-xs text-gray-700 font-mono tabular-nums">
                {traceability.stock_movements.slice(0, 5).map((m) => (
                  <li key={m.id}>
                    {m.occurred_at ? `${m.occurred_at} · ` : ''}
                    {m.movement_type} · store {m.store_id.slice(0, 8)}… · item {m.item_id.slice(0, 8)}… · qty {m.qty_delta}
                  </li>
                ))}
              </ul>
              {traceability.stock_movements.length > 5 ? (
                <p className="mt-2 text-xs text-gray-600">Showing first 5 movements.</p>
              ) : null}
            </div>
          ) : null}

          {traceability.labour_lines && traceability.labour_lines.some((x) => x.amount != null) ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">Labour payable impact</h3>
              <p className="mt-2 text-gray-700">
                Total labour cost on this job:{' '}
                <span className="font-medium">{traceability.labour_total ?? '—'}</span>
                <span className="text-gray-600"> (from posted labour line amounts)</span>
              </p>
              <ul className="mt-2 space-y-1.5 text-xs text-gray-700">
                {traceability.labour_lines
                  .filter((l) => l.amount != null)
                  .slice(0, 8)
                  .map((l) => (
                    <li key={l.field_job_labour_id}>
                      {l.worker_label ?? l.worker_id}: <span className="font-mono tabular-nums">{l.amount}</span>
                    </li>
                  ))}
              </ul>
            </div>
          ) : null}

          {traceability.source_machine_work_logs && traceability.source_machine_work_logs.length > 0 ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">
                Source machine usage (charge lines)
              </h3>
              <ul className="mt-2 space-y-1.5">
                {traceability.source_machine_work_logs.map((w) => (
                  <li key={w.id}>
                    <Link
                      to={`/app/machinery/work-logs/${w.id}`}
                      className="font-medium text-[#1F6F5C] hover:underline"
                    >
                      View machine usage
                    </Link>
                    <span className="text-gray-600">
                      {' '}
                      — {w.work_log_no ?? w.id.slice(0, 8)}
                      {w.work_date ? ` · ${w.work_date}` : ''}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {traceability.parent_machinery_charge ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">
                Generated machinery charge
              </h3>
              <p className="mt-2">
                <Link
                  to={`/app/machinery/charges/${traceability.parent_machinery_charge.id}`}
                  className="font-medium text-[#1F6F5C] hover:underline"
                >
                  View machinery charge
                </Link>
                <span className="text-gray-600">
                  {' '}
                  — {traceability.parent_machinery_charge.charge_no ?? traceability.parent_machinery_charge.id.slice(0, 8)}
                  {traceability.parent_machinery_charge.charge_date
                    ? ` · ${traceability.parent_machinery_charge.charge_date}`
                    : ''}
                </span>
              </p>
            </div>
          ) : null}

          {traceability.linked_field_job_machines && traceability.linked_field_job_machines.length > 0 ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">Field jobs</h3>
              <ul className="mt-2 space-y-1.5">
                {traceability.linked_field_job_machines.map((row) =>
                  row.field_job ? (
                    <li key={row.field_job_machine_id}>
                      <Link
                        to={`/app/crop-ops/field-jobs/${row.field_job.id}`}
                        className="font-medium text-[#1F6F5C] hover:underline"
                      >
                        View related field job
                      </Link>
                      <span className="text-gray-600">
                        {' '}
                        — {row.field_job.doc_no ?? row.field_job.id.slice(0, 8)}
                        {row.field_job.job_date ? ` · ${row.field_job.job_date}` : ''}
                      </span>
                    </li>
                  ) : null,
                )}
              </ul>
            </div>
          ) : null}

          {traceability.share_line_source_ids && traceability.share_line_source_ids.length > 0 ? (
            <div>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700">
                Share line sources ({traceability.share_lines_count ?? traceability.share_line_source_ids.length})
              </h3>
              <ul className="mt-2 space-y-1.5 text-xs text-gray-700">
                {traceability.share_line_source_ids.map((row) => (
                  <li key={row.share_line_id} className="font-mono tabular-nums">
                    Line {row.share_line_id.slice(0, 8)}…
                    {row.source_field_job_id ? ` · field job ${row.source_field_job_id.slice(0, 8)}…` : ''}
                    {row.source_machinery_charge_id
                      ? ` · machinery charge ${row.source_machinery_charge_id.slice(0, 8)}…`
                      : ''}
                    {row.source_lab_work_log_id ? ` · labour log ${row.source_lab_work_log_id.slice(0, 8)}…` : ''}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
      )}
    </section>
  );
}

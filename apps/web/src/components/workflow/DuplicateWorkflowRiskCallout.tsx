import type { OperationalTraceabilityPayload } from '../../types';

function fieldJobHasOverlappingMachinerySources(t: OperationalTraceabilityPayload | null | undefined): boolean {
  return (t?.machinery_sources ?? []).some(
    (m) => m.source_work_log != null || m.source_machinery_charge != null,
  );
}

function harvestHasManualShareSources(t: OperationalTraceabilityPayload | null | undefined): boolean {
  return (t?.share_line_source_ids ?? []).some(
    (s) => s.source_machinery_charge_id != null || s.source_lab_work_log_id != null,
  );
}

/**
 * Surfaces duplicate-workflow risk using server-provided traceability only (no client business rules).
 */
export function DuplicateWorkflowRiskCallout({
  context,
  traceability,
}: {
  context: 'field-job' | 'harvest';
  traceability?: OperationalTraceabilityPayload | null;
}) {
  const show =
    context === 'field-job'
      ? fieldJobHasOverlappingMachinerySources(traceability)
      : harvestHasManualShareSources(traceability);

  if (!show) {
    return null;
  }

  const message =
    context === 'field-job'
      ? 'Some machinery lines on this job are also tied to standalone work logs or charges. Posting the same usage twice can be blocked—review links in Traceability before you post.'
      : 'Some share lines trace to machinery charges or labour work logs outside the usual field-job path. Check Traceability to avoid double-counting the same work or output.';

  return (
    <div
      role="status"
      className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
    >
      <p className="font-medium">Possible duplicate-workflow risk</p>
      <p className="mt-1 text-amber-950/95">{message}</p>
    </div>
  );
}

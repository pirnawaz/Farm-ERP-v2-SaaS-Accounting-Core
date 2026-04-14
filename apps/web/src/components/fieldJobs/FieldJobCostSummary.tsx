import type { FieldJob, FieldJobInputLine, FieldJobLabourLine, FieldJobMachineLine } from '../../types';
import { useFormatting } from '../../hooks/useFormatting';
import { useFieldJobDraftCostPreview } from '../../hooks/useFieldJobs';

function sumInputLineTotals(inputs: FieldJobInputLine[] | undefined): number | null {
  if (!inputs?.length) {
    return 0;
  }
  let hasValue = false;
  let sum = 0;
  for (const line of inputs) {
    if (line.line_total != null && line.line_total !== '') {
      hasValue = true;
      sum += parseFloat(line.line_total);
    }
  }
  return hasValue ? sum : null;
}

function sumLabourAmounts(labour: FieldJobLabourLine[] | undefined): number | null {
  if (!labour?.length) {
    return 0;
  }
  let hasValue = false;
  let sum = 0;
  for (const line of labour) {
    if (line.amount != null && line.amount !== '') {
      hasValue = true;
      sum += parseFloat(line.amount);
    }
  }
  return hasValue ? sum : null;
}

function sumMachineryAmounts(machines: FieldJobMachineLine[] | undefined): number | null {
  if (!machines?.length) {
    return 0;
  }
  let hasValue = false;
  let sum = 0;
  for (const line of machines) {
    if (line.amount != null && line.amount !== '') {
      hasValue = true;
      sum += parseFloat(line.amount);
    }
  }
  return hasValue ? sum : null;
}

function formatTotal(
  formatMoney: (amount: number | string) => string,
  value: number | null
): string {
  if (value === null) {
    return '—';
  }
  return formatMoney(value);
}

type Props = {
  job: FieldJob;
};

/**
 * Displays:
 * - Posted/reversed: totals from persisted line snapshots returned by the API (authoritative post-time values).
 * - Draft: totals from the draft cost preview endpoint (estimates), with explicit handling for partial/unknown costs.
 */
export function FieldJobCostSummary({ job }: Props) {
  const { formatMoney } = useFormatting();

  const inputsTotal = sumInputLineTotals(job.inputs);
  const labourTotal = sumLabourAmounts(job.labour);
  const machineryTotal = sumMachineryAmounts(job.machines);

  const allKnown =
    inputsTotal !== null && labourTotal !== null && machineryTotal !== null;
  const grandTotal = allKnown ? inputsTotal + labourTotal + machineryTotal : null;

  const isDraft = job.status === 'DRAFT';
  const isReversed = job.status === 'REVERSED';

  const previewQ = useFieldJobDraftCostPreview(job.id, isDraft);
  const preview = previewQ.data;

  const previewGrandTotal = preview?.summary.grand_total_estimate ?? null;
  const previewKnownTotal = preview?.summary.known_total_estimate ?? null;
  const previewHasUnknowns = (preview?.summary.unknown_lines_count ?? 0) > 0;

  const showPreview = isDraft;
  const draftLoading = showPreview && previewQ.isLoading;
  const draftFailed = showPreview && previewQ.isError;

  const title = isDraft ? 'Estimated cost summary' : 'Cost summary';

  const renderDraftSectionValue = (section: {
    lines?: unknown[];
    all_known: boolean;
    subtotal_estimate: string | null;
    known_subtotal_estimate: string;
    unknown_lines_count: number;
  }): { labelPrefix: 'Known ' | ''; value: string } => {
    const linesCount = section.lines?.length ?? 0;
    if (linesCount === 0) {
      return { labelPrefix: '', value: formatMoney(0) };
    }

    if (section.all_known && section.subtotal_estimate != null) {
      return { labelPrefix: '', value: formatMoney(section.subtotal_estimate) };
    }

    const known = parseFloat(section.known_subtotal_estimate || '0');
    if (!Number.isFinite(known)) {
      return { labelPrefix: '', value: 'Valued on posting' };
    }

    if (!section.all_known) {
      if (known > 0) {
        return { labelPrefix: 'Known ', value: formatMoney(section.known_subtotal_estimate) };
      }
      if (section.unknown_lines_count > 0) {
        return { labelPrefix: '', value: 'Valued on posting' };
      }
    }

    return { labelPrefix: '', value: formatMoney(section.known_subtotal_estimate) };
  };

  return (
    <section
      className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
      aria-label="Field job cost summary"
    >
      <h2 className="text-sm font-semibold text-gray-900 uppercase tracking-wide">{title}</h2>
      <p className="mt-1 text-sm text-gray-600">
        {isDraft ? (
          <>
            Estimates are shown without posting. If some lines cannot be priced safely yet, totals are shown as a <strong>known subtotal</strong>{' '}
            and the rest will be <strong>valued on posting</strong>.
          </>
        ) : isReversed ? (
          <>Amounts reflect the original posting before this job was reversed.</>
        ) : (
          <>Totals match the posted ledger allocations for inputs, labour, and machinery on this job.</>
        )}
      </p>
      {draftLoading ? (
        <p className="mt-2 text-xs text-gray-500">Loading draft estimates…</p>
      ) : draftFailed ? (
        <p className="mt-2 text-xs text-amber-800">Draft estimates unavailable. Totals will appear after posting.</p>
      ) : null}
      {showPreview && !draftLoading && !draftFailed && previewHasUnknowns ? (
        <p className="mt-2 text-xs text-gray-700">
          Some costs will be valued on posting. Showing known subtotals where possible.
        </p>
      ) : null}
      <dl className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm border-t border-gray-100 pt-4">
        <div className="flex justify-between gap-4 rounded-lg bg-[#F7FAF9] px-3 py-2">
          <dt className="text-gray-600">
            {showPreview && preview
              ? `${renderDraftSectionValue(preview.inputs).labelPrefix}inputs subtotal`
              : 'Total inputs cost'}
          </dt>
          <dd className="font-medium tabular-nums text-gray-900">
            {showPreview
              ? preview
                ? renderDraftSectionValue(preview.inputs).value
                : '—'
              : formatTotal(formatMoney, inputsTotal)}
          </dd>
        </div>
        <div className="flex justify-between gap-4 rounded-lg bg-[#F7FAF9] px-3 py-2">
          <dt className="text-gray-600">
            {showPreview && preview
              ? `${renderDraftSectionValue(preview.labour).labelPrefix}labour subtotal`
              : 'Total labour cost'}
          </dt>
          <dd className="font-medium tabular-nums text-gray-900">
            {showPreview
              ? preview
                ? renderDraftSectionValue(preview.labour).value
                : '—'
              : formatTotal(formatMoney, labourTotal)}
          </dd>
        </div>
        <div className="flex justify-between gap-4 rounded-lg bg-[#E6ECEA] px-3 py-2 sm:col-span-2">
          <dt className="text-gray-700">
            {showPreview && preview
              ? `${renderDraftSectionValue(preview.machinery).labelPrefix}machinery subtotal`
              : 'Total machinery cost'}
          </dt>
          <dd className="font-semibold tabular-nums text-gray-900">
            {showPreview
              ? preview
                ? renderDraftSectionValue(preview.machinery).value
                : '—'
              : formatTotal(formatMoney, machineryTotal)}
          </dd>
        </div>
        <div className="flex justify-between gap-4 rounded-lg border border-[#1F6F5C]/30 bg-[#1F6F5C]/5 px-3 py-2 sm:col-span-2">
          <dt className="font-medium text-gray-900">
            {showPreview && previewGrandTotal === null ? 'Known job subtotal' : 'Total job cost'}
          </dt>
          <dd className="font-semibold tabular-nums text-[#1F6F5C]">
            {showPreview
              ? previewGrandTotal === null
                ? previewKnownTotal !== null
                  ? formatMoney(previewKnownTotal)
                  : 'Valued on posting'
                : formatMoney(previewGrandTotal)
              : formatTotal(formatMoney, grandTotal)}
          </dd>
        </div>
      </dl>
    </section>
  );
}

import type { FieldJob, FieldJobInputLine, FieldJobLabourLine, FieldJobMachineLine } from '../../types';
import { useFormatting } from '../../hooks/useFormatting';

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
 * Displays subtotals and job total from line-level amounts returned by the API.
 * Does not compute costs; sums monetary fields present on each line only.
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

  return (
    <section
      className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
      aria-label="Field job cost summary"
    >
      <h2 className="text-sm font-semibold text-gray-900 uppercase tracking-wide">Cost summary</h2>
      <p className="mt-1 text-sm text-gray-600">
        {isDraft ? (
          <>
            Figures use amounts returned for each line. Input and machinery costs usually appear after you post (unless you
            enter optional amounts on machine lines). Labour shows a total only when line amounts are present.
          </>
        ) : isReversed ? (
          <>Amounts reflect the original posting before this job was reversed.</>
        ) : (
          <>Totals match the posted ledger allocations for inputs, labour, and machinery on this job.</>
        )}
      </p>
      <dl className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm border-t border-gray-100 pt-4">
        <div className="flex justify-between gap-4 rounded-lg bg-[#F7FAF9] px-3 py-2">
          <dt className="text-gray-600">Total inputs cost</dt>
          <dd className="font-medium tabular-nums text-gray-900">{formatTotal(formatMoney, inputsTotal)}</dd>
        </div>
        <div className="flex justify-between gap-4 rounded-lg bg-[#F7FAF9] px-3 py-2">
          <dt className="text-gray-600">Total labour cost</dt>
          <dd className="font-medium tabular-nums text-gray-900">{formatTotal(formatMoney, labourTotal)}</dd>
        </div>
        <div className="flex justify-between gap-4 rounded-lg bg-[#E6ECEA] px-3 py-2 sm:col-span-2">
          <dt className="text-gray-700">Total machinery cost</dt>
          <dd className="font-semibold tabular-nums text-gray-900">{formatTotal(formatMoney, machineryTotal)}</dd>
        </div>
        <div className="flex justify-between gap-4 rounded-lg border border-[#1F6F5C]/30 bg-[#1F6F5C]/5 px-3 py-2 sm:col-span-2">
          <dt className="font-medium text-gray-900">Total job cost</dt>
          <dd className="font-semibold tabular-nums text-[#1F6F5C]">{formatTotal(formatMoney, grandTotal)}</dd>
        </div>
      </dl>
    </section>
  );
}

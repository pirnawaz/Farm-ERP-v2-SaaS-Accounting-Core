import type { Harvest, HarvestLine, HarvestRecipientRole, HarvestShareLine } from '../types';

/** Parse numeric fields from API (decimals may arrive as strings). */
export function parseSnapshotNumber(v: string | number | null | undefined): number | null {
  if (v === null || v === undefined || v === '') return null;
  const n = typeof v === 'number' ? v : parseFloat(String(v));
  return Number.isFinite(n) ? n : null;
}

export function totalHarvestQuantity(lines: HarvestLine[] | undefined): number {
  return (lines ?? []).reduce((s, l) => s + parseFloat(String(l.quantity ?? 0)), 0);
}

export type PostedShareSummaryRow = {
  key: string;
  label: string;
  qty: number | null;
  value: number | null;
  unitCost: number | null;
  hint?: string;
};

const ROLE_LABEL: Record<HarvestRecipientRole, string> = {
  OWNER: 'Owner retained',
  MACHINE: 'Machine share',
  LABOUR: 'Labour share',
  LANDLORD: 'Landlord share',
  CONTRACTOR: 'Contractor share',
};

/**
 * Read-model only: uses persisted share line snapshots and harvest line quantities from the API.
 * Does not compute valuation; optional implicit owner qty is quantity arithmetic on posted snapshot qty only.
 */
export function buildPostedShareSummary(harvest: Harvest): {
  rows: PostedShareSummaryRow[];
  totalHarvestQty: number;
  hasPostedSnapshots: boolean;
  shareLineCount: number;
} {
  const totalHarvestQty = totalHarvestQuantity(harvest.lines);
  const shareLines = harvest.share_lines ?? [];
  const withSnapshots = shareLines.filter((sl) => parseSnapshotNumber(sl.computed_qty) != null);
  const hasPostedSnapshots = withSnapshots.length > 0;

  const rows: PostedShareSummaryRow[] = [];

  rows.push({
    key: 'total_harvested',
    label: 'Total harvested',
    qty: totalHarvestQty,
    value: null,
    unitCost: null,
  });

  if (!hasPostedSnapshots) {
    if (shareLines.length === 0) {
      rows.push({
        key: 'owner_all',
        label: 'Retained by owner',
        qty: totalHarvestQty,
        value: null,
        unitCost: null,
        hint: 'No output split was defined — everything stays with the farm.',
      });
    }
    return { rows, totalHarvestQty, hasPostedSnapshots, shareLineCount: shareLines.length };
  }

  const byRole = new Map<
    HarvestRecipientRole,
    { qty: number; value: number; n: number }
  >();

  for (const sl of withSnapshots) {
    const q = parseSnapshotNumber(sl.computed_qty) ?? 0;
    const val = parseSnapshotNumber(sl.computed_value_snapshot) ?? 0;
    const cur = byRole.get(sl.recipient_role) ?? { qty: 0, value: 0, n: 0 };
    cur.qty += q;
    cur.value += val;
    cur.n += 1;
    byRole.set(sl.recipient_role, cur);
  }

  const order: HarvestRecipientRole[] = ['OWNER', 'MACHINE', 'LABOUR', 'LANDLORD', 'CONTRACTOR'];

  const hasOwnerSnapshots = (byRole.get('OWNER')?.qty ?? 0) > 0;
  let sumOtherQty = 0;
  for (const role of order) {
    if (role === 'OWNER') continue;
    sumOtherQty += byRole.get(role)?.qty ?? 0;
  }

  if (!hasOwnerSnapshots && sumOtherQty > 0 && totalHarvestQty > sumOtherQty - 1e-6) {
    const implicitQty = totalHarvestQty - sumOtherQty;
    if (implicitQty > 1e-6) {
      rows.push({
        key: 'owner_implicit',
        label: 'Owner retained (balance)',
        qty: implicitQty,
        value: null,
        unitCost: null,
        hint:
          'Quantity left after other posted shares. Posted value for this balance is included in owner lines where defined in the table below.',
      });
    }
  }

  for (const role of order) {
    const agg = byRole.get(role);
    if (!agg || agg.qty <= 0) continue;
    const unitCost = agg.n === 1 && agg.qty > 0 ? agg.value / agg.qty : agg.qty > 0 ? agg.value / agg.qty : null;
    rows.push({
      key: `role_${role}`,
      label: ROLE_LABEL[role],
      qty: agg.qty,
      value: agg.value,
      unitCost: unitCost != null && Number.isFinite(unitCost) ? unitCost : null,
      hint:
        role === 'OWNER'
          ? 'Posted crop cost allocated to owner buckets (per system posting).'
          : undefined,
    });
  }

  return { rows, totalHarvestQty, hasPostedSnapshots, shareLineCount: shareLines.length };
}

export function shareLineHasPostedSnapshot(sl: HarvestShareLine): boolean {
  return parseSnapshotNumber(sl.computed_qty) != null;
}

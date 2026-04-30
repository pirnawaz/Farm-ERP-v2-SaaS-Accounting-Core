import type { SetupCompleteness } from './SetupStatusBadge';

export type SetupProjectLike = {
  land_allocation_id?: string | null;
  land_allocation?: unknown | null;
  field_block_id?: string | null;
  field_block?: unknown | null;
  agreement_id?: string | null;
  agreement?: unknown | null;
  agreement_allocation_id?: string | null;
  agreement_allocation?: unknown | null;
};

export function getSetupCompleteness(p: SetupProjectLike | null | undefined): SetupCompleteness {
  if (!p) return 'NOT_SET';
  const hasLandAllocation = !!(p.land_allocation_id || p.land_allocation);
  const hasFieldBlock = !!(p.field_block_id || p.field_block);
  const hasAgreement = !!(p.agreement_id || p.agreement);
  const hasAgreementAllocation = !!(p.agreement_allocation_id || p.agreement_allocation);
  const hasAnyLink = hasLandAllocation || hasFieldBlock || hasAgreement || hasAgreementAllocation;
  const complete = hasLandAllocation && hasFieldBlock && hasAgreement && hasAgreementAllocation;
  if (complete) return 'COMPLETE';
  if (!hasAnyLink) return 'NOT_SET';
  return 'PARTIAL';
}

export function isSetupComplete(p: SetupProjectLike | null | undefined): boolean {
  return getSetupCompleteness(p) === 'COMPLETE';
}


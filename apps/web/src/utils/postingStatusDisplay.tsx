import { Badge } from '../components/Badge';
import type { BadgeVariant } from '../components/Badge';

/** Visible label for DRAFT / POSTED / REVERSED (and similar) inventory & operations posting statuses. */
export function postingStatusLabel(status: string): string {
  const s = String(status || '').toUpperCase();
  if (s === 'DRAFT') return 'Draft';
  if (s === 'POSTED') return 'Posted';
  if (s === 'REVERSED') return 'Reversed';
  return status;
}

export function postingStatusVariant(status: string): BadgeVariant {
  const s = String(status || '').toUpperCase();
  if (s === 'DRAFT') return 'warning';
  if (s === 'POSTED') return 'success';
  if (s === 'REVERSED') return 'neutral';
  return 'neutral';
}

export function PostingStatusBadge({ status }: { status: string }) {
  return <Badge variant={postingStatusVariant(status)}>{postingStatusLabel(status)}</Badge>;
}

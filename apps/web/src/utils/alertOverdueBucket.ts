/**
 * Overdue bucket filter: whether a customer row has overdue amount in configured bucket or worse.
 */

import type { ARAgeingReport } from '../types';
import type { OverdueBucket } from '../types/alertPreferences';

export function isOverdueInBucketOrWorse(
  row: ARAgeingReport['rows'][0],
  bucket: OverdueBucket
): boolean {
  const b31 = parseFloat(row.bucket_31_60 || '0');
  const b61 = parseFloat(row.bucket_61_90 || '0');
  const b90 = parseFloat(row.bucket_90_plus || '0');
  if (bucket === '31_60') return b31 > 0 || b61 > 0 || b90 > 0;
  if (bucket === '61_90') return b61 > 0 || b90 > 0;
  return b90 > 0;
}

/**
 * Alert preferences (frontend-only, localStorage).
 * Future-proofed for when backend preferences exist.
 */

import type { AlertType } from './alerts';

export type OverdueBucket = '31_60' | '61_90' | '90_plus';

export interface AlertPreferences {
  enabled: Record<AlertType, boolean>;
  overdueBucket: OverdueBucket;
  negativeMarginThreshold: number;
  showComingSoon: boolean;
}

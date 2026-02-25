/**
 * Alert Center types (Sprint 5 – frontend-only, existing APIs).
 */

export type AlertType =
  | 'PENDING_REVIEW'
  | 'OVERDUE_CUSTOMERS'
  | 'UNPAID_LABOUR'
  | 'LOW_STOCK'
  | 'NEGATIVE_MARGIN_FIELDS';

export type AlertSeverity = 'info' | 'warning' | 'critical';

export interface Alert {
  id: string;
  type: AlertType;
  title: string;
  description: string;
  severity: AlertSeverity;
  count?: number;
  ctaLabel: string;
  ctaHref: string;
}

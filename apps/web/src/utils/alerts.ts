/**
 * Build Alert Center items from raw counts/data (no API calls).
 * Used by useAlerts to shape data from existing hooks/APIs.
 */

import type { Alert, AlertSeverity, AlertType } from '../types/alerts';

const SEVERITY_ORDER: Record<AlertSeverity, number> = {
  critical: 0,
  warning: 1,
  info: 2,
};

export function sortAlertsBySeverity(alerts: Alert[]): Alert[] {
  return [...alerts].sort(
    (a, b) => SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity]
  );
}

export function buildAlert(
  type: AlertType,
  opts: {
    title: string;
    description: string;
    severity: AlertSeverity;
    count?: number;
    ctaLabel: string;
    ctaHref: string;
  }
): Alert {
  return {
    id: type,
    type,
    title: opts.title,
    description: opts.description,
    severity: opts.severity,
    count: opts.count,
    ctaLabel: opts.ctaLabel,
    ctaHref: opts.ctaHref,
  };
}

export function buildPendingReviewAlert(count: number): Alert | null {
  if (count <= 0) return null;
  return buildAlert('PENDING_REVIEW', {
    title: 'Pending review',
    description: 'Unposted operational transactions need review and posting.',
    severity: 'warning',
    count,
    ctaLabel: 'Review transactions',
    ctaHref: '/app/transactions',
  });
}

export function buildOverdueCustomersAlert(count: number): Alert | null {
  if (count <= 0) return null;
  return buildAlert('OVERDUE_CUSTOMERS', {
    title: 'Overdue customers',
    description: 'Customers with receivables overdue (31+ days).',
    severity: 'warning',
    count,
    ctaLabel: 'View overdue customers',
    ctaHref: '/app/alerts/overdue-customers',
  });
}

export function buildUnpaidLabourAlert(count: number): Alert | null {
  if (count <= 0) return null;
  return buildAlert('UNPAID_LABOUR', {
    title: 'Unpaid labour',
    description: 'Workers with outstanding wages to be paid.',
    severity: 'critical',
    count,
    ctaLabel: 'View unpaid labour',
    ctaHref: '/app/alerts/unpaid-labour',
  });
}

export function buildLowStockAlert(count: number): Alert | null {
  if (count <= 0) return null;
  return buildAlert('LOW_STOCK', {
    title: 'Low stock',
    description: 'Inventory items below minimum level.',
    severity: 'warning',
    count,
    ctaLabel: 'View stock on hand',
    ctaHref: '/app/inventory/stock-on-hand',
  });
}

export function buildLowStockComingSoonAlert(): Alert {
  return buildAlert('LOW_STOCK', {
    title: 'Low stock alerts',
    description: 'Alert rules for low stock (vs minimum level) are coming soon.',
    severity: 'info',
    ctaLabel: 'Stock on hand',
    ctaHref: '/app/inventory/stock-on-hand',
  });
}

export function buildNegativeMarginFieldsAlert(count: number): Alert | null {
  if (count <= 0) return null;
  return buildAlert('NEGATIVE_MARGIN_FIELDS', {
    title: 'Negative margin fields',
    description: 'Projects with negative margin in the active cycle period.',
    severity: 'warning',
    count,
    ctaLabel: 'View negative margin fields',
    ctaHref: '/app/alerts/negative-margin',
  });
}

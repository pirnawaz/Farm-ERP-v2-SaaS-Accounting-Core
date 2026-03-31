import type { ReportPageMetadata } from './reportPageMetadata';

/** Tabs on the in-app Reports hub (`ReportsPage`). */
export type ReportsHubTab = 'trial-balance' | 'general-ledger' | 'project-statement';

/**
 * Declarative metadata for each hub tab. Aligns with `ReportPageMetadata` for consistency
 * with standalone report routes.
 */
export const REPORT_HUB_METADATA: Record<ReportsHubTab, ReportPageMetadata> = {
  'trial-balance': {
    pageTitle: 'Trial balance (preview)',
    reportTitle: 'Trial balance',
    periodMode: 'asOf',
    scopeType: 'tenantWide',
    reportKind: 'trial_balance',
    exportAvailable: true,
    printAvailable: true,
    emptyStateKey: 'noDataForPeriod',
  },
  'general-ledger': {
    pageTitle: 'General ledger (preview)',
    reportTitle: 'General ledger',
    periodMode: 'range',
    scopeType: 'mixed',
    reportKind: 'general_ledger',
    exportAvailable: true,
    printAvailable: true,
    emptyStateKey: 'noDataForPeriod',
  },
  'project-statement': {
    pageTitle: 'Field Cycle statement (preview)',
    reportTitle: 'Field Cycle statement',
    periodMode: 'asOf',
    scopeType: 'project',
    reportKind: 'project_statement',
    exportAvailable: false,
    printAvailable: false,
    emptyStateKey: 'noRecords',
  },
};

/**
 * Nav visibility assertions by module (data-testid nav-* from AppLayout).
 */

import type { Page } from '@playwright/test';

/** Map module key to nav test id(s). Nav test ids are derived from href: nav-{path without /app/}. */
export const MODULE_NAV_IDS: Record<string, string[]> = {
  land: ['nav-land'],
  projects_crop_cycles: ['nav-crop-cycles', 'nav-allocations', 'nav-projects', 'nav-transactions'],
  treasury_payments: ['nav-payments'],
  treasury_advances: ['nav-advances'],
  settlements: ['nav-settlement'],
  ar_sales: ['nav-sales'],
  inventory: ['nav-inventory'],
  labour: ['nav-labour'],
  crop_ops: ['nav-crop-ops', 'nav-harvests'],
  machinery: [
    'nav-machinery-work-logs',
    'nav-machinery-services',
    'nav-machinery-charges',
    'nav-machinery-maintenance-jobs',
    'nav-machinery-reports-profitability',
    'nav-machinery-machines',
    'nav-machinery-maintenance-types',
    'nav-machinery-rate-cards',
  ],
  reports: [
    'nav-reports-trial-balance',
    'nav-reports-general-ledger',
    'nav-reports-project-pl',
    'nav-reports-crop-cycle-pl',
    'nav-reports-account-balances',
    'nav-reports-cashbook',
    'nav-reports-ar-ageing',
    'nav-reports-sales-margin',
    'nav-reports-party-ledger',
    'nav-reports-party-summary',
    'nav-reports-role-ageing',
    'nav-reports-reconciliation-dashboard',
  ],
};

/** Assert nav entries for the given module keys are visible. */
export async function assertNavVisible(page: Page, keys: string[]): Promise<void> {
  for (const moduleKey of keys) {
    const ids = MODULE_NAV_IDS[moduleKey];
    if (!ids?.length) continue;
    const first = page.locator(`[data-testid="${ids[0]}"]`);
    await first.waitFor({ state: 'visible', timeout: 5000 });
  }
}

/** Assert nav entries for the given module keys are hidden (or absent). */
export async function assertNavHidden(page: Page, keys: string[]): Promise<void> {
  for (const moduleKey of keys) {
    const ids = MODULE_NAV_IDS[moduleKey];
    if (!ids?.length) continue;
    for (const id of ids) {
      await page.locator(`[data-testid="${id}"]`).waitFor({ state: 'hidden', timeout: 2000 }).catch(() => {});
    }
  }
}

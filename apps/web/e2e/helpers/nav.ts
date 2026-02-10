import { Page } from '@playwright/test';

/** Go to login page (relative to baseURL). */
export async function gotoLogin(page: Page): Promise<void> {
  await page.goto('/login');
}

/** Go to app dashboard (requires auth context set). */
export async function gotoDashboard(page: Page): Promise<void> {
  await page.goto('/app/dashboard');
}

/** Go to platform tenants (platform admin only). */
export async function gotoPlatformTenants(page: Page): Promise<void> {
  await page.goto('/app/platform/tenants');
}

/** Go to tenant admin module management. */
export async function gotoModuleManagement(page: Page): Promise<void> {
  await page.goto('/app/admin/modules');
}

/** Go to transactions list. */
export async function gotoTransactions(page: Page): Promise<void> {
  await page.goto('/app/transactions');
}

/** Go to a transaction detail by id. */
export async function gotoTransactionDetail(page: Page, id: string): Promise<void> {
  await page.goto(`/app/transactions/${id}`);
}

/** Go to machinery services list. */
export async function gotoMachineryServices(page: Page): Promise<void> {
  await page.goto('/app/machinery/services');
}

/** Go to a machinery service detail by id. */
export async function gotoMachineryServiceDetail(page: Page, id: string): Promise<void> {
  await page.goto(`/app/machinery/services/${id}`);
}

/** Go to posting group detail by id. */
export async function gotoPostingGroupDetail(page: Page, id: string): Promise<void> {
  await page.goto(`/app/posting-groups/${id}`);
}

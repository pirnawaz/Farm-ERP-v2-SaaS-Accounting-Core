import { expect } from '@playwright/test';

/** Assert that the app shell (tenant app layout) is visible. Prefers stable data-testid. */
export async function expectAppShellVisible(page: { locator: (s: string) => { isVisible: () => Promise<boolean> } }): Promise<void> {
  const sidebar = page.locator('[data-testid=app-sidebar]').or(page.locator('[data-testid=app-layout]')).or(page.locator('aside')).or(page.locator('nav')).first();
  await expect(sidebar).toBeVisible();
}

/** Assert success toast is shown (react-hot-toast). */
export async function expectToastSuccess(page: { locator: (s: string) => any }): Promise<void> {
  const toast = page.locator('[data-testid=toast-success], [data-sonner-toast], .toast').first();
  await expect(toast).toBeVisible({ timeout: 5000 });
}

/** Assert error toast is shown. */
export async function expectToastError(page: { locator: (s: string) => any }): Promise<void> {
  const toast = page.locator('[data-testid=toast-error], [data-sonner-toast][data-type=error], .toast').first();
  await expect(toast).toBeVisible({ timeout: 5000 });
}

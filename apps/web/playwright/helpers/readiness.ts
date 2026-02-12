/**
 * Wait for app readiness signals (e.g. tenant modules loaded) before asserting or interacting.
 */

import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

const MODULES_READY_TIMEOUT = 30_000;

/** Wait until the modules-ready marker has data-state "ready" or "error"; throws if error. */
export async function waitForModulesReady(page: Page): Promise<void> {
  const marker = page.getByTestId('modules-ready');
  try {
    await expect(marker).toHaveAttribute('data-state', /^(ready|error)$/, {
      timeout: MODULES_READY_TIMEOUT,
    });
  } catch (e) {
    const url = page.url();
    const state = await marker.getAttribute('data-state').catch(() => null);
    const errMsg = await marker.getAttribute('data-modules-error').catch(() => null);
    throw new Error(
      `waitForModulesReady timed out. URL: ${url}, data-state: ${state ?? 'missing'}${errMsg ? `, data-modules-error: ${errMsg}` : ''}. Original: ${e instanceof Error ? e.message : String(e)}`
    );
  }
  const state = await marker.getAttribute('data-state');
  if (state === 'error') {
    const errMsg = await marker.getAttribute('data-modules-error');
    throw new Error(
      `Modules failed to load (data-state=error).${errMsg ? ` ${errMsg}` : ''}`
    );
  }
}

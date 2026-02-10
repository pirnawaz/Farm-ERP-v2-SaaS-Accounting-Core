/**
 * Module gating tests driven by contract matrix.
 * Module state is set via API (setTenantModuleOverrides), not UI.
 */
import { test, expect } from '@playwright/test';
import { loginDev } from '../helpers/auth';
import { gotoDashboard } from '../helpers/nav';
import { readSeedState } from '../helpers/seed';
import { disableModules, enableModules } from '../helpers/tenantSetup';
import { GATING_CONTRACTS } from '../contracts/modules.contract';

const BASE_URL = process.env.BASE_URL || 'http://localhost:3000';

test.describe('Module gating', () => {
  for (const contract of GATING_CONTRACTS) {
    test.describe(`${contract.key}`, () => {
      test(`when disabled: nav hidden, routes redirect, API ${contract.apiGated ? '403' : 'not 403'}`, async ({ page }) => {
        const seed = readSeedState();
        if (!seed) {
          test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
          return;
        }
        await loginDev(page, { tenantId: seed.tenant_id, role: 'tenant_admin', seed });
        await disableModules(page, [contract.key]);

        if (contract.uiGated) {
          await gotoDashboard(page);
          await expect(page.getByTestId(contract.navTextOrTestId)).toHaveCount(0);

          for (const path of contract.protectedPaths) {
            const fullPath = path.startsWith('/') ? path : '/app/' + path;
            await page.goto(`${BASE_URL}${fullPath}`);
            await page.waitForLoadState('domcontentloaded');
            await expect(page).toHaveURL(/\/(app\/?|app\/dashboard)(\?|$)/);
          }
        }

        if (contract.apiGated && contract.apiProbe.length > 0) {
          for (const probe of contract.apiProbe) {
            const url = probe.url.startsWith('http') ? probe.url : BASE_URL + probe.url;
            const res = await page.request.fetch(url, { method: probe.method });
            expect(res.status()).toBe(403);
            const body = await res.json().catch(() => ({})) as { message?: string };
            expect(body.message).toContain('not enabled');
          }
        }

        await enableModules(page, [contract.key]);
      });

      test(`when enabled: nav visible, route loads, API 200`, async ({ page }) => {
        const seed = readSeedState();
        if (!seed) {
          test.skip(true, 'Seed state missing; run API with APP_DEBUG=true so globalSetup can seed.');
          return;
        }
        await loginDev(page, { tenantId: seed.tenant_id, role: 'tenant_admin', seed });
        await enableModules(page, [contract.key]);

        if (contract.uiGated) {
          await gotoDashboard(page);
          await expect(page.getByTestId(contract.navTextOrTestId).first()).toBeAttached();

          const firstPath = contract.protectedPaths[0];
          if (firstPath) {
            const fullPath = firstPath.startsWith('/') ? firstPath : '/app/' + firstPath;
            await page.goto(`${BASE_URL}${fullPath}`);
            await page.waitForLoadState('domcontentloaded');
            await expect(page).toHaveURL(new RegExp(fullPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(\\?|$|/)'));
          }
        }

        if (contract.apiGated && contract.apiProbe.length > 0) {
          for (const probe of contract.apiProbe) {
            const url = probe.url.startsWith('http') ? probe.url : BASE_URL + probe.url;
            const res = await page.request.fetch(url, { method: probe.method });
            expect(res.status()).toBe(200);
          }
        }
      });
    });
  }
});

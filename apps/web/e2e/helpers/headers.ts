import { Page } from '@playwright/test';

/** Exact localStorage keys used by the app (see AUTH_CONTEXT.md) */
export const STORAGE_KEYS = {
  tenantId: 'farm_erp_tenant_id',
  userRole: 'farm_erp_user_role',
  userId: 'farm_erp_user_id',
} as const;

export interface AuthContext {
  tenantId: string;
  userRole: string;
  userId?: string;
}

/**
 * Set the same localStorage keys the app uses so API requests send X-Tenant-Id, X-User-Role, X-User-Id.
 * Call before navigating to app routes (or after login) so the api-client sees the context.
 */
export async function setAuthContext(page: Page, ctx: AuthContext): Promise<void> {
  await page.evaluate(
    ({ keys, ctx }) => {
      localStorage.setItem(keys.tenantId, ctx.tenantId);
      localStorage.setItem(keys.userRole, ctx.userRole);
      if (ctx.userId) {
        localStorage.setItem(keys.userId, ctx.userId);
      }
    },
    { keys: STORAGE_KEYS, ctx }
  );
}

/**
 * Run before any page load so every document gets these keys (e.g. in beforeEach).
 * Use when you navigate to baseURL and want context already set.
 */
export async function addAuthContextInitScript(
  page: Page,
  ctx: AuthContext
): Promise<void> {
  await page.addInitScript(
    ({ keys, ctx }) => {
      localStorage.setItem(keys.tenantId, ctx.tenantId);
      localStorage.setItem(keys.userRole, ctx.userRole);
      if (ctx.userId) {
        localStorage.setItem(keys.userId, ctx.userId);
      }
    },
    { keys: STORAGE_KEYS, ctx }
  );
}

/**
 * E2E tenant and module setup via API (no UI toggling).
 * Use after loginDev so page.request sends the auth cookie.
 */

import type { Page } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'http://localhost:3000';

export interface TenantModuleItem {
  key: string;
  name: string;
  description: string | null;
  is_core: boolean;
  sort_order: number;
  enabled: boolean;
  status: string;
}

export interface TenantSetupResult {
  tenantId: string;
  cropCycleId: string;
  projectId: string;
}

/**
 * Get current tenant modules from API. Requires authenticated page (e.g. after loginDev).
 */
export async function getTenantModules(page: Page): Promise<TenantModuleItem[]> {
  const res = await page.request.get(`${BASE_URL}/api/tenant/modules`, {
    headers: { Accept: 'application/json' },
  });
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`GET /api/tenant/modules failed (${res.status}): ${text}`);
  }
  const data = (await res.json()) as { modules: TenantModuleItem[] };
  return data.modules;
}

/**
 * Set module states via API. Overrides only the given keys; others keep current state.
 * Requires authenticated page (tenant_admin).
 */
export async function setTenantModuleOverrides(
  page: Page,
  overrides: Record<string, boolean>
): Promise<void> {
  const modules = await getTenantModules(page);
  const payload = {
    modules: modules.map((m) => ({
      key: m.key,
      enabled: overrides[m.key] ?? m.enabled,
    })),
  };
  const res = await page.request.put(`${BASE_URL}/api/tenant/modules`, {
    data: payload,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  });
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`PUT /api/tenant/modules failed (${res.status}): ${text}`);
  }
}

/**
 * Enable only the given module keys (and core). Disable all others.
 * Use for tests that need a minimal set of modules (e.g. accounting invariant).
 */
export async function setTenantModulesOnly(
  page: Page,
  enabledKeys: string[]
): Promise<void> {
  const modules = await getTenantModules(page);
  const set = new Set(enabledKeys);
  const overrides: Record<string, boolean> = {};
  for (const m of modules) {
    overrides[m.key] = m.is_core || set.has(m.key);
  }
  await setTenantModuleOverrides(page, overrides);
}

/**
 * Disable a single module (or multiple). Others unchanged.
 */
export async function disableModules(page: Page, keys: string[]): Promise<void> {
  const overrides: Record<string, boolean> = {};
  for (const k of keys) overrides[k] = false;
  await setTenantModuleOverrides(page, overrides);
}

/**
 * Enable a single module (or multiple). Others unchanged.
 */
export async function enableModules(page: Page, keys: string[]): Promise<void> {
  const overrides: Record<string, boolean> = {};
  for (const k of keys) overrides[k] = true;
  await setTenantModuleOverrides(page, overrides);
}

/**
 * Playwright request helpers for calling backend from tests (e.g. with storageState cookies).
 */

import type { APIRequestContext } from '@playwright/test';

const API_BASE_URL = process.env.API_BASE_URL ?? 'http://localhost:8000';

export function apiUrl(path: string): string {
  const p = path.startsWith('/') ? path : `/${path}`;
  return `${API_BASE_URL}${p}`;
}

/**
 * Call GET with tenant context. Use request context from test that has storageState
 * so cookies are sent; add X-Tenant-Id from storageState or pass explicitly.
 */
export async function apiGet<T>(
  request: APIRequestContext,
  path: string,
  tenantId: string
): Promise<T> {
  const url = path.startsWith('http') ? path : apiUrl(path);
  const res = await request.get(url, {
    headers: { 'X-Tenant-Id': tenantId, Accept: 'application/json' },
  });
  if (!res.ok) {
    throw new Error(`GET ${path} failed: ${res.status} ${await res.text()}`);
  }
  return res.json() as Promise<T>;
}

/**
 * E2E module provisioning: get catalog and set tenant modules via API.
 * Idempotent; safe to run repeatedly.
 */

const API_BASE_URL = process.env.API_BASE_URL ?? 'http://localhost:8000';

export type E2EProfile = 'core' | 'all';

/** Core profile: enable these OPTIONAL modules only. All core modules (is_core=true) are never in PUT payload. */
const CORE_PROFILE_ENABLED_KEYS = new Set(['land', 'projects_crop_cycles', 'reports']);

export interface TenantModuleItem {
  key: string;
  name: string;
  enabled: boolean;
  status: string;
  is_core?: boolean;
}

export interface TenantModulesResponse {
  modules: TenantModuleItem[];
}

export interface ModuleApiHeaders {
  cookie: string;
  tenantId: string;
  userRole?: string;
  userId?: string;
}

function buildHeaders({ cookie, tenantId, userRole, userId }: ModuleApiHeaders): Record<string, string> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Tenant-Id': tenantId,
  };
  if (cookie) headers['Cookie'] = cookie;
  if (userRole) headers['X-User-Role'] = userRole;
  if (userId) headers['X-User-Id'] = userId;
  return headers;
}

/**
 * Fetch current tenant modules (GET /api/tenant/modules).
 * Requires auth cookie, X-Tenant-Id, X-User-Role, X-User-Id.
 */
export async function getTenantModules(opts: ModuleApiHeaders): Promise<TenantModulesResponse> {
  const res = await fetch(`${API_BASE_URL}/api/tenant/modules`, {
    method: 'GET',
    headers: buildHeaders(opts),
  });
  if (!res.ok) {
    throw new Error(`GET tenant/modules failed: ${res.status} ${await res.text()}`);
  }
  return res.json() as Promise<TenantModulesResponse>;
}

/**
 * Update tenant modules (PUT /api/tenant/modules).
 * Payload: { modules: [ { key: string, enabled: boolean } ] }.
 * Only include OPTIONAL modules (is_core=false). Never include core modules.
 * Idempotent.
 */
export async function putTenantModules(
  opts: ModuleApiHeaders,
  modules: { key: string; enabled: boolean }[]
): Promise<TenantModulesResponse> {
  const res = await fetch(`${API_BASE_URL}/api/tenant/modules`, {
    method: 'PUT',
    headers: buildHeaders(opts),
    body: JSON.stringify({ modules }),
  });
  if (!res.ok) {
    throw new Error(`PUT tenant/modules failed: ${res.status} ${await res.text()}`);
  }
  return res.json() as Promise<TenantModulesResponse>;
}

/**
 * Apply E2E profile via API (no UI).
 * Build PUT payload ONLY from optional modules (is_core === false). Never toggle core modules.
 * - core: enable land, projects_crop_cycles, reports; disable all other optional.
 * - all: enable all optional modules.
 * Defensive: if API does not expose is_core, only send enable for required modules (no disable).
 * Returns enabled module keys after update.
 */
export async function applyE2EProfile(
  opts: ModuleApiHeaders & { profile: E2EProfile }
): Promise<string[]> {
  const { profile, ...apiOpts } = opts;
  const current = await getTenantModules(apiOpts);

  const optionalModules = current.modules.filter((m) => m.is_core === false);
  const hasCoreFlag = current.modules.some((m) => typeof m.is_core === 'boolean');

  let desired: { key: string; enabled: boolean }[];

  if (!hasCoreFlag) {
    // Defensive: do not attempt to disable anything; only send enable for required modules
    const catalogKeys = new Set(current.modules.map((m) => m.key));
    if (profile === 'core') {
      desired = Array.from(CORE_PROFILE_ENABLED_KEYS)
        .filter((key) => catalogKeys.has(key))
        .map((key) => ({ key, enabled: true }));
    } else {
      desired = current.modules.map((m) => ({ key: m.key, enabled: true }));
    }
  } else {
    desired = optionalModules.map((m) => {
      if (profile === 'core') {
        return { key: m.key, enabled: CORE_PROFILE_ENABLED_KEYS.has(m.key) };
      }
      return { key: m.key, enabled: true };
    });
  }

  const updated = await putTenantModules(apiOpts, desired);
  const enabledKeys = updated.modules.filter((m) => m.enabled).map((m) => m.key);
  return enabledKeys;
}

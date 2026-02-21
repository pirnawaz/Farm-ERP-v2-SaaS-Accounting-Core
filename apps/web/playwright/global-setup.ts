import type { FullConfig } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { applyE2EProfile, type E2EProfile } from './helpers/modules';

const BASE_URL = process.env.BASE_URL ?? 'http://127.0.0.1:3000';
const API_BASE_URL = process.env.API_BASE_URL ?? 'http://localhost:8000';

function parseSetCookieHeaders(headers: Headers): { name: string; value: string } | null {
  const setCookies = headers.getSetCookie?.() ?? (headers.get('set-cookie') ? [headers.get('set-cookie')!] : []);
  if (setCookies.length === 0) return null;
  const first = setCookies[0];
  const eq = first.indexOf('=');
  if (eq === -1) return null;
  const name = first.slice(0, eq).trim();
  const valuePart = first.slice(eq + 1);
  const semicolon = valuePart.indexOf(';');
  const value = semicolon === -1 ? valuePart.trim() : valuePart.slice(0, semicolon).trim();
  return { name, value };
}

async function globalSetup(_config: FullConfig): Promise<void> {
  const seedRes = await fetch(`${API_BASE_URL}/api/dev/e2e/seed`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ tenant_name: 'E2E Farm' }),
  });
  if (!seedRes.ok) {
    throw new Error(`E2E seed failed: ${seedRes.status} ${await seedRes.text()}`);
  }
  const seedData = (await seedRes.json()) as {
    tenant_id: string;
    tenant_admin_user_id: string;
    [key: string]: unknown;
  };
  const { tenant_id, tenant_admin_user_id } = seedData;

  const profile = (process.env.E2E_PROFILE ?? 'core') as E2EProfile;
  const isPlatformProfile = profile === 'all';
  const role = isPlatformProfile ? 'platform_admin' : 'tenant_admin';
  const userId = isPlatformProfile
    ? (seedData.platform_admin_user_id as string)
    : tenant_admin_user_id;

  const authRes = await fetch(`${API_BASE_URL}/api/dev/e2e/auth-cookie`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      tenant_id,
      role,
      user_id: userId,
    }),
  });
  if (!authRes.ok) {
    throw new Error(`E2E auth-cookie failed: ${authRes.status} ${await authRes.text()}`);
  }
  const cookie = parseSetCookieHeaders(authRes.headers);

  const cookieHeader = cookie ? `${cookie.name}=${cookie.value}` : '';
  let enabledModules: string[] = [];
  if (!isPlatformProfile) {
    enabledModules = await applyE2EProfile({
      cookie: cookieHeader,
      tenantId: tenant_id,
      userRole: 'tenant_admin',
      userId: tenant_admin_user_id,
      profile,
    });
  }

  const authDir = path.join(process.cwd(), 'playwright', '.auth');
  fs.mkdirSync(authDir, { recursive: true });

  const origin = new URL(BASE_URL).origin;
  const state: {
    cookies: Array<{ name: string; value: string; domain: string; path: string }>;
    origins: Array<{ origin: string; localStorage: Array<{ name: string; value: string }> }>;
  } = {
    cookies: [],
    origins: [
      {
        origin,
        localStorage: [
          { name: 'farm_erp_tenant_id', value: isPlatformProfile ? '' : tenant_id },
          { name: 'farm_erp_user_role', value: role },
          { name: 'farm_erp_user_id', value: userId },
        ],
      },
    ],
  };
  if (cookie) {
    state.cookies.push({
      name: cookie.name,
      value: cookie.value,
      domain: new URL(BASE_URL).hostname,
      path: '/',
      expires: -1,
      httpOnly: true,
      secure: false,
      sameSite: 'Lax',
    });
  }
  fs.writeFileSync(path.join(authDir, 'state.json'), JSON.stringify(state), 'utf-8');
  fs.writeFileSync(path.join(authDir, 'seed.json'), JSON.stringify(seedData, null, 2), 'utf-8');
  fs.writeFileSync(
    path.join(authDir, 'profile.json'),
    JSON.stringify({ profile, enabled_modules: enabledModules }, null, 2),
    'utf-8'
  );
}

export default globalSetup;

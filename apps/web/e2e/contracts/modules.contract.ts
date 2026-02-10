/**
 * Module contract matrix for E2E gating tests.
 * - uiGated: nav item hidden and protected routes redirect when disabled.
 * - apiGated: API returns 403 with "Module X is not enabled" when disabled.
 * - Core modules (accounting_core, projects_crop_cycles, reports, treasury_payments) cannot be disabled; excluded from gating tests.
 * - projects_crop_cycles is frontend-only gated (no require_module on API).
 */
export const CORE_MODULE_KEYS = ['accounting_core', 'projects_crop_cycles', 'reports', 'treasury_payments'] as const;

export interface ApiProbe {
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  url: string;
}

export interface ModuleContract {
  key: string;
  uiGated: boolean;
  apiGated: boolean;
  /** Selector hint: data-testid for nav item (e.g. nav-land) or text for nav link */
  navTextOrTestId: string;
  /** UI routes to try direct navigation; expect redirect when module disabled */
  protectedPaths: string[];
  /** API probes that should return 403 when disabled (only if apiGated) */
  apiProbe: ApiProbe[];
}

const baseApi = '/api';
const baseApiV1 = '/api/v1';

export const MODULE_CONTRACTS: ModuleContract[] = [
  {
    key: 'accounting_core',
    uiGated: false,
    apiGated: false,
    navTextOrTestId: 'nav-accounting_core',
    protectedPaths: [],
    apiProbe: [],
  },
  {
    key: 'projects_crop_cycles',
    uiGated: true,
    apiGated: false,
    navTextOrTestId: 'nav-crop-cycles',
    protectedPaths: ['/app/crop-cycles', '/app/projects', '/app/transactions'],
    apiProbe: [], // API not behind require_module
  },
  {
    key: 'land',
    uiGated: true,
    apiGated: true,
    navTextOrTestId: 'nav-land',
    protectedPaths: ['/app/land'],
    apiProbe: [{ method: 'GET', url: `${baseApi}/land-parcels` }],
  },
  {
    key: 'treasury_payments',
    uiGated: true,
    apiGated: true,
    navTextOrTestId: 'nav-payments',
    protectedPaths: ['/app/payments'],
    apiProbe: [{ method: 'GET', url: `${baseApi}/payments` }],
  },
  {
    key: 'treasury_advances',
    uiGated: true,
    apiGated: true,
    navTextOrTestId: 'nav-advances',
    protectedPaths: ['/app/advances'],
    apiProbe: [{ method: 'GET', url: `${baseApi}/advances` }],
  },
  {
    key: 'ar_sales',
    uiGated: true,
    apiGated: true,
    navTextOrTestId: 'nav-sales',
    protectedPaths: ['/app/sales'],
    apiProbe: [{ method: 'GET', url: `${baseApi}/sales` }],
  },
  {
    key: 'settlements',
    uiGated: true,
    apiGated: true,
    navTextOrTestId: 'nav-settlement',
    protectedPaths: ['/app/settlement'],
    apiProbe: [{ method: 'GET', url: `${baseApi}/settlements` }],
  },
  {
    key: 'reports',
    uiGated: true,
    apiGated: true,
    navTextOrTestId: 'nav-reports-trial-balance',
    protectedPaths: ['/app/reports/trial-balance'],
    apiProbe: [{ method: 'GET', url: `${baseApi}/reports/trial-balance` }],
  },
  {
    key: 'inventory',
    uiGated: true,
    apiGated: true,
    navTextOrTestId: 'nav-inventory',
    protectedPaths: ['/app/inventory'],
    apiProbe: [{ method: 'GET', url: `${baseApiV1}/inventory/items` }],
  },
  {
    key: 'labour',
    uiGated: true,
    apiGated: true,
    navTextOrTestId: 'nav-labour',
    protectedPaths: ['/app/labour'],
    apiProbe: [{ method: 'GET', url: `${baseApiV1}/labour/workers` }],
  },
  {
    key: 'machinery',
    uiGated: true,
    apiGated: true,
    navTextOrTestId: 'nav-machinery-services',
    protectedPaths: ['/app/machinery/services'],
    apiProbe: [{ method: 'GET', url: `${baseApiV1}/machinery/machines` }],
  },
  {
    key: 'loans',
    uiGated: false,
    apiGated: false,
    navTextOrTestId: 'nav-loans',
    protectedPaths: [],
    apiProbe: [], // toggle only; no UI/api gating to assert yet
  },
  {
    key: 'crop_ops',
    uiGated: true,
    apiGated: true,
    navTextOrTestId: 'nav-crop-ops',
    protectedPaths: ['/app/crop-ops', '/app/harvests'],
    apiProbe: [{ method: 'GET', url: `${baseApiV1}/crop-ops/activity-types` }],
  },
];

/** Contracts to run in gating tests (exclude core modules and loans). */
export const GATING_CONTRACTS = MODULE_CONTRACTS.filter(
  (c) => !CORE_MODULE_KEYS.includes(c.key as (typeof CORE_MODULE_KEYS)[number]) && c.key !== 'loans'
);

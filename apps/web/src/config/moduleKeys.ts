/**
 * Canonical module keys matching backend (modules table / GET /api/tenant/modules).
 * Use these everywhere: nav requiredModules, Modules page, isModuleEnabled checks.
 */
export const MODULE_KEYS = [
  'accounting_core',
  'projects_crop_cycles',
  'land',
  'treasury_payments',
  'treasury_advances',
  'ar_sales',
  'settlements',
  'reports',
  'inventory',
  'labour',
  'machinery',
  'loans',
  'crop_ops',
  'land_leases',
] as const;

export type ModuleKey = (typeof MODULE_KEYS)[number];

/** Display names for toasts and UI (e.g. "Enable Projects & Crop Cycles to use this feature"). */
export const MODULE_LABELS: Record<ModuleKey, string> = {
  accounting_core: 'Accounting Core',
  projects_crop_cycles: 'Projects & Crop Cycles',
  land: 'Land',
  treasury_payments: 'Treasury – Payments',
  treasury_advances: 'Treasury – Advances',
  ar_sales: 'AR & Sales',
  settlements: 'Settlements',
  reports: 'Reports',
  inventory: 'Inventory',
  labour: 'Labour',
  machinery: 'Machinery',
  loans: 'Loans',
  crop_ops: 'Crop Operations',
  land_leases: 'Land Lease (Maqada)',
};

export function getModuleLabel(key: string): string {
  return MODULE_LABELS[key as ModuleKey] ?? key;
}

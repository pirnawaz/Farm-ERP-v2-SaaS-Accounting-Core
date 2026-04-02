/**
 * Frontend-only policy: whether "All Crop Cycles" is an appropriate operating context for the current route.
 * Does not change API behaviour, posting, or persistence — UX guardrails in the header only.
 *
 * When `allowsAllCropCyclesForPath` is false, the crop cycle selector disables the "All Crop Cycles" option
 * and a route notice can prompt the user to pick a specific cycle.
 */

/** Path prefixes where a single crop cycle context is expected (operational entry, capture, machinery, etc.). */
const DENY_ALL_CROP_CYCLES_PREFIXES: readonly string[] = [
  '/app/transactions',
  '/app/crop-ops',
  '/app/harvests',
  '/app/labour',
  '/app/machinery',
  '/app/land-leases',
  '/app/allocations',
  '/app/projects',
  '/app/parties',
  '/app/payments',
  '/app/advances',
  '/app/sales',
  '/app/settlement',
  '/app/accounting',
  '/app/posting-groups',
  '/app/crop-cycles/season-setup',
];

const INVENTORY_DASHBOARD_PATH = '/app/inventory';

/**
 * `true` = user may select "All Crop Cycles" in the header for this route.
 * `false` = "All Crop Cycles" is disabled; a specific cycle should be chosen.
 */
export function allowsAllCropCyclesForPath(pathname: string): boolean {
  const path = pathname.split('?')[0] || '';

  /** Inventory dashboard is read/summary; sub-routes are operational documents. */
  if (path === INVENTORY_DASHBOARD_PATH) {
    return true;
  }
  if (path.startsWith(`${INVENTORY_DASHBOARD_PATH}/`)) {
    return false;
  }

  for (const prefix of DENY_ALL_CROP_CYCLES_PREFIXES) {
    if (path === prefix || path.startsWith(`${prefix}/`)) {
      return false;
    }
  }
  return true;
}

/**
 * Inverse of `allowsAllCropCyclesForPath` — useful for copy and notices.
 */
export function singleCropCycleContextRecommended(pathname: string): boolean {
  return !allowsAllCropCyclesForPath(pathname);
}

# Crop cycle scope (header)

The tenant app header includes a **Crop cycle** control (not “Scope”). It sets the **application context** for dashboards and other scoped views: either **All Crop Cycles** or a **specific** crop cycle.

## Behaviour

- **Persistence:** Selection is stored per tenant in `localStorage` (`terrava.scope.*` in `CropCycleScopeContext`).
- **Frontend policy:** `apps/web/src/config/cropCycleScopePolicy.ts` defines where **All Crop Cycles** is available in the selector. On routes that expect a single working context, that option is **disabled** and an inline **notice** may appear if the user still has “All” selected — **no automatic scope change** is applied for that case.
- **Backend:** Unchanged; this is UX only.

## Adding routes

1. If a new area is **read-heavy / overview** (e.g. dashboards, reports), default policy usually **allows** “All Crop Cycles” (no change).
2. If it is **operational entry, capture, or posting-related**, add a prefix to `DENY_ALL_CROP_CYCLES_PREFIXES` in `cropCycleScopePolicy.ts` and extend tests in `cropCycleScopePolicy.test.ts`.

## Related files

- `apps/web/src/components/CropCycleScopeSelector.tsx`
- `apps/web/src/components/CropCycleScopeRouteNotice.tsx`
- `apps/web/src/contexts/CropCycleScopeContext.tsx`

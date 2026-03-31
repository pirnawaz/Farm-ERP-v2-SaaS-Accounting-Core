# Tenant navigation (sidebar)

The tenant app shell uses a **domain → section → item** model. Configuration lives in **`apps/web/src/config/nav.ts`**; route matching for active/expanded states is in **`apps/web/src/config/navMatch.ts`**.

## Primary API

| Export | Purpose |
|--------|---------|
| `getNavDomains(term, showOrchards, showLivestock)` | **Use this.** Returns top-level domains (Farm, Operations, Finance, Governance, Settings) with optional section titles and `NavItem` trees. |
| `filterDomainsByPermission(domains, can)` | Filters items by permission; pass `can` as `(p) => canPermission(userRole, p)` or equivalent. |
| `isSubmenuParent(item)` | Type guard for items with `children` + `submenuKey` (e.g. Machinery). |

## Deprecated

| Export | Purpose |
|--------|---------|
| `getNavGroups(...)` | Flattens domains into legacy `{ name, items: [...] }` groups. **Do not use for new navigation UI.** Kept for backward compatibility only. |

## Domain layout (current)

- **Farm** — Farm Pulse, Today, Alerts  
- **Operations** — Sections include *Land & Crops*, *Work & Harvest* (includes **Drafts (Unposted)** at `/app/transactions`), *Machinery* (submenu), *Inventory*, *People*  
- **Finance** — *Money & treasury*; *Accounting & reports*  
- **Governance** — Governance overview, **Farm Integrity**, **Audit Logs**  
- **Settings** — Farm Profile, Users, Roles, Modules, Localisation (not Audit/Farm Integrity)

**Route URLs are stable** — navigation changes are structural only; do not rename paths for menu purposes.

## `navMatch` helpers

- **`isPathUnderRoute(pathname, to)`** — `true` for exact match or nested routes under `to`.  
- **`isNavItemActive(pathname, item)`** — respects submenu children when `submenuKey` is set.  
- **`domainHasActivePath(pathname, domain)`** — `true` if any visible item in the domain matches the current path.

## Contributor rules

1. **New pages** — Add a `NavItem` under the correct **domain** and **section** in `getNavDomains()`. Do not reintroduce a flat, single-level sidebar list.  
2. **Preserve routes** — Use existing `to` paths; add new routes in the router and `nav.ts` together.  
3. **Permissions** — Keep `requiredPermission` and optional `requiredModules` aligned with backend and `permissions.ts`.  
4. **Sidebar / React** — Do not pass `can` from `useRole()` into `useMemo` dependencies for filtered nav; use **`userRole`** (stable) with **`canPermission`** from `config/permissions.ts` (see `AppSidebar.tsx`).  
5. **No backend changes** for navigation-only work.

## Related files

- `apps/web/src/components/AppSidebar.tsx` — sidebar UI, module-disabled styling, expansion state  
- `apps/web/src/config/navigation/index.ts` — re-exports `nav` + `navMatch`  
- `apps/web/src/config/terminology.ts` — `navDomain*` labels for domain titles  

**Header crop cycle context** (separate from sidebar): see [CROP_CYCLE_SCOPE.md](./CROP_CYCLE_SCOPE.md).

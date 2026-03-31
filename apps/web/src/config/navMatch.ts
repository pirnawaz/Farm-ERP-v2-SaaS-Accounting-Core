/**
 * Pure route-matching helpers for the tenant sidebar (no React).
 *
 * - `isPathUnderRoute` — active link when pathname equals `to` or is a nested detail route under it.
 * - `isNavItemActive` — for a nav item or submenu parent, whether the current location matches.
 * - `domainHasActivePath` — whether any item in a domain is active (used to expand the domain and highlight the header).
 *
 * See `docs/NAVIGATION.md` for how these fit the domain-based nav model.
 */

/** True if pathname is this route or a nested detail/form path under it. */
export function isPathUnderRoute(pathname: string, to: string): boolean {
  if (pathname === to) {
    return true;
  }
  const prefix = to.endsWith('/') ? to : `${to}/`;
  return pathname.startsWith(prefix);
}

type NavLike = {
  to: string;
  children?: { to: string }[];
  submenuKey?: string;
};

export function isNavItemActive(pathname: string, item: NavLike): boolean {
  const children = item.children;
  const submenuKey = item.submenuKey;
  if (Array.isArray(children) && children.length > 0 && submenuKey) {
    return children.some((c) => isPathUnderRoute(pathname, c.to));
  }
  return isPathUnderRoute(pathname, item.to);
}

export type NavDomainLike = {
  sections: { items: NavLike[] }[];
};

export function domainHasActivePath(pathname: string, domain: NavDomainLike): boolean {
  return domain.sections.some((s) => s.items.some((item) => isNavItemActive(pathname, item)));
}

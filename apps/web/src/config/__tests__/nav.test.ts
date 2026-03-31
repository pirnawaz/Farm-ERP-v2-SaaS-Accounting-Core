import { describe, it, expect } from 'vitest';
import { term } from '../terminology';
import { getNavDomains, filterDomainsByPermission, isSubmenuParent } from '../nav';
import { domainHasActivePath, isNavItemActive, isPathUnderRoute } from '../navMatch';

const tenantViewAll = () => true;

describe('getNavDomains', () => {
  it('returns five top-level domains in order', () => {
    const domains = getNavDomains(term, false, false);
    expect(domains.map((d) => d.domainKey)).toEqual(['farm', 'operations', 'finance', 'governance', 'settings']);
  });

  it('places Drafts (Unposted) under Operations > Work & Harvest, not as a top-level domain', () => {
    const domains = getNavDomains(term, false, false);
    const ops = domains.find((d) => d.domainKey === 'operations');
    expect(ops).toBeDefined();
    const work = ops!.sections.find((s) => s.sectionKey === 'ops-work-harvest');
    expect(work?.items.some((i) => i.key === 'pending-review')).toBe(true);
  });

  it('places Farm Integrity and Audit Logs under Governance, not Settings', () => {
    const domains = getNavDomains(term, false, false);
    const gov = domains.find((d) => d.domainKey === 'governance');
    expect(gov?.sections[0].items.map((i) => i.key)).toContain('farm-integrity');
    expect(gov?.sections[0].items.map((i) => i.key)).toContain('audit-logs');
    const settings = domains.find((d) => d.domainKey === 'settings');
    expect(settings?.sections[0].items.map((i) => i.key)).not.toContain('farm-integrity');
    expect(settings?.sections[0].items.map((i) => i.key)).not.toContain('audit-logs');
  });

  it('keeps Machinery as a nested submenu under Operations', () => {
    const domains = getNavDomains(term, false, false);
    const ops = domains.find((d) => d.domainKey === 'operations');
    const machSection = ops?.sections.find((s) => s.sectionKey === 'ops-machinery');
    expect(machSection?.items).toHaveLength(1);
    const m = machSection?.items[0];
    expect(m && isSubmenuParent(m)).toBe(true);
    if (m && isSubmenuParent(m)) {
      expect(m.children.some((c) => c.key === 'machinery-rate-cards')).toBe(true);
    }
  });

  it('does not change canonical route paths', () => {
    const domains = getNavDomains(term, false, false);
    const allItems: { to: string }[] = [];
    domains.forEach((d) => {
      d.sections.forEach((s) => {
        s.items.forEach((item) => {
          if (isSubmenuParent(item)) {
            item.children.forEach((c) => allItems.push(c));
          } else {
            allItems.push(item);
          }
        });
      });
    });
    const paths = new Set(allItems.map((i) => i.to));
    expect(paths.has('/app/transactions')).toBe(true);
    expect(paths.has('/app/governance')).toBe(true);
    expect(paths.has('/app/settings/localisation')).toBe(true);
  });
});

describe('filterDomainsByPermission', () => {
  it('removes domains with no visible items when user lacks permission', () => {
    const domains = getNavDomains(term, false, false);
    const denyAll = () => false;
    const filtered = filterDomainsByPermission(domains, denyAll);
    expect(filtered.length).toBe(0);
  });

  it('keeps Machinery submenu when children visible', () => {
    const domains = getNavDomains(term, false, false);
    const filtered = filterDomainsByPermission(domains, tenantViewAll);
    const ops = filtered.find((d) => d.domainKey === 'operations');
    expect(ops?.sections.some((s) => s.sectionKey === 'ops-machinery')).toBe(true);
  });
});

describe('navMatch', () => {
  it('marks nested routes active under parent path', () => {
    expect(isPathUnderRoute('/app/crop-ops/activities/123', '/app/crop-ops')).toBe(true);
    expect(isPathUnderRoute('/app/crop-ops', '/app/crop-ops')).toBe(true);
    expect(isPathUnderRoute('/app/crop-ops', '/app/harvests')).toBe(false);
  });

  it('domainHasActivePath detects machinery child routes', () => {
    const domains = getNavDomains(term, false, false);
    const ops = domains.find((d) => d.domainKey === 'operations')!;
    expect(domainHasActivePath('/app/machinery/machines/abc', ops)).toBe(true);
    expect(domainHasActivePath('/app/inventory', ops)).toBe(true);
    expect(domainHasActivePath('/app/dashboard', ops)).toBe(false);
  });

  it('isNavItemActive for submenu parent follows children', () => {
    const domains = getNavDomains(term, false, false);
    const ops = domains.find((d) => d.domainKey === 'operations')!;
    const mach = ops.sections.find((s) => s.sectionKey === 'ops-machinery')!.items[0];
    expect(isNavItemActive('/app/machinery/rate-cards', mach)).toBe(true);
    expect(isNavItemActive('/app/sales', mach)).toBe(false);
  });
});

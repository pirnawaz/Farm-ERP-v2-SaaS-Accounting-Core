import { describe, it, expect } from 'vitest';
import { term } from '../terminology';
import { getNavDomains, filterDomainsByPermission, isSubmenuParent, getSectionNavItems } from '../nav';
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
    expect(getSectionNavItems(work!).some((i) => i.key === 'pending-review')).toBe(true);
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
    // Machinery is currently a flat section (not a submenu parent).
    expect(machSection?.items.some((i) => i.key === 'machinery-rate-cards')).toBe(true);
  });

  it('does not change canonical route paths', () => {
    const domains = getNavDomains(term, false, false);
    const allItems: { to: string }[] = [];
    domains.forEach((d) => {
      d.sections.forEach((s) => {
        getSectionNavItems(s).forEach((item) => {
          if (isSubmenuParent(item)) {
            item.children.forEach((c) => allItems.push(c));
          } else {
            allItems.push(item);
          }
        });
      });
    });
    const paths = new Set(allItems.map((i) => i.to));
    expect(paths.has('/app/inventory')).toBe(true);
    expect(paths.has('/app/inventory/stock-on-hand')).toBe(true);
    expect(paths.has('/app/inventory/grns')).toBe(true);
    expect(paths.has('/app/transactions')).toBe(true);
    // Governance is not a canonical route path; specific pages live under internal/admin routes.
    expect(paths.has('/app/internal/farm-integrity')).toBe(true);
    expect(paths.has('/app/admin/audit-logs')).toBe(true);
    expect(paths.has('/app/settings/localisation')).toBe(true);
    expect(paths.has('/app/crop-ops')).toBe(true);
    expect(paths.has('/app/crop-ops/activities')).toBe(true);
    expect(paths.has('/app/crop-ops/activity-types')).toBe(true);
  });

  it('omits Orchard & Livestock performance report when addons disabled', () => {
    const domains = getNavDomains(term, false, false);
    const fin = domains.find((d) => d.domainKey === 'finance')!;
    const analysis = fin.sections.find((s) => s.sectionKey === 'fin-analysis')!;
    const keys = analysis.items.map((i) => i.key);
    expect(keys).not.toContain('production-units-profitability');
  });

  it('includes Orchard & Livestock performance report when any addon enabled', () => {
    const domains = getNavDomains(term, true, false);
    const fin = domains.find((d) => d.domainKey === 'finance')!;
    const analysis = fin.sections.find((s) => s.sectionKey === 'fin-analysis')!;
    const keys = analysis.items.map((i) => i.key);
    expect(keys).toContain('production-units-profitability');
  });

  it('omits Production Units (Advanced) under Land & Crops when orchard and livestock addons are disabled', () => {
    const domains = getNavDomains(term, false, false);
    const ops = domains.find((d) => d.domainKey === 'operations')!;
    const land = ops.sections.find((s) => s.sectionKey === 'ops-land-crops')!;
    expect(getSectionNavItems(land).map((i) => i.key)).not.toContain('production-units');
  });

  it('includes Production Units (Advanced) when any orchard or livestock addon is enabled', () => {
    const domains = getNavDomains(term, false, true);
    const ops = domains.find((d) => d.domainKey === 'operations')!;
    const land = ops.sections.find((s) => s.sectionKey === 'ops-land-crops')!;
    expect(getSectionNavItems(land).map((i) => i.key)).toContain('production-units');
  });

  it('groups Land & Crops into Land Setup, Crop Planning, and Advanced when addons on', () => {
    const domains = getNavDomains(term, true, true);
    const ops = domains.find((d) => d.domainKey === 'operations')!;
    const land = ops.sections.find((s) => s.sectionKey === 'ops-land-crops')!;
    expect(land.itemGroups?.map((g) => g.groupTitle)).toEqual(['Land Setup', 'Crop Planning', 'Advanced']);
    expect(land.itemGroups?.[0].items.map((i) => i.key)).toEqual(['land', 'allocations', 'land-leases']);
    expect(land.itemGroups?.[1].items.map((i) => i.key)).toEqual([
      'crop-cycles',
      'fields',
      'project-planning',
      'orchards',
      'livestock',
    ]);
    expect(land.itemGroups?.[2].items.map((i) => i.key)).toEqual(['production-units']);
  });

  it('groups Land & Crops into Land Setup and Crop Planning only when addons off', () => {
    const domains = getNavDomains(term, false, false);
    const ops = domains.find((d) => d.domainKey === 'operations')!;
    const land = ops.sections.find((s) => s.sectionKey === 'ops-land-crops')!;
    expect(land.itemGroups?.map((g) => g.groupTitle)).toEqual(['Land Setup', 'Crop Planning']);
  });

  it('groups Work & Harvest into Crop Ops then Other', () => {
    const domains = getNavDomains(term, false, false);
    const ops = domains.find((d) => d.domainKey === 'operations')!;
    const work = ops.sections.find((s) => s.sectionKey === 'ops-work-harvest')!;
    expect(work.itemGroups?.map((g) => g.groupTitle)).toEqual(['Crop Ops', 'Other']);
    expect(work.itemGroups?.[0].items.map((i) => i.key)).toEqual([
      'crop-ops-overview',
      'crop-ops-field-jobs',
      'crop-ops-field-work-logs',
      'harvests',
      'crop-ops-agreements',
      'crop-ops-work-types',
    ]);
    expect(work.itemGroups?.[1].items.map((i) => i.key)).toEqual(['pending-review']);
  });

  it('groups Operations > People & Workforce with Workforce then Directory', () => {
    const domains = getNavDomains(term, false, false);
    const ops = domains.find((d) => d.domainKey === 'operations')!;
    const people = ops.sections.find((s) => s.sectionKey === 'ops-people')!;
    expect(people.sectionTitle).toBe('People & Workforce');
    expect(people.itemGroups?.map((g) => g.groupTitle)).toEqual(['Workforce', 'Directory']);
    const keys = people.itemGroups?.flatMap((g) => g.items.map((i) => i.key));
    expect(keys).toEqual(['labour-overview', 'labour-workers', 'labour-work-logs', 'labour-payables', 'parties']);
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
    expect(domainHasActivePath('/app/land', ops)).toBe(true);
    expect(domainHasActivePath('/app/allocations', ops)).toBe(true);
    expect(domainHasActivePath('/app/crop-cycles', ops)).toBe(true);
    expect(domainHasActivePath('/app/projects', ops)).toBe(true);
    expect(domainHasActivePath('/app/projects/some-id', ops)).toBe(true);
    expect(domainHasActivePath('/app/production-units', ops)).toBe(false);
    expect(domainHasActivePath('/app/crop-ops', ops)).toBe(true);
    expect(domainHasActivePath('/app/crop-ops/activities', ops)).toBe(true);
    expect(domainHasActivePath('/app/crop-ops/activities/new', ops)).toBe(true);
    expect(domainHasActivePath('/app/crop-ops/activity-types', ops)).toBe(true);
    expect(domainHasActivePath('/app/harvests', ops)).toBe(true);
    expect(domainHasActivePath('/app/harvests/new', ops)).toBe(true);
    expect(domainHasActivePath('/app/harvests/abc-123', ops)).toBe(true);
    expect(domainHasActivePath('/app/labour', ops)).toBe(true);
    expect(domainHasActivePath('/app/labour/workers', ops)).toBe(true);
    expect(domainHasActivePath('/app/labour/work-logs', ops)).toBe(true);
    expect(domainHasActivePath('/app/labour/work-logs/new', ops)).toBe(true);
    expect(domainHasActivePath('/app/labour/payables', ops)).toBe(true);
    expect(domainHasActivePath('/app/parties/xyz', ops)).toBe(true);
    expect(domainHasActivePath('/app/dashboard', ops)).toBe(false);
  });

  it('isNavItemActive for submenu parent follows children', () => {
    const domains = getNavDomains(term, false, false);
    const ops = domains.find((d) => d.domainKey === 'operations')!;
    const rateCards = ops.sections.find((s) => s.sectionKey === 'ops-machinery')!.items.find((i) => i.key === 'machinery-rate-cards')!;
    expect(isNavItemActive('/app/machinery/rate-cards', rateCards)).toBe(true);
    expect(isNavItemActive('/app/sales', rateCards)).toBe(false);
  });
});

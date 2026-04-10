import { useState, useEffect, useMemo } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { term } from '../config/terminology';
import {
  getNavDomains,
  pruneDomainsForRole,
  filterDomainsByPermission,
  isSubmenuParent,
  getSectionNavItems,
  type NavItem,
} from '../config/nav';
import { domainHasActivePath, isNavItemActive, isPathUnderRoute } from '../config/navMatch';
import { getModuleLabel } from '../config/moduleKeys';
import { CAPABILITIES, can as canPermission } from '../config/permissions';
import { useRole } from '../hooks/useRole';
import { useModules } from '../contexts/ModulesContext';
import { useOrchardLivestockAddonsEnabled } from '../hooks/useModules';

const VITE_DEBUG_NAV = import.meta.env.VITE_DEBUG_NAV === 'true' || import.meta.env.VITE_DEBUG_NAV === '1';
const VITE_DEBUG_MODULES = import.meta.env.VITE_DEBUG_MODULES === 'true' || import.meta.env.VITE_DEBUG_MODULES === '1';

function getFirstMissingModuleKey(requiredModules: string[] | undefined, isModuleEnabled: (key: string) => boolean): string | undefined {
  if (!requiredModules?.length) return undefined;
  return requiredModules.find((k) => !isModuleEnabled(k));
}

/** Avoid setState when Set contents are unchanged (prevents redundant renders / effect loops). */
function stringSetsEqual(a: Set<string>, b: Set<string>): boolean {
  if (a.size !== b.size) {
    return false;
  }
  for (const x of a) {
    if (!b.has(x)) {
      return false;
    }
  }
  return true;
}

export interface AppSidebarProps {
  onItemClick?: () => void;
}

export function AppSidebar({ onItemClick }: AppSidebarProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const { can, userRole } = useRole();
  const { isModuleEnabled, loading: modulesLoading } = useModules();
  const { showOrchards, showLivestock } = useOrchardLivestockAddonsEnabled();

  const navDomains = useMemo(() => getNavDomains(term, showOrchards, showLivestock), [showOrchards, showLivestock]);
  const rolePrunedDomains = useMemo(() => pruneDomainsForRole(navDomains, userRole), [navDomains, userRole]);
  // Use userRole (stable) + canPermission — not `can` from useRole, which is a new function every render and would break useMemo.
  const visibleDomains = useMemo(
    () => filterDomainsByPermission(rolePrunedDomains, (p) => canPermission(userRole ?? undefined, p)),
    [rolePrunedDomains, userRole],
  );

  const canManageModules = can(CAPABILITIES.TENANT_MODULES_MANAGE);

  const modulesEnabledFor = (item: NavItem): boolean => {
    if (!item.requiredModules?.length) return true;
    if (modulesLoading) return false;
    return item.requiredModules.every(isModuleEnabled);
  };

  const handleDisabledClick = (item: NavItem) => {
    const firstMissing = getFirstMissingModuleKey(item.requiredModules, isModuleEnabled);
    const moduleLabel = firstMissing ? getModuleLabel(firstMissing) : 'module';
    if (canManageModules) {
      onItemClick?.();
      navigate('/app/admin/modules');
      toast(`Enable ${moduleLabel} to use ${item.label}`);
    } else {
      toast(`Ask a tenant admin to enable ${moduleLabel}`);
    }
  };

  if (VITE_DEBUG_NAV) {
    visibleDomains.forEach((domain) => {
      domain.sections.forEach((section) => {
        getSectionNavItems(section).forEach((item) => {
          if (isSubmenuParent(item)) {
            item.children!.forEach((c) => {
              console.log('[VITE_DEBUG_NAV]', {
                key: c.key,
                requiredPermission: c.requiredPermission,
                canResult: can(c.requiredPermission),
                requiredModules: c.requiredModules,
                modulesEnabledResult: modulesEnabledFor(c),
              });
            });
          } else {
            console.log('[VITE_DEBUG_NAV]', {
              key: item.key,
              requiredPermission: item.requiredPermission,
              canResult: can(item.requiredPermission),
              requiredModules: item.requiredModules,
              modulesEnabledResult: modulesEnabledFor(item),
            });
          }
        });
      });
    });
  }

  if (VITE_DEBUG_MODULES) {
    const tenantId = typeof window !== 'undefined' ? localStorage.getItem('farm_erp_tenant_id') ?? '' : '';
    const itemsWithModules: { key: string; requiredModules?: string[]; isEnabled: boolean }[] = [];
    visibleDomains.forEach((domain) => {
      domain.sections.forEach((section) => {
        getSectionNavItems(section).forEach((item) => {
          if (isSubmenuParent(item)) {
            item.children!.forEach((c) => {
              itemsWithModules.push({
                key: c.key,
                requiredModules: c.requiredModules,
                isEnabled: modulesEnabledFor(c),
              });
            });
          } else {
            itemsWithModules.push({
              key: item.key,
              requiredModules: item.requiredModules,
              isEnabled: modulesEnabledFor(item),
            });
          }
        });
      });
    });
    console.log('[VITE_DEBUG_MODULES] sidebar', { tenantId, navItems: itemsWithModules });
  }

  const SIDEBAR_EXPANDED_KEY = 'terrava.sidebar.expanded';
  const tenantId = typeof window !== 'undefined' ? localStorage.getItem('farm_erp_tenant_id') ?? '' : '';
  const getSubmenuStorageKey = () => `${SIDEBAR_EXPANDED_KEY}.${tenantId || '_default'}`;

  const loadSubmenuExpanded = (): Record<string, boolean> => {
    try {
      const raw = localStorage.getItem(getSubmenuStorageKey());
      if (raw) {
        const parsed = JSON.parse(raw) as Record<string, boolean>;
        if (parsed && typeof parsed === 'object') return parsed;
      }
    } catch {
      /* noop */
    }
    return {};
  };

  const saveSubmenuExpanded = (map: Record<string, boolean>) => {
    try {
      localStorage.setItem(getSubmenuStorageKey(), JSON.stringify(map));
    } catch {
      /* noop */
    }
  };

  const isOnMachineryRoute = location.pathname.startsWith('/app/machinery');
  const [expandedSubmenus, setExpandedSubmenus] = useState<Record<string, boolean>>(() => {
    const saved = loadSubmenuExpanded();
    return { machinery: saved.machinery ?? isOnMachineryRoute, ...saved };
  });

  useEffect(() => {
    const saved = loadSubmenuExpanded();
    setExpandedSubmenus((prev) => ({ ...saved, machinery: saved.machinery ?? prev.machinery ?? isOnMachineryRoute }));
  }, [tenantId]);

  useEffect(() => {
    if (location.pathname.startsWith('/app/machinery')) {
      setExpandedSubmenus((prev) => (prev.machinery ? prev : { ...prev, machinery: true }));
    }
  }, [location.pathname]);

  const toggleSubmenu = (submenuKey: string) => {
    setExpandedSubmenus((prev) => {
      const next = { ...prev, [submenuKey]: !prev[submenuKey] };
      saveSubmenuExpanded(next);
      return next;
    });
  };

  const [expandedDomains, setExpandedDomains] = useState<Set<string>>(() => {
    const initial = new Set<string>(['farm']);
    return initial;
  });

  useEffect(() => {
    setExpandedDomains((prev) => {
      const next = new Set(prev);
      visibleDomains.forEach((d) => {
        if (domainHasActivePath(location.pathname, d)) {
          next.add(d.domainKey);
        }
      });
      if (stringSetsEqual(prev, next)) {
        return prev;
      }
      return next;
    });
  }, [location.pathname, visibleDomains]);

  const toggleDomain = (domainKey: string) => {
    setExpandedDomains((prev) => {
      const next = new Set(prev);
      if (next.has(domainKey)) next.delete(domainKey);
      else next.add(domainKey);
      return next;
    });
  };

  const renderLeafItem = (item: NavItem) => {
    const disabled = !modulesEnabledFor(item);
    const navTestId = `nav-${item.to.replace('/app/', '').replace(/\//g, '-')}`;
    const isActive = isPathUnderRoute(location.pathname, item.to);
    const layoutClass = item.sidebarHint ? 'flex flex-col items-stretch gap-0.5' : 'flex items-center';
    const baseClass = `group ${layoutClass} px-2 py-2 text-sm font-medium rounded-md`;
    const activeClass = isActive ? 'bg-[#E6ECEA] text-[#1F6F5C]' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900';
    const disabledClass = disabled ? 'text-gray-400 cursor-not-allowed hover:bg-gray-50' : '';

    const labelBlock =
      item.sidebarHint != null && item.sidebarHint !== '' ? (
        <>
          <span>{item.label}</span>
          <span className="text-[11px] font-normal leading-snug text-gray-500">{item.sidebarHint}</span>
        </>
      ) : (
        item.label
      );

    if (disabled) {
      return (
        <span
          key={item.key}
          role="button"
          tabIndex={0}
          title="Module not enabled"
          data-testid={navTestId}
          className={`${baseClass} ${disabledClass} cursor-not-allowed`}
          onClick={(e) => {
            e.preventDefault();
            handleDisabledClick(item);
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              handleDisabledClick(item);
            }
          }}
        >
          {labelBlock}
        </span>
      );
    }

    return (
      <Link
        key={item.key}
        to={item.to}
        data-testid={navTestId}
        onClick={onItemClick}
        className={`${baseClass} ${activeClass}`}
      >
        {labelBlock}
      </Link>
    );
  };

  const renderItem = (item: NavItem) => {
    if (isSubmenuParent(item)) {
      const subExpanded = expandedSubmenus[item.submenuKey] ?? false;
      const hasChildActive = isNavItemActive(location.pathname, item);
      return (
        <div key={item.submenuKey}>
          <button
            type="button"
            onClick={() => toggleSubmenu(item.submenuKey)}
            aria-expanded={subExpanded}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleSubmenu(item.submenuKey);
              }
            }}
            className={`${
              hasChildActive ? 'bg-[#E6ECEA] text-[#1F6F5C]' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
            } w-full group flex items-center justify-between px-2 py-2 text-sm font-medium rounded-md`}
          >
            <span>{item.label}</span>
            <svg
              className={`h-4 w-4 transition-transform ${subExpanded ? 'transform rotate-90' : ''}`}
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
            </svg>
          </button>
          {subExpanded && (
            <div className="ml-4 mt-1 space-y-1 pl-2 border-l border-gray-200">
              {item.children!.map((child) => renderLeafItem(child))}
            </div>
          )}
        </div>
      );
    }
    return renderLeafItem(item);
  };

  return (
    <nav className="mt-5 flex-1 px-2 space-y-1" aria-label="Main">
      {visibleDomains.map((domain) => {
        const isExpanded = expandedDomains.has(domain.domainKey);
        const hasActiveInDomain = domainHasActivePath(location.pathname, domain);

        return (
          <div key={domain.domainKey}>
            <button
              type="button"
              onClick={() => toggleDomain(domain.domainKey)}
              aria-expanded={isExpanded}
              className={`${
                hasActiveInDomain ? 'bg-[#E6ECEA] text-[#1F6F5C]' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
              } w-full group flex items-center justify-between px-2 py-2 text-sm font-semibold rounded-md`}
            >
              <span>{domain.name}</span>
              <svg
                className={`h-4 w-4 transition-transform ${isExpanded ? 'transform rotate-90' : ''}`}
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
              </svg>
            </button>
            {isExpanded && (
              <div className="ml-4 mt-1 space-y-3">
                {domain.sections.map((section, sIdx) => (
                  <div key={section.sectionKey}>
                    {section.sectionTitle ? (
                      <div
                        className={`text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1.5 ${sIdx > 0 ? 'mt-1' : ''}`}
                      >
                        {section.sectionTitle}
                      </div>
                    ) : null}
                    {section.itemGroups?.length ? (
                      <div className="space-y-3">
                        {section.itemGroups.map((g) => (
                          <div key={g.groupTitle}>
                            <div className="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mb-1">
                              {g.groupTitle}
                            </div>
                            <div className="space-y-1">{g.items.map((item) => renderItem(item))}</div>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <div className="space-y-1">{section.items.map((item) => renderItem(item))}</div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        );
      })}
    </nav>
  );
}

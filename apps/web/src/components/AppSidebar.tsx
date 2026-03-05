import { useState, useEffect } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { term } from '../config/terminology';
import { getNavGroups, type NavGroup, type NavItem } from '../config/nav';
import { getModuleLabel } from '../config/moduleKeys';
import { CAPABILITIES } from '../config/permissions';
import { useRole } from '../hooks/useRole';
import { useModules } from '../contexts/ModulesContext';
import { useTenantAddonModulesQuery } from '../hooks/useModules';

const VITE_DEBUG_NAV = import.meta.env.VITE_DEBUG_NAV === 'true' || import.meta.env.VITE_DEBUG_NAV === '1';
const VITE_DEBUG_MODULES = import.meta.env.VITE_DEBUG_MODULES === 'true' || import.meta.env.VITE_DEBUG_MODULES === '1';

function isSubmenuParent(item: NavItem): item is NavItem & { children: NavItem[]; submenuKey: string } {
  return Array.isArray((item as NavItem & { children?: NavItem[] }).children) && !!(item as NavItem & { submenuKey?: string }).submenuKey;
}

function getFirstMissingModuleKey(requiredModules: string[] | undefined, isModuleEnabled: (key: string) => boolean): string | undefined {
  if (!requiredModules?.length) return undefined;
  return requiredModules.find((k) => !isModuleEnabled(k));
}


export interface AppSidebarProps {
  onItemClick?: () => void;
}

export function AppSidebar({ onItemClick }: AppSidebarProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const { can } = useRole();
  const { isModuleEnabled, loading: modulesLoading } = useModules();
  const { data: addonData, status: addonStatus } = useTenantAddonModulesQuery();
  const envOrchards = import.meta.env.VITE_ENABLE_ORCHARDS === 'true';
  const envLivestock = import.meta.env.VITE_ENABLE_LIVESTOCK === 'true';
  const showOrchards = envOrchards || (addonStatus === 'success' && addonData?.modules?.orchards === true);
  const showLivestock = envLivestock || (addonStatus === 'success' && addonData?.modules?.livestock === true);

  const navGroups = getNavGroups(term, showOrchards, showLivestock);

  const canManageModules = can(CAPABILITIES.TENANT_MODULES_MANAGE);

  // Permission-only visibility: show item iff can(requiredPermission). Modules never hide.
  const visibleGroups: NavGroup[] = navGroups.map((group) => ({
    ...group,
    items: group.items.filter((item) => {
      if (isSubmenuParent(item)) {
        const visibleChildren = item.children!.filter((c) => can(c.requiredPermission));
        if (visibleChildren.length === 0) return false;
        return true;
      }
      return can(item.requiredPermission);
    }).map((item) => {
      if (isSubmenuParent(item)) {
        return { ...item, children: item.children!.filter((c) => can(c.requiredPermission)) };
      }
      return item;
    }).filter((item) => {
      if (isSubmenuParent(item)) return (item as NavItem & { children: NavItem[] }).children.length > 0;
      return true;
    }),
  })).filter((g) => g.items.length > 0);

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
    visibleGroups.forEach((group) => {
      group.items.forEach((item) => {
        if (isSubmenuParent(item)) {
          item.children!.forEach((c) => {
            const canResult = can(c.requiredPermission);
            const modulesEnabledResult = modulesEnabledFor(c);
            console.log('[VITE_DEBUG_NAV]', {
              key: c.key,
              requiredPermission: c.requiredPermission,
              canResult,
              requiredModules: c.requiredModules,
              modulesEnabledResult,
            });
          });
        } else {
          const canResult = can(item.requiredPermission);
          const modulesEnabledResult = modulesEnabledFor(item);
          console.log('[VITE_DEBUG_NAV]', {
            key: item.key,
            requiredPermission: item.requiredPermission,
            canResult,
            requiredModules: item.requiredModules,
            modulesEnabledResult,
          });
        }
      });
    });
  }

  if (VITE_DEBUG_MODULES) {
    const tenantId = typeof window !== 'undefined' ? localStorage.getItem('farm_erp_tenant_id') ?? '' : '';
    const itemsWithModules: { key: string; requiredModules?: string[]; isEnabled: boolean }[] = [];
    visibleGroups.forEach((group) => {
      group.items.forEach((item) => {
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
    if (location.pathname.startsWith('/app/machinery') && !expandedSubmenus.machinery) {
      setExpandedSubmenus((prev) => ({ ...prev, machinery: true }));
    }
  }, [location.pathname]);

  const toggleSubmenu = (submenuKey: string) => {
    setExpandedSubmenus((prev) => {
      const next = { ...prev, [submenuKey]: !prev[submenuKey] };
      saveSubmenuExpanded(next);
      return next;
    });
  };

  const [expandedGroups, setExpandedGroups] = useState<Set<string>>(() => {
    const active = new Set<string>();
    active.add(term('navFarm'));
    visibleGroups.forEach((group) => {
      const hasActive = group.items.some((item) => {
        if (isSubmenuParent(item)) {
          return item.children!.some(
            (c) => location.pathname === c.to || location.pathname.startsWith(c.to + '/')
          );
        }
        return location.pathname === item.to || location.pathname.startsWith(item.to + '/');
      });
      if (hasActive) active.add(group.name);
    });
    return active;
  });

  useEffect(() => {
    visibleGroups.forEach((group) => {
      const hasActive = group.items.some((item) => {
        if (isSubmenuParent(item)) {
          return item.children!.some(
            (c) => location.pathname === c.to || location.pathname.startsWith(c.to + '/')
          );
        }
        return location.pathname === item.to || location.pathname.startsWith(item.to + '/');
      });
      if (hasActive) {
        setExpandedGroups((prev) => (prev.has(group.name) ? prev : new Set(prev).add(group.name)));
      }
    });
  }, [location.pathname]);

  const toggleGroup = (name: string) => {
    setExpandedGroups((prev) => {
      const next = new Set(prev);
      if (next.has(name)) next.delete(name);
      else next.add(name);
      return next;
    });
  };

  const renderItem = (item: NavItem) => {
    const disabled = !modulesEnabledFor(item);
    const navTestId = `nav-${item.to.replace('/app/', '').replace(/\//g, '-')}`;
    const isActive = location.pathname === item.to || location.pathname.startsWith(item.to + '/');
    const baseClass = `group flex items-center px-2 py-2 text-sm font-medium rounded-md`;
    const activeClass = isActive ? 'bg-[#E6ECEA] text-[#1F6F5C]' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900';
    const disabledClass = disabled ? 'text-gray-400 cursor-not-allowed hover:bg-gray-50' : '';

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
          {item.label}
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
        {item.label}
      </Link>
    );
  };

  return (
    <nav className="mt-5 flex-1 px-2 space-y-1" aria-label="Main">
      {visibleGroups.map((group) => {
        const isExpanded = expandedGroups.has(group.name);
        const hasActiveItem = group.items.some((item) => {
          if (isSubmenuParent(item)) {
            return item.children!.some(
              (c) => location.pathname === c.to || location.pathname.startsWith(c.to + '/')
            );
          }
          return location.pathname === item.to || location.pathname.startsWith(item.to + '/');
        });

        if (group.items.length === 1 && !isSubmenuParent(group.items[0])) {
          const item = group.items[0];
          return <div key={group.name}>{renderItem(item)}</div>;
        }

        return (
          <div key={group.name}>
            <button
              type="button"
              onClick={() => toggleGroup(group.name)}
              className={`${
                hasActiveItem ? 'bg-[#E6ECEA] text-[#1F6F5C]' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
              } w-full group flex items-center justify-between px-2 py-2 text-sm font-medium rounded-md`}
            >
              <span>{group.name}</span>
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
              <div className="ml-4 mt-1 space-y-1">
                {group.items.map((item) => {
                  if (isSubmenuParent(item)) {
                    const subExpanded = expandedSubmenus[item.submenuKey] ?? false;
                    const hasChildActive = item.children!.some(
                      (c) => location.pathname === c.to || location.pathname.startsWith(c.to + '/')
                    );
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
                            {item.children!.map((child) => renderItem(child))}
                          </div>
                        )}
                      </div>
                    );
                  }
                  return renderItem(item);
                })}
              </div>
            )}
          </div>
        );
      })}
    </nav>
  );
}

import { useState, useMemo } from 'react';
import { useModules } from '../contexts/ModulesContext';
import { useUpdateTenantModulesMutation } from '../hooks/useModules';
import { LoadingSpinner } from '../components/LoadingSpinner';
import toast from 'react-hot-toast';
import type { TenantModuleItem, TenantModulesUpdateResponse } from '@farm-erp/shared';

function tierBadge(tier: TenantModuleItem['tier']) {
  if (tier === 'CORE') return { label: 'Core', className: 'bg-amber-100 text-amber-800' };
  if (tier === 'CORE_ADJUNCT') return { label: 'Core-adjunct', className: 'bg-slate-100 text-slate-700' };
  if (tier === 'OPTIONAL') return { label: 'Optional', className: 'bg-gray-100 text-gray-600' };
  return null;
}

function requiredByLabel(requiredBy: string[], keyToName: Record<string, string> | undefined, modules: TenantModuleItem[]) {
  const names = requiredBy.map((k) => keyToName?.[k] ?? modules.find((m) => m.key === k)?.name ?? k);
  return names.length ? `Required by: ${names.join(', ')}` : null;
}

export default function ModuleTogglePage() {
  const { modules, loading, error } = useModules();
  const updateMutation = useUpdateTenantModulesMutation();
  const [localOverrides, setLocalOverrides] = useState<Record<string, boolean>>({});

  const sorted = useMemo(
    () =>
      [...modules].sort(
        (a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name)
      ),
    [modules]
  );

  const getEnabled = (key: string, fallback: boolean) =>
    localOverrides[key] ?? fallback;

  const isDisabledByDependency = (m: TenantModuleItem) =>
    (m.required_by?.length ?? 0) > 0;

  const canToggle = (m: TenantModuleItem) =>
    !m.is_core && !isDisabledByDependency(m);

  const handleToggle = (key: string, m: TenantModuleItem, current: boolean) => {
    if (!canToggle(m)) return;
    setLocalOverrides((o) => ({ ...o, [key]: !current }));
  };

  const handleSave = async () => {
    const payload = {
      modules: sorted.map((m) => ({
        key: m.key,
        enabled: getEnabled(m.key, m.enabled),
      })),
    };
    try {
      const data = await updateMutation.mutateAsync(
        payload
      ) as TenantModulesUpdateResponse | undefined;
      setLocalOverrides({});
      toast.success('Module settings saved');
      const autoEnabled = data?.auto_enabled;
      if (autoEnabled && typeof autoEnabled === 'object') {
        const keyToName =
          data?.key_to_name ??
          Object.fromEntries(modules.map((x) => [x.key, x.name]));
        for (const [enabledKey, alsoKeys] of Object.entries(autoEnabled)) {
          if (alsoKeys?.length) {
            const names = alsoKeys.map(
              (k) => keyToName[k] ?? modules.find((m) => m.key === k)?.name ?? k
            );
            const mainName = keyToName[enabledKey] ?? modules.find((m) => m.key === enabledKey)?.name ?? enabledKey;
            toast.success(`Enabled ${mainName} (also enabled: ${names.join(', ')})`);
          }
        }
      }
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string; error?: string }; status?: number } };
      const message =
        err?.response?.data?.message ??
        (err as Error)?.message ??
        'Failed to save';
      toast.error(message);
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">Error loading modules: {error.message}</p>
      </div>
    );
  }

  const keyToName = Object.fromEntries(modules.map((m) => [m.key, m.name]));

  return (
    <div data-testid="module-toggles-page">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Module Toggles</h1>
        <p className="text-sm text-gray-500 mt-1">
          Enable or disable modules for this tenant. Core and required-by-others modules cannot be turned off.
        </p>
      </div>

      <div className="bg-white rounded-lg shadow overflow-hidden">
        <ul className="divide-y divide-gray-200">
          {sorted.map((m) => {
            const enabled = getEnabled(m.key, m.enabled);
            const disabled = !canToggle(m);
            const requiredByText = requiredByLabel(
              m.required_by ?? [],
              keyToName,
              modules
            );
            const badge = tierBadge(m.tier ?? 'OPTIONAL');
            return (
              <li
                key={m.key}
                className="px-4 py-3 flex items-center justify-between gap-4"
                data-testid={`module-row-${m.key}`}
              >
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-medium text-gray-900">{m.name}</span>
                    {badge && (
                      <span
                        className={`px-2 py-0.5 text-xs rounded ${badge.className}`}
                        data-testid={
                          m.tier === 'CORE'
                            ? 'module-badge-core'
                            : `module-badge-${m.tier?.toLowerCase() ?? 'optional'}`
                        }
                      >
                        {badge.label}
                      </span>
                    )}
                  </div>
                  {m.description && (
                    <p className="text-sm text-gray-500 mt-0.5">{m.description}</p>
                  )}
                  {disabled && requiredByText && (
                    <p
                      className="text-xs text-amber-700 mt-1"
                      data-testid={`module-required-by-${m.key}`}
                    >
                      {requiredByText}
                    </p>
                  )}
                </div>
                <div className="flex-shrink-0">
                  <button
                    type="button"
                    role="switch"
                    aria-checked={enabled}
                    disabled={disabled}
                    data-testid={`module-toggle-${m.key}`}
                    onClick={() => handleToggle(m.key, m, enabled)}
                    className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ${
                      enabled ? 'bg-[#1F6F5C]' : 'bg-gray-200'
                    } ${disabled ? 'cursor-not-allowed' : ''}`}
                  >
                    <span
                      className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition ${
                        enabled ? 'translate-x-5' : 'translate-x-1'
                      }`}
                    />
                  </button>
                </div>
              </li>
            );
          })}
        </ul>
        <div className="px-4 py-3 bg-gray-50 border-t border-gray-200 flex justify-end">
          <button
            type="button"
            onClick={handleSave}
            disabled={updateMutation.isPending}
            data-testid="module-toggles-save"
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] disabled:bg-gray-400 disabled:cursor-not-allowed"
          >
            {updateMutation.isPending ? 'Saving...' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  );
}

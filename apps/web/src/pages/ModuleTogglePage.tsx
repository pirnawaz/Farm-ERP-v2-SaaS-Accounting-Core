import { useState, useMemo } from 'react';
import { useModules } from '../contexts/ModulesContext';
import { useUpdateTenantModulesMutation } from '../hooks/useModules';
import { LoadingSpinner } from '../components/LoadingSpinner';
import toast from 'react-hot-toast';

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

  const handleToggle = (key: string, isCore: boolean, current: boolean) => {
    if (isCore) return;
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
      await updateMutation.mutateAsync(payload);
      setLocalOverrides({});
      toast.success('Module settings saved');
    } catch (e: unknown) {
      toast.error((e as Error)?.message || 'Failed to save');
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

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Module Toggles</h1>
        <p className="text-sm text-gray-500 mt-1">
          Enable or disable modules for this tenant. Core modules cannot be turned off.
        </p>
      </div>

      <div className="bg-white rounded-lg shadow overflow-hidden">
        <ul className="divide-y divide-gray-200">
          {sorted.map((m) => {
            const enabled = getEnabled(m.key, m.enabled);
            const canToggle = !m.is_core;
            return (
              <li
                key={m.key}
                className="px-4 py-3 flex items-center justify-between gap-4"
              >
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className="font-medium text-gray-900">{m.name}</span>
                    {m.is_core && (
                      <span className="px-2 py-0.5 text-xs rounded bg-amber-100 text-amber-800">
                        Core
                      </span>
                    )}
                  </div>
                  {m.description && (
                    <p className="text-sm text-gray-500 mt-0.5">{m.description}</p>
                  )}
                </div>
                <div className="flex-shrink-0">
                  <button
                    type="button"
                    role="switch"
                    aria-checked={enabled}
                    disabled={!canToggle}
                    onClick={() => handleToggle(m.key, m.is_core, enabled)}
                    className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ${
                      enabled ? 'bg-[#1F6F5C]' : 'bg-gray-200'
                    } ${canToggle ? '' : 'cursor-not-allowed'}`}
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
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] disabled:bg-gray-400 disabled:cursor-not-allowed"
          >
            {updateMutation.isPending ? 'Saving...' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  );
}

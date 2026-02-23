import { useAuth } from '../../hooks/useAuth';
import type { UserRole } from '../../types';
import type { DashboardViewType } from './dashboardTypes';

const STORAGE_KEY_PREFIX = 'terrava.dashboard.view.';

export type DashboardViewTypeOption = { value: DashboardViewType; label: string };

const VIEW_OPTIONS: DashboardViewTypeOption[] = [
  { value: 'owner', label: 'Owner' },
  { value: 'manager', label: 'Manager' },
  { value: 'accountant', label: 'Accountant' },
];

function roleToDefaultView(role: UserRole | null): DashboardViewType {
  if (!role) return 'manager';
  switch (role) {
    case 'tenant_admin':
      return 'owner';
    case 'accountant':
    case 'platform_admin':
      return 'accountant';
    case 'operator':
    default:
      return 'manager';
  }
}

export function getStoredView(tenantId: string | null): DashboardViewType | null {
  if (!tenantId) return null;
  try {
    const raw = localStorage.getItem(STORAGE_KEY_PREFIX + tenantId);
    if (raw && VIEW_OPTIONS.some((o) => o.value === raw)) return raw as DashboardViewType;
  } catch {
    // ignore
  }
  return null;
}

export function setStoredView(tenantId: string | null, view: DashboardViewType): void {
  if (!tenantId) return;
  try {
    localStorage.setItem(STORAGE_KEY_PREFIX + tenantId, view);
  } catch {
    // ignore
  }
}

interface DashboardViewSelectorProps {
  value: DashboardViewType;
  onChange: (view: DashboardViewType) => void;
}

export function DashboardViewSelector({ value, onChange }: DashboardViewSelectorProps) {
  const { tenantId } = useAuth();

  const handleChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const v = e.target.value as DashboardViewType;
    onChange(v);
    setStoredView(tenantId ?? null, v);
  };

  return (
    <div className="flex items-center gap-2">
      <label htmlFor="dashboard-view" className="text-sm font-medium text-gray-600">
        View as
      </label>
      <select
        id="dashboard-view"
        value={value}
        onChange={handleChange}
        className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 focus:border-[#1F6F5C] focus:outline-none focus:ring-1 focus:ring-[#1F6F5C]"
      >
        {VIEW_OPTIONS.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>
    </div>
  );
}

export function getDefaultViewForRole(role: UserRole | null): DashboardViewType {
  return roleToDefaultView(role ?? null);
}

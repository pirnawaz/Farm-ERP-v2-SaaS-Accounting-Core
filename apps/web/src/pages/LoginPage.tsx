import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { useTenant } from '../hooks/useTenant';
import { devApi } from '../api/dev';
import { platformApi } from '../api/platform';
import { tenantLogin } from '../api/auth';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { BrandLogo } from '../components/BrandLogo';
import toast from 'react-hot-toast';
import type { UserRole } from '../types';
import type { Tenant } from '@farm-erp/shared';

const TENANT_ID_KEY = 'farm_erp_tenant_id';
// Dev-only: picker must be impossible in production (require DEV and explicit env flag)
const DEV_TENANT_PICKER = import.meta.env.DEV === true && import.meta.env.VITE_DEV_TENANT_PICKER === 'true';

/** Normalize user input to slug: trim, lower, spaces/underscores → hyphens, strip invalid, collapse/trim hyphens. */
function normalizeSlugInput(input: string): string {
  return input
    .trim()
    .toLowerCase()
    .replace(/[\s_]+/g, '-')
    .replace(/[^a-z0-9-]/g, '')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
}

type LoginMode = 'tenant' | 'platform';

export default function LoginPage() {
  const navigate = useNavigate();
  const { setDevIdentity } = useAuth();
  const { setTenantId } = useTenant();
  const [loginMode, setLoginMode] = useState<LoginMode>('tenant');
  const [platformEmail, setPlatformEmail] = useState('');
  const [platformPassword, setPlatformPassword] = useState('');
  const [platformSubmitting, setPlatformSubmitting] = useState(false);
  const [tenantIdInput, setTenantIdInput] = useState('');
  const [useTenantId, setUseTenantId] = useState(false);
  const [tenantEmail, setTenantEmail] = useState('');
  const [tenantPassword, setTenantPassword] = useState('');
  const [tenantSubmitting, setTenantSubmitting] = useState(false);
  const [selectedRole, setSelectedRole] = useState<UserRole>('operator');
  const [selectedTenantId, setSelectedTenantId] = useState<string>('');
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [bootstrappingId, setBootstrappingId] = useState<string | null>(null);
  const [newFarmName, setNewFarmName] = useState('');

  useEffect(() => {
    if (DEV_TENANT_PICKER) {
      loadTenants();
    } else {
      setLoading(false);
    }
  }, []);

  const loadTenants = async () => {
    try {
      setLoading(true);
      const response = await devApi.listTenants();
      setTenants(response.tenants);
      const stored = localStorage.getItem(TENANT_ID_KEY);
      if (stored && response.tenants.some(t => t.id === stored)) {
        setSelectedTenantId(stored);
      } else if (response.tenants.length > 0) {
        setSelectedTenantId(response.tenants[0].id);
      }
    } catch (error: any) {
      if (error.message?.includes('403') || error.message?.includes('disabled')) {
        toast.error('Dev bootstrap disabled. Enable APP_DEBUG or use production auth.');
      } else {
        toast.error(error.message || 'Failed to load tenants');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleSelectTenant = (tenantId: string) => {
    setSelectedTenantId(tenantId);
    setTenantId(tenantId);
  };

  const handleDeleteTenant = async (tenantId: string) => {
    if (!window.confirm('Remove this farm? This cannot be undone.')) return;
    try {
      setDeletingId(tenantId);
      await devApi.deleteTenant(tenantId);
      toast.success('Farm removed');
      if (selectedTenantId === tenantId) setSelectedTenantId('');
      await loadTenants();
    } catch (error: any) {
      const msg = error.message || 'Failed to remove farm';
      toast.error(msg.includes('linked data') ? 'Farm has data. Use DB reset (migrate:fresh) to remove it.' : msg);
    } finally {
      setDeletingId(null);
    }
  };

  const handleBootstrapAccounts = async (tenantId: string) => {
    try {
      setBootstrappingId(tenantId);
      const res = await devApi.bootstrapAccounts(tenantId);
      toast.success(res?.message ?? 'Accounts bootstrapped');
    } catch (error: any) {
      toast.error(error.message || 'Failed to bootstrap accounts');
    } finally {
      setBootstrappingId(null);
    }
  };

  const handleCreateTenant = async () => {
    if (!newFarmName.trim() || newFarmName.trim().length < 2) {
      toast.error('Farm name must be at least 2 characters');
      return;
    }

    try {
      setCreating(true);
      const response = await devApi.createTenant({ name: newFarmName.trim() });
      toast.success('Farm created successfully');
      setNewFarmName('');
      await loadTenants();
      // Auto-select the newly created tenant
      setSelectedTenantId(response.tenant.id);
      setTenantId(response.tenant.id);
    } catch (error: any) {
      if (error.message?.includes('403') || error.message?.includes('disabled')) {
        toast.error('Dev bootstrap disabled. Enable APP_DEBUG or use production auth.');
      } else {
        toast.error(error.message || 'Failed to create farm');
      }
    } finally {
      setCreating(false);
    }
  };

  const isPlatformAdmin = selectedRole === 'platform_admin';

  const handlePlatformLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!platformEmail.trim() || !platformPassword) {
      toast.error('Email and password are required');
      return;
    }
    setPlatformSubmitting(true);
    try {
      const res = await platformApi.login(platformEmail.trim(), platformPassword);
      setDevIdentity({
        role: 'platform_admin',
        userId: res.user.id,
        tenantId: null,
      });
      setTenantId('');
      toast.success('Signed in as platform admin');
      navigate('/app/platform/tenants');
    } catch (err: unknown) {
      toast.error((err as Error)?.message ?? 'Platform login failed');
    } finally {
      setPlatformSubmitting(false);
    }
  };

  const handleTenantLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    const email = tenantEmail.trim();
    if (!email || !tenantPassword) {
      toast.error('Email and password are required');
      return;
    }
    let slugOrId: string;
    if (useTenantId) {
      slugOrId = tenantIdInput.trim();
      if (!slugOrId) {
        toast.error('Tenant ID is required');
        return;
      }
    } else {
      slugOrId = normalizeSlugInput(tenantIdInput);
      if (!slugOrId) {
        toast.error('Enter your farm name or URL.');
        return;
      }
    }
    setTenantSubmitting(true);
    try {
      const res = await tenantLogin(slugOrId, email, tenantPassword, { useTenantId });
      setDevIdentity({
        role: res.user.role as UserRole,
        userId: res.user.id,
        tenantId: res.tenant?.id ?? null,
        mustChangePassword: res.user.must_change_password === true,
      });
      setTenantId(res.tenant?.id ?? '');
      toast.success('Signed in');
      navigate(res.user.must_change_password ? '/app/set-password' : '/app/dashboard');
    } catch (err: unknown) {
      toast.error((err as Error)?.message ?? 'Tenant login failed');
    } finally {
      setTenantSubmitting(false);
    }
  };

  const handleContinue = () => {
    if (!selectedRole) {
      toast.error('Please select a role');
      return;
    }
    if (isPlatformAdmin) {
      setTenantId('');
      setDevIdentity({ role: 'platform_admin', tenantId: null });
      navigate('/app/platform/tenants');
      return;
    }
    if (!selectedTenantId) {
      toast.error('Please select a farm');
      return;
    }

    setTenantId(selectedTenantId);
    setDevIdentity({ role: selectedRole, tenantId: selectedTenantId });
    navigate('/app/dashboard');
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-2xl w-full space-y-8">
        <div>
          <div className="flex justify-center mb-4">
            <BrandLogo size="lg" />
          </div>
          <h2 className="mt-6 text-center text-3xl font-extrabold text-gray-900">
            Welcome back to Terrava
          </h2>
          <p className="mt-2 text-center text-sm text-gray-600">
            {loginMode === 'platform' ? 'Platform Admin sign in' : DEV_TENANT_PICKER ? 'Select Farm (Tenant) - Development' : 'Tenant sign in'}
          </p>
          <div className="mt-2 flex justify-center gap-2">
            <button
              type="button"
              onClick={() => setLoginMode('platform')}
              className={`text-sm font-medium ${loginMode === 'platform' ? 'text-[#1F6F5C] underline' : 'text-gray-500 hover:text-gray-700'}`}
            >
              Platform Admin
            </button>
            <span className="text-gray-300">|</span>
            <button
              type="button"
              onClick={() => setLoginMode('tenant')}
              className={`text-sm font-medium ${loginMode === 'tenant' ? 'text-[#1F6F5C] underline' : 'text-gray-500 hover:text-gray-700'}`}
            >
              Tenant
            </button>
          </div>
        </div>

        <div className="mt-8 space-y-6 bg-white shadow rounded-lg p-6">
          {loginMode === 'platform' ? (
            <form onSubmit={handlePlatformLogin} className="space-y-4">
              <div>
                <label htmlFor="platform-email" className="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input
                  id="platform-email"
                  type="email"
                  value={platformEmail}
                  onChange={(e) => setPlatformEmail(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                  placeholder="admin@example.com"
                  autoComplete="email"
                />
              </div>
              <div>
                <label htmlFor="platform-password" className="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input
                  id="platform-password"
                  type="password"
                  value={platformPassword}
                  onChange={(e) => setPlatformPassword(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                  autoComplete="current-password"
                />
              </div>
              <button
                type="submit"
                disabled={platformSubmitting}
                className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-[#1F6F5C] hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="platform-login-submit"
              >
                {platformSubmitting ? 'Signing in...' : 'Sign in (Platform Admin)'}
              </button>
            </form>
          ) : DEV_TENANT_PICKER ? (
            <>
          {/* Dev picker: Existing Farms */}
          <div>
            <h3 className="text-lg font-medium text-gray-900 mb-4">Existing Farms</h3>
            {loading ? (
              <div className="flex justify-center py-8">
                <LoadingSpinner />
              </div>
            ) : tenants.length === 0 ? (
              <p className="text-sm text-gray-500 py-4">No farms found. Create one below.</p>
            ) : (
              <div className="space-y-2">
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-[#E6ECEA]">
                      <tr>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                          Name
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                          Status
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                          ID
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                          Action
                        </th>
                      </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                      {tenants.map((tenant) => (
                        <tr key={tenant.id} className={selectedTenantId === tenant.id ? 'bg-[#E6ECEA]' : ''}>
                          <td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                            {tenant.name}
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm">
                            <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${
                              tenant.status === 'active' 
                                ? 'bg-green-100 text-green-800' 
                                : 'bg-yellow-100 text-yellow-800'
                            }`}>
                              {tenant.status}
                            </span>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-xs text-gray-500 font-mono">
                            {tenant.id ? tenant.id.substring(0, 8) + '...' : 'N/A'}
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm space-x-2">
                            <button
                              onClick={() => handleSelectTenant(tenant.id)}
                              className={`px-3 py-1 rounded text-sm font-medium ${
                                selectedTenantId === tenant.id
                                  ? 'bg-[#1F6F5C] text-white'
                                  : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
                              }`}
                            >
                              {selectedTenantId === tenant.id ? 'Selected' : 'Select'}
                            </button>
                            <button
                              type="button"
                              onClick={() => handleBootstrapAccounts(tenant.id)}
                              disabled={bootstrappingId !== null}
                              title="Add missing system accounts (e.g. for posting supplies received)"
                              className="px-3 py-1 rounded text-sm font-medium text-amber-700 bg-amber-100 hover:bg-amber-200 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                              {bootstrappingId === tenant.id ? '…' : 'Bootstrap accounts'}
                            </button>
                            <button
                              type="button"
                              onClick={() => handleDeleteTenant(tenant.id)}
                              disabled={deletingId !== null}
                              className="px-3 py-1 rounded text-sm font-medium text-red-700 bg-red-100 hover:bg-red-200 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                              {deletingId === tenant.id ? '…' : 'Delete'}
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </div>

          {/* Section 2: Create New Farm */}
          <div className="border-t border-gray-200 pt-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">Create New Farm</h3>
            <div className="flex space-x-2">
              <input
                type="text"
                value={newFarmName}
                onChange={(e) => setNewFarmName(e.target.value)}
                placeholder="Enter farm name"
                className="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                onKeyPress={(e) => {
                  if (e.key === 'Enter' && !creating) {
                    handleCreateTenant();
                  }
                }}
              />
              <button
                onClick={handleCreateTenant}
                disabled={creating || !newFarmName.trim()}
                className="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {creating ? 'Creating...' : 'Create'}
              </button>
            </div>
          </div>

          {/* Section 3: Role Selection */}
          <div className="border-t border-gray-200 pt-6">
            <label htmlFor="role" className="block text-sm font-medium text-gray-700 mb-2">
              Select Role
            </label>
            <select
              id="role"
              data-testid="role"
              value={selectedRole}
              onChange={(e) => setSelectedRole(e.target.value as UserRole)}
              className="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            >
              <option value="tenant_admin">Tenant Admin</option>
              <option value="accountant">Accountant</option>
              <option value="operator">Operator</option>
              <option value="platform_admin">Platform Admin</option>
            </select>
          </div>

          {/* CTA Button */}
          <div className="border-t border-gray-200 pt-6">
            <button
              type="button"
              data-testid="login-submit"
              onClick={handleContinue}
              disabled={!selectedRole || (!isPlatformAdmin && !selectedTenantId)}
              className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-[#1F6F5C] hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Continue
            </button>
          </div>
            </>
          ) : (
            <form onSubmit={handleTenantLogin} className="space-y-4">
              <div>
                <label htmlFor="tenant-slug-or-id" className="block text-sm font-medium text-gray-700 mb-1">
                  {useTenantId ? 'Tenant ID' : 'Farm'}
                </label>
                <input
                  id="tenant-slug-or-id"
                  type="text"
                  value={tenantIdInput}
                  onChange={(e) => setTenantIdInput(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C] font-mono text-sm"
                  placeholder={useTenantId ? 'UUID e.g. 550e8400-e29b-41d4-a716-446655440000' : 'e.g. demo-farm or demo farm'}
                  autoComplete="off"
                />
                {!useTenantId && (
                  <p className="mt-1 text-xs text-gray-500">
                    If your farm URL is demo-farm, enter demo-farm (spaces are OK).
                  </p>
                )}
                <div className="mt-1 flex items-center gap-2">
                  <input
                    id="use-tenant-id"
                    type="checkbox"
                    checked={useTenantId}
                    onChange={(e) => setUseTenantId(e.target.checked)}
                    className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C]"
                  />
                  <label htmlFor="use-tenant-id" className="text-xs text-gray-500">Use Tenant ID (power users)</label>
                </div>
              </div>
              <div>
                <label htmlFor="tenant-email" className="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input
                  id="tenant-email"
                  type="email"
                  value={tenantEmail}
                  onChange={(e) => setTenantEmail(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                  placeholder="you@example.com"
                  autoComplete="email"
                />
              </div>
              <div>
                <label htmlFor="tenant-password" className="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input
                  id="tenant-password"
                  type="password"
                  value={tenantPassword}
                  onChange={(e) => setTenantPassword(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                  autoComplete="current-password"
                />
              </div>
              <button
                type="submit"
                disabled={tenantSubmitting}
                className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-[#1F6F5C] hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="tenant-login-submit"
              >
                {tenantSubmitting ? 'Signing in...' : 'Sign in (Tenant)'}
              </button>
            </form>
          )}
        </div>
      </div>
    </div>
  );
}

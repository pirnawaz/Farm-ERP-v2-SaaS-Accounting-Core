import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { useTenant } from '../hooks/useTenant';
import { devApi } from '../api/dev';
import { unifiedLogin, selectTenant } from '../api/auth';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { BrandLogo } from '../components/BrandLogo';
import toast from 'react-hot-toast';
import type { UserRole } from '../types';
import type { Tenant } from '@farm-erp/shared';

const TENANT_ID_KEY = 'farm_erp_tenant_id';
const LAST_TENANT_KEY = 'farm_erp_last_tenant_id';
const DEV_TENANT_PICKER =
  import.meta.env.DEV === true && import.meta.env.VITE_DEV_TENANT_PICKER === 'true';

type TenantOption = { id: string; slug: string | null; name: string; role: string };

export default function LoginPage() {
  const navigate = useNavigate();
  const { setIdentityFromUnifiedLogin, setDevIdentity } = useAuth();
  const { setTenantId } = useTenant();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [selectTenantList, setSelectTenantList] = useState<TenantOption[] | null>(null);
  const [selectedTenantId, setSelectedTenantId] = useState<string>('');
  const [selectSubmitting, setSelectSubmitting] = useState(false);

  // Dev-only: tenant picker state for creating/selecting farms without logging in
  const [devTenants, setDevTenants] = useState<Tenant[]>([]);
  const [loading, setLoading] = useState(!!DEV_TENANT_PICKER);
  const [creating, setCreating] = useState(false);
  const [newFarmName, setNewFarmName] = useState('');
  const [selectedRole, setSelectedRole] = useState<UserRole>('operator');
  const [selectedDevTenantId, setSelectedDevTenantId] = useState<string>('');
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [bootstrappingId, setBootstrappingId] = useState<string | null>(null);

  useEffect(() => {
    if (DEV_TENANT_PICKER) {
      loadDevTenants();
    } else {
      setLoading(false);
    }
  }, []);

  const loadDevTenants = async () => {
    try {
      setLoading(true);
      const response = await devApi.listTenants();
      setDevTenants(response.tenants);
      const stored = localStorage.getItem(TENANT_ID_KEY);
      if (stored && response.tenants.some((t) => t.id === stored)) {
        setSelectedDevTenantId(stored);
      } else if (response.tenants.length > 0) {
        setSelectedDevTenantId(response.tenants[0].id);
      }
    } catch (error: unknown) {
      const msg = (error as Error)?.message;
      if (msg?.includes('403') || msg?.includes('disabled')) {
        toast.error('Dev bootstrap disabled. Enable APP_DEBUG or use production auth.');
      } else {
        toast.error(msg || 'Failed to load tenants');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleUnifiedLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    const trimmedEmail = email.trim();
    if (!trimmedEmail || !password) {
      toast.error('Email and password are required');
      return;
    }
    setSubmitting(true);
    try {
      const res = await unifiedLogin(trimmedEmail, password);
      if (res.mode === 'platform') {
        setIdentityFromUnifiedLogin({
          mode: 'platform',
          userId: res.identity.id,
          role: 'platform_admin',
          tenantId: null,
        });
        setTenantId('');
        toast.success('Signed in');
        navigate('/app/platform/tenants');
        return;
      }
      if (res.mode === 'tenant') {
        setIdentityFromUnifiedLogin({
          mode: 'tenant',
          userId: res.user.id,
          role: res.user.role as UserRole,
          tenantId: res.tenant.id,
          mustChangePassword: res.user.must_change_password,
        });
        setTenantId(res.tenant.id);
        localStorage.setItem(LAST_TENANT_KEY, res.tenant.id);
        toast.success('Signed in');
        navigate(res.user.must_change_password ? '/app/set-password' : '/app/dashboard');
        return;
      }
      if (res.mode === 'select_tenant') {
        setSelectTenantList(res.tenants);
        const lastId = localStorage.getItem(LAST_TENANT_KEY);
        const valid = res.tenants.some((t) => t.id === lastId);
        setSelectedTenantId(valid && lastId ? lastId : res.tenants[0]?.id ?? '');
      }
    } catch (err: unknown) {
      toast.error((err as Error)?.message ?? 'Sign in failed');
    } finally {
      setSubmitting(false);
    }
  };

  const handleSelectTenant = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedTenantId || !selectTenantList?.length) {
      toast.error('Select a farm');
      return;
    }
    setSelectSubmitting(true);
    try {
      const res = await selectTenant(selectedTenantId);
      setIdentityFromUnifiedLogin({
        mode: 'tenant',
        userId: res.user.id,
        role: res.user.role as UserRole,
        tenantId: res.tenant.id,
        mustChangePassword: res.user.must_change_password,
      });
      setTenantId(res.tenant.id);
      localStorage.setItem(LAST_TENANT_KEY, res.tenant.id);
      setSelectTenantList(null);
      toast.success('Signed in');
      navigate(res.user.must_change_password ? '/app/set-password' : '/app/dashboard');
    } catch (err: unknown) {
      toast.error((err as Error)?.message ?? 'Failed to select farm');
    } finally {
      setSelectSubmitting(false);
    }
  };

  // Dev-only: continue without login (role + tenant selection)
  const handleDevContinue = () => {
    if (!selectedRole) {
      toast.error('Please select a role');
      return;
    }
    const isPlatformAdmin = selectedRole === 'platform_admin';
    if (isPlatformAdmin) {
      setTenantId('');
      setDevIdentity({ role: 'platform_admin', tenantId: null });
      navigate('/app/platform/tenants');
      return;
    }
    if (!selectedDevTenantId) {
      toast.error('Please select a farm');
      return;
    }
    setTenantId(selectedDevTenantId);
    setDevIdentity({ role: selectedRole, tenantId: selectedDevTenantId });
    navigate('/app/dashboard');
  };

  const handleDevCreateTenant = async () => {
    if (!newFarmName.trim() || newFarmName.trim().length < 2) {
      toast.error('Farm name must be at least 2 characters');
      return;
    }
    try {
      setCreating(true);
      const response = await devApi.createTenant({ name: newFarmName.trim() });
      toast.success('Farm created');
      setNewFarmName('');
      await loadDevTenants();
      setSelectedDevTenantId(response.tenant.id);
      setTenantId(response.tenant.id);
    } catch (error: unknown) {
      const msg = (error as Error)?.message;
      if (msg?.includes('403') || msg?.includes('disabled')) {
        toast.error('Dev bootstrap disabled.');
      } else {
        toast.error(msg || 'Failed to create farm');
      }
    } finally {
      setCreating(false);
    }
  };

  if (DEV_TENANT_PICKER) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-2xl w-full space-y-8">
          <div>
            <div className="flex justify-center mb-4">
              <BrandLogo size="lg" />
            </div>
            <h2 className="mt-6 text-center text-3xl font-extrabold text-gray-900">Welcome (Dev)</h2>
            <p className="mt-2 text-center text-sm text-gray-600">Select farm and role to continue</p>
          </div>
          <div className="mt-8 space-y-6 bg-white shadow rounded-lg p-6">
            <div>
              <h3 className="text-lg font-medium text-gray-900 mb-4">Existing Farms</h3>
              {loading ? (
                <div className="flex justify-center py-8">
                  <LoadingSpinner />
                </div>
              ) : devTenants.length === 0 ? (
                <p className="text-sm text-gray-500 py-4">No farms. Create one below.</p>
              ) : (
                <div className="space-y-2">
                  {devTenants.map((t) => (
                    <div
                      key={t.id}
                      className={`flex items-center justify-between py-2 px-3 rounded ${selectedDevTenantId === t.id ? 'bg-[#E6ECEA]' : ''}`}
                    >
                      <span className="font-medium">{t.name}</span>
                      <div className="flex gap-2">
                        <button
                          type="button"
                          onClick={() => {
                            setSelectedDevTenantId(t.id);
                            setTenantId(t.id);
                          }}
                          className={`px-3 py-1 rounded text-sm font-medium ${selectedDevTenantId === t.id ? 'bg-[#1F6F5C] text-white' : 'bg-gray-200 text-gray-700'}`}
                        >
                          {selectedDevTenantId === t.id ? 'Selected' : 'Select'}
                        </button>
                        <button
                          type="button"
                          onClick={async () => {
                            try {
                              setBootstrappingId(t.id);
                              await devApi.bootstrapAccounts(t.id);
                              toast.success('Accounts bootstrapped');
                            } catch (err) {
                              toast.error((err as Error)?.message ?? 'Bootstrap failed');
                            } finally {
                              setBootstrappingId(null);
                            }
                          }}
                          disabled={bootstrappingId !== null}
                          className="px-3 py-1 rounded text-sm text-amber-700 bg-amber-100"
                        >
                          {bootstrappingId === t.id ? '…' : 'Bootstrap'}
                        </button>
                        <button
                          type="button"
                          onClick={async () => {
                            if (!window.confirm('Remove this farm?')) return;
                            setDeletingId(t.id);
                            try {
                              await devApi.deleteTenant(t.id);
                              await loadDevTenants();
                            } finally {
                              setDeletingId(null);
                            }
                          }}
                          disabled={deletingId !== null}
                          className="px-3 py-1 rounded text-sm text-red-700 bg-red-100"
                        >
                          Delete
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
            <div className="border-t pt-4">
              <h3 className="text-lg font-medium text-gray-900 mb-2">Create New Farm</h3>
              <div className="flex gap-2">
                <input
                  type="text"
                  value={newFarmName}
                  onChange={(e) => setNewFarmName(e.target.value)}
                  placeholder="Farm name"
                  className="flex-1 px-3 py-2 border border-gray-300 rounded-md"
                />
                <button
                  type="button"
                  onClick={handleDevCreateTenant}
                  disabled={creating || !newFarmName.trim()}
                  className="px-4 py-2 bg-green-600 text-white rounded-md disabled:opacity-50"
                >
                  Create
                </button>
              </div>
            </div>
            <div className="border-t pt-4">
              <label className="block text-sm font-medium text-gray-700 mb-2">Role</label>
              <select
                value={selectedRole}
                onChange={(e) => setSelectedRole(e.target.value as UserRole)}
                className="block w-full px-3 py-2 border border-gray-300 rounded-md"
              >
                <option value="tenant_admin">Tenant Admin</option>
                <option value="accountant">Accountant</option>
                <option value="operator">Operator</option>
                <option value="platform_admin">Platform Admin</option>
              </select>
            </div>
            <div className="border-t pt-4">
              <button
                type="button"
                onClick={handleDevContinue}
                disabled={!selectedRole || (selectedRole !== 'platform_admin' && !selectedDevTenantId)}
                className="w-full py-2 px-4 bg-[#1F6F5C] text-white rounded-md font-medium disabled:opacity-50"
              >
                Continue
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  if (selectTenantList !== null && selectTenantList.length > 0) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-md w-full space-y-8">
          <div>
            <div className="flex justify-center mb-4">
              <BrandLogo size="lg" />
            </div>
            <h2 className="mt-6 text-center text-2xl font-extrabold text-gray-900">Select a farm</h2>
            <p className="mt-2 text-center text-sm text-gray-600">You have access to more than one farm.</p>
          </div>
          <form onSubmit={handleSelectTenant} className="mt-8 space-y-4 bg-white shadow rounded-lg p-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Farm</label>
              <select
                value={selectedTenantId}
                onChange={(e) => setSelectedTenantId(e.target.value)}
                className="block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                required
              >
                {selectTenantList.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name} ({t.role})
                  </option>
                ))}
              </select>
            </div>
            <button
              type="submit"
              disabled={selectSubmitting}
              className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-[#1F6F5C] hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {selectSubmitting ? 'Signing in...' : 'Continue'}
            </button>
          </form>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div>
          <div className="flex justify-center mb-4">
            <BrandLogo size="lg" />
          </div>
          <h2 className="mt-6 text-center text-3xl font-extrabold text-gray-900">Welcome back to Terrava</h2>
          <p className="mt-2 text-center text-sm text-gray-600">Sign in with your email and password.</p>
        </div>
        <form onSubmit={handleUnifiedLogin} className="mt-8 space-y-4 bg-white shadow rounded-lg p-6">
          <div>
            <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-1">
              Email
            </label>
            <input
              id="email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              placeholder="you@example.com"
              autoComplete="email"
              required
            />
          </div>
          <div>
            <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-1">
              Password
            </label>
            <input
              id="password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              autoComplete="current-password"
              required
            />
          </div>
          <button
            type="submit"
            disabled={submitting}
            className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-[#1F6F5C] hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] disabled:opacity-50"
            data-testid="login-submit"
          >
            {submitting ? 'Signing in...' : 'Sign in'}
          </button>
        </form>
      </div>
    </div>
  );
}

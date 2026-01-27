import { useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { usePlatformTenant, useUpdatePlatformTenant } from '../../hooks/usePlatformTenants';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useFormatting } from '../../hooks/useFormatting';
import toast from 'react-hot-toast';
import type { UpdatePlatformTenantPayload } from '../../types';
import { platformApi } from '../../api/platform';
import { useQuery, useQueryClient } from '@tanstack/react-query';

export default function PlatformTenantDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { data: tenant, isLoading, error } = usePlatformTenant(id || null);
  const updateMutation = useUpdatePlatformTenant();
  const { formatDate } = useFormatting();

  const [editOpen, setEditOpen] = useState(false);
  const [editForm, setEditForm] = useState<UpdatePlatformTenantPayload>({});

  // Fetch modules for this tenant
  const { data: modulesData } = useQuery({
    queryKey: ['tenantModules', id],
    queryFn: async () => {
      // Note: This would need a platform admin endpoint to get modules for a tenant
      // For now, we'll show a placeholder
      return { modules: [] };
    },
    enabled: !!id,
  });

  const handleUpdate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!id) return;
    try {
      await updateMutation.mutateAsync({ id, payload: editForm });
      toast.success('Tenant updated');
      setEditOpen(false);
      setEditForm({});
    } catch (e: unknown) {
      toast.error((e as Error)?.message || 'Failed to update');
    }
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (error || !tenant) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">Error: {(error as Error)?.message || 'Tenant not found'}</p>
        <Link to="/app/platform/tenants" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
          Back to tenants
        </Link>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6 flex justify-between items-center">
        <div>
          <Link to="/app/platform/tenants" className="text-[#1F6F5C] hover:underline text-sm mb-2 inline-block">
            ← Back to tenants
          </Link>
          <h1 className="text-2xl font-bold text-gray-900">{tenant.name}</h1>
          <p className="text-sm text-gray-500 mt-1">Tenant ID: {tenant.id}</p>
        </div>
        <button
          type="button"
          onClick={() => {
            setEditForm({ name: tenant.name, status: tenant.status });
            setEditOpen(true);
          }}
          className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
        >
          Edit tenant
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Tenant Information */}
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Tenant Information</h2>
          <dl className="space-y-3">
            <div>
              <dt className="text-sm font-medium text-gray-500">Status</dt>
              <dd className="mt-1">
                <span
                  className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${
                    tenant.status === 'active'
                      ? 'bg-green-100 text-green-800'
                      : 'bg-yellow-100 text-yellow-800'
                  }`}
                >
                  {tenant.status}
                </span>
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Currency</dt>
              <dd className="mt-1 text-sm text-gray-900">{tenant.currency_code}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Locale</dt>
              <dd className="mt-1 text-sm text-gray-900">{tenant.locale}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Timezone</dt>
              <dd className="mt-1 text-sm text-gray-900">{tenant.timezone}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Created</dt>
              <dd className="mt-1 text-sm text-gray-900">
                {formatDate(tenant.created_at)}
              </dd>
            </div>
          </dl>
        </div>

        {/* Farm Profile */}
        {tenant.farm && (
          <div className="bg-white rounded-lg shadow p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Farm Profile</h2>
            <dl className="space-y-3">
              <div>
                <dt className="text-sm font-medium text-gray-500">Farm Name</dt>
                <dd className="mt-1 text-sm text-gray-900">{tenant.farm.farm_name}</dd>
              </div>
              {tenant.farm.country && (
                <div>
                  <dt className="text-sm font-medium text-gray-500">Country</dt>
                  <dd className="mt-1 text-sm text-gray-900">{tenant.farm.country}</dd>
                </div>
              )}
              {tenant.farm.city && (
                <div>
                  <dt className="text-sm font-medium text-gray-500">City</dt>
                  <dd className="mt-1 text-sm text-gray-900">{tenant.farm.city}</dd>
                </div>
              )}
              {tenant.farm.phone && (
                <div>
                  <dt className="text-sm font-medium text-gray-500">Phone</dt>
                  <dd className="mt-1 text-sm text-gray-900">{tenant.farm.phone}</dd>
                </div>
              )}
            </dl>
          </div>
        )}

        {/* Modules */}
        <div className="bg-white rounded-lg shadow p-6 md:col-span-2">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">Enabled Modules</h2>
          <p className="text-sm text-gray-500">
            Module management for this tenant. (Module management UI to be implemented)
          </p>
        </div>
      </div>

      {/* Edit Modal */}
      <Modal isOpen={editOpen} onClose={() => { setEditOpen(false); setEditForm({}); }} title="Edit tenant" size="md">
        <form onSubmit={handleUpdate} className="space-y-4">
          <FormField label="Name">
            <input
              type="text"
              value={editForm.name ?? tenant.name}
              onChange={(e) => setEditForm({ ...editForm, name: e.target.value })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            />
          </FormField>
          <FormField label="Status">
            <select
              value={editForm.status ?? tenant.status}
              onChange={(e) => setEditForm({ ...editForm, status: e.target.value as 'active' | 'suspended' })}
              className="w-full border border-gray-300 rounded-md px-3 py-2"
            >
              <option value="active">active</option>
              <option value="suspended">suspended</option>
            </select>
          </FormField>
          <div className="flex justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={() => { setEditOpen(false); setEditForm({}); }}
              className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={updateMutation.isPending}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {updateMutation.isPending ? 'Saving...' : 'Save'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
}

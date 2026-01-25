import { useState, useEffect } from 'react';
import { useFarmProfile, useUpdateFarmProfileMutation } from '../hooks/useFarmProfile';
import { FormField } from '../components/FormField';
import { LoadingSpinner } from '../components/LoadingSpinner';
import toast from 'react-hot-toast';
import type { UpdateFarmProfilePayload } from '../types';

export default function AdminFarmProfilePage() {
  const { data: farm, isLoading, error } = useFarmProfile();
  const updateMutation = useUpdateFarmProfileMutation();
  const [formData, setFormData] = useState<UpdateFarmProfilePayload>({
    farm_name: '',
    country: '',
    address_line1: '',
    address_line2: '',
    city: '',
    region: '',
    postal_code: '',
    phone: '',
  });

  useEffect(() => {
    if (farm) {
      setFormData({
        farm_name: farm.farm_name || '',
        country: farm.country ?? '',
        address_line1: farm.address_line1 ?? '',
        address_line2: farm.address_line2 ?? '',
        city: farm.city ?? '',
        region: farm.region ?? '',
        postal_code: farm.postal_code ?? '',
        phone: farm.phone ?? '',
      });
    }
  }, [farm]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await updateMutation.mutateAsync(formData);
      toast.success('Farm profile updated');
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

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">Error loading farm profile: {(error as Error).message}</p>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Farm Profile</h1>
        <p className="text-sm text-gray-500 mt-1">Farm name, address, and contact details.</p>
      </div>

      <form onSubmit={handleSubmit} className="bg-white rounded-lg shadow p-6 space-y-4">
        <FormField label="Farm name" required>
          <input
            type="text"
            value={formData.farm_name ?? ''}
            onChange={(e) => setFormData({ ...formData, farm_name: e.target.value })}
            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
            required
          />
        </FormField>
        <FormField label="Country">
          <input
            type="text"
            value={formData.country ?? ''}
            onChange={(e) => setFormData({ ...formData, country: e.target.value })}
            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </FormField>
        <FormField label="Address line 1">
          <input
            type="text"
            value={formData.address_line1 ?? ''}
            onChange={(e) => setFormData({ ...formData, address_line1: e.target.value })}
            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </FormField>
        <FormField label="Address line 2">
          <input
            type="text"
            value={formData.address_line2 ?? ''}
            onChange={(e) => setFormData({ ...formData, address_line2: e.target.value })}
            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </FormField>
        <FormField label="City">
          <input
            type="text"
            value={formData.city ?? ''}
            onChange={(e) => setFormData({ ...formData, city: e.target.value })}
            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </FormField>
        <FormField label="Region / State">
          <input
            type="text"
            value={formData.region ?? ''}
            onChange={(e) => setFormData({ ...formData, region: e.target.value })}
            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </FormField>
        <FormField label="Postal code">
          <input
            type="text"
            value={formData.postal_code ?? ''}
            onChange={(e) => setFormData({ ...formData, postal_code: e.target.value })}
            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </FormField>
        <FormField label="Phone">
          <input
            type="text"
            value={formData.phone ?? ''}
            onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </FormField>
        <div className="pt-4">
          <button
            type="submit"
            disabled={updateMutation.isPending}
            className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {updateMutation.isPending ? 'Saving...' : 'Save'}
          </button>
        </div>
      </form>
    </div>
  );
}

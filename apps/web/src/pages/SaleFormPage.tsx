import { useState, useEffect } from 'react';
import { useParams, Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useSale, useCreateSale, useUpdateSale } from '../hooks/useSales';
import { useParties } from '../hooks/useParties';
import { useProjects } from '../hooks/useProjects';
import { useCropCycles } from '../hooks/useCropCycles';
import { FormField } from '../components/FormField';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { useRole } from '../hooks/useRole';
import type { CreateSalePayload } from '../types';

export default function SaleFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const isEdit = !!id;
  const { data: sale, isLoading } = useSale(id || '');
  const createMutation = useCreateSale();
  const updateMutation = useUpdateSale();
  const { data: parties } = useParties();
  const { data: projects } = useProjects();
  const { data: cropCycles } = useCropCycles();
  const { hasRole } = useRole();
  
  // Get query params for prefill
  const prefilledBuyerPartyId = searchParams.get('buyerPartyId');

  const [formData, setFormData] = useState<CreateSalePayload>({
    buyer_party_id: prefilledBuyerPartyId || '',
    project_id: '',
    crop_cycle_id: '',
    amount: '',
    posting_date: new Date().toISOString().split('T')[0],
    sale_no: '',
    sale_date: '',
    due_date: '',
    notes: '',
  });

  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (sale && isEdit) {
      setFormData({
        buyer_party_id: sale.buyer_party_id,
        project_id: sale.project_id || '',
        crop_cycle_id: sale.crop_cycle_id || '',
        amount: sale.amount,
        posting_date: sale.posting_date,
        sale_no: sale.sale_no || '',
        sale_date: sale.sale_date || sale.posting_date,
        due_date: sale.due_date || sale.posting_date,
        notes: sale.notes || '',
      });
    } else if (!isEdit && prefilledBuyerPartyId) {
      setFormData((prev) => ({
        ...prev,
        buyer_party_id: prefilledBuyerPartyId || prev.buyer_party_id,
      }));
    }
  }, [sale, isEdit, prefilledBuyerPartyId]);

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {};

    if (!formData.posting_date) newErrors.posting_date = 'Posting date is required';
    if (!formData.buyer_party_id) newErrors.buyer_party_id = 'Buyer party is required';
    if (!formData.amount || parseFloat(String(formData.amount)) <= 0) {
      newErrors.amount = 'Valid amount is required';
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async () => {
    if (!validateForm()) return;

    try {
      const payload: CreateSalePayload = {
        ...formData,
        project_id: formData.project_id || undefined,
        crop_cycle_id: formData.crop_cycle_id || undefined,
        notes: formData.notes || undefined,
      };

      if (isEdit && id) {
        await updateMutation.mutateAsync({ id, payload });
      } else {
        await createMutation.mutateAsync(payload);
      }
      navigate('/app/sales');
    } catch (error: any) {
      // Error handled by mutation
    }
  };

  if (isLoading && isEdit) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (isEdit && sale?.status !== 'DRAFT') {
    return (
      <div>
        <p className="text-red-600">This sale cannot be edited because it is not in DRAFT status.</p>
        <Link to="/app/sales" className="text-blue-600 hover:text-blue-900">
          Back to Sales
        </Link>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/sales" className="text-blue-600 hover:text-blue-900 mb-2 inline-block">
          ← Back to Sales
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">
          {isEdit ? 'Edit Sale' : 'New Sale'}
        </h1>
      </div>

      <div className="bg-white rounded-lg shadow p-6">
        <div className="space-y-4">
          <FormField label="Buyer Party" required error={errors.buyer_party_id}>
            <select
              value={formData.buyer_party_id}
              onChange={(e) => setFormData({ ...formData, buyer_party_id: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            >
              <option value="">Select a buyer</option>
              {parties?.map((party) => (
                <option key={party.id} value={party.id}>
                  {party.name}
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="Amount" required error={errors.amount}>
            <input
              type="number"
              step="0.01"
              min="0.01"
              value={formData.amount}
              onChange={(e) => setFormData({ ...formData, amount: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            />
          </FormField>

          <FormField label="Posting Date" required error={errors.posting_date}>
            <input
              type="date"
              value={formData.posting_date}
              onChange={(e) => {
                const newDate = e.target.value;
                setFormData({ 
                  ...formData, 
                  posting_date: newDate,
                  // Auto-set sale_date and due_date if not set
                  sale_date: formData.sale_date || newDate,
                  due_date: formData.due_date || newDate,
                });
              }}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            />
          </FormField>

          <FormField label="Sale Number (Optional)">
            <input
              type="text"
              value={formData.sale_no}
              onChange={(e) => setFormData({ ...formData, sale_no: e.target.value })}
              disabled={!canEdit}
              placeholder="e.g., SALE-001"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            />
          </FormField>

          <FormField label="Sale Date (Optional)">
            <input
              type="date"
              value={formData.sale_date}
              onChange={(e) => setFormData({ ...formData, sale_date: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            />
            <p className="text-xs text-gray-500 mt-1">Defaults to posting date if not set</p>
          </FormField>

          <FormField label="Due Date (Optional)">
            <input
              type="date"
              value={formData.due_date}
              onChange={(e) => setFormData({ ...formData, due_date: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            />
            <p className="text-xs text-gray-500 mt-1">Used for AR ageing. Defaults to sale date if not set</p>
          </FormField>

          <FormField label="Project (Optional)">
            <select
              value={formData.project_id}
              onChange={(e) => setFormData({ ...formData, project_id: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            >
              <option value="">None</option>
              {projects?.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.name}
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="Crop Cycle (Optional)">
            <select
              value={formData.crop_cycle_id}
              onChange={(e) => setFormData({ ...formData, crop_cycle_id: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            >
              <option value="">None</option>
              {cropCycles?.map((cycle) => (
                <option key={cycle.id} value={cycle.id}>
                  {cycle.name}
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="Notes">
            <textarea
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              disabled={!canEdit}
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            />
          </FormField>

          {canEdit && (
            <div className="flex justify-end space-x-4 pt-4">
              <Link
                to="/app/sales"
                className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </Link>
              <button
                onClick={handleSubmit}
                disabled={createMutation.isPending || updateMutation.isPending}
                className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
              >
                {createMutation.isPending || updateMutation.isPending ? 'Saving...' : 'Save'}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

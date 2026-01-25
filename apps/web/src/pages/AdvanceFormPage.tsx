import { useState, useEffect } from 'react';
import { useParams, Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useAdvance, useCreateAdvance, useUpdateAdvance } from '../hooks/useAdvances';
import { useParties } from '../hooks/useParties';
import { useProjects } from '../hooks/useProjects';
import { useCropCycles } from '../hooks/useCropCycles';
import { FormField } from '../components/FormField';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { useRole } from '../hooks/useRole';
import type { CreateAdvancePayload, AdvanceType, AdvanceDirection, AdvanceMethod } from '../types';

export default function AdvanceFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const isEdit = !!id;
  const { data: advance, isLoading } = useAdvance(id || '');
  const createMutation = useCreateAdvance();
  const updateMutation = useUpdateAdvance();
  const { data: parties } = useParties();
  const { data: projects } = useProjects();
  const { data: cropCycles } = useCropCycles();
  const { hasRole } = useRole();
  
  // Get query params for prefill
  const prefilledPartyId = searchParams.get('partyId');
  const prefilledType = searchParams.get('type') as AdvanceType | null;
  const prefilledDirection = searchParams.get('direction') as AdvanceDirection | null;

  const [formData, setFormData] = useState<CreateAdvancePayload>({
    party_id: prefilledPartyId || '',
    type: prefilledType || 'HARI_ADVANCE',
    direction: prefilledDirection || 'OUT',
    amount: '',
    posting_date: new Date().toISOString().split('T')[0],
    method: 'CASH',
    project_id: '',
    crop_cycle_id: '',
    notes: '',
  });

  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (advance && isEdit) {
      setFormData({
        party_id: advance.party_id,
        type: advance.type,
        direction: advance.direction,
        amount: advance.amount,
        posting_date: advance.posting_date,
        method: advance.method,
        project_id: advance.project_id || '',
        crop_cycle_id: advance.crop_cycle_id || '',
        notes: advance.notes || '',
      });
    } else if (!isEdit && (prefilledPartyId || prefilledType || prefilledDirection)) {
      setFormData((prev) => ({
        ...prev,
        party_id: prefilledPartyId || prev.party_id,
        type: prefilledType || prev.type,
        direction: prefilledDirection || prev.direction,
      }));
    }
  }, [advance, isEdit, prefilledPartyId, prefilledType, prefilledDirection]);

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {};

    if (!formData.posting_date) newErrors.posting_date = 'Posting date is required';
    if (!formData.party_id) newErrors.party_id = 'Party is required';
    if (!formData.type) newErrors.type = 'Type is required';
    if (!formData.amount || parseFloat(String(formData.amount)) <= 0) {
      newErrors.amount = 'Valid amount is required';
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async () => {
    if (!validateForm()) return;

    try {
      const payload: CreateAdvancePayload = {
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
      navigate('/app/advances');
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

  if (isEdit && advance?.status !== 'DRAFT') {
    return (
      <div>
        <p className="text-red-600">This advance cannot be edited because it is not in DRAFT status.</p>
        <Link to="/app/advances" className="text-blue-600 hover:text-blue-900">
          Back to Advances
        </Link>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/advances" className="text-blue-600 hover:text-blue-900 mb-2 inline-block">
          ← Back to Advances
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">
          {isEdit ? 'Edit Advance' : 'New Advance'}
        </h1>
      </div>

      <div className="bg-white rounded-lg shadow p-6">
        <div className="space-y-4">
          <FormField label="Type" required error={errors.type}>
            <select
              value={formData.type}
              onChange={(e) => setFormData({ ...formData, type: e.target.value as AdvanceType })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            >
              <option value="HARI_ADVANCE">Hari Advance</option>
              <option value="VENDOR_ADVANCE">Vendor Advance</option>
              <option value="LOAN">Loan</option>
            </select>
          </FormField>

          <FormField label="Direction" required>
            <select
              value={formData.direction}
              onChange={(e) => setFormData({ ...formData, direction: e.target.value as AdvanceDirection })}
              disabled={!canEdit || isEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            >
              <option value="OUT">OUT (Disbursement)</option>
              <option value="IN">IN (Repayment)</option>
            </select>
          </FormField>

          <FormField label="Party" required error={errors.party_id}>
            <select
              value={formData.party_id}
              onChange={(e) => setFormData({ ...formData, party_id: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            >
              <option value="">Select a party</option>
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
              onChange={(e) => setFormData({ ...formData, posting_date: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            />
          </FormField>

          <FormField label="Method" required>
            <select
              value={formData.method}
              onChange={(e) => setFormData({ ...formData, method: e.target.value as AdvanceMethod })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
            >
              <option value="CASH">CASH</option>
              <option value="BANK">BANK</option>
            </select>
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
                to="/app/advances"
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

import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useProjectRule, useUpdateProjectRule } from '../hooks/useProjectRules';
import { useParties } from '../hooks/useParties';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import toast from 'react-hot-toast';
import type { UpdateProjectRulePayload } from '../types';

export default function ProjectRulesPage() {
  const { id } = useParams<{ id: string }>();
  const { data: rule, isLoading } = useProjectRule(id || '');
  const { data: parties } = useParties();
  const updateMutation = useUpdateProjectRule();
  const { hasRole } = useRole();
  const [formData, setFormData] = useState<UpdateProjectRulePayload>({
    profit_split_landlord_pct: '50',
    profit_split_hari_pct: '50',
    kamdari_pct: '0',
    kamdar_party_id: '',
    kamdari_order: 'BEFORE_SPLIT',
    pool_definition: 'REVENUE_MINUS_SHARED_COSTS',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});

  const canEdit = hasRole(['tenant_admin', 'accountant']);
  const kamdarParties = parties?.filter((p) => p.party_types.includes('KAMDAR')) || [];

  useEffect(() => {
    if (rule) {
      setFormData({
        profit_split_landlord_pct: rule.profit_split_landlord_pct != null ? String(rule.profit_split_landlord_pct) : '50',
        profit_split_hari_pct: rule.profit_split_hari_pct != null ? String(rule.profit_split_hari_pct) : '50',
        kamdari_pct: rule.kamdari_pct != null ? String(rule.kamdari_pct) : '0',
        kamdar_party_id: rule.kamdar_party_id || '',
        kamdari_order: rule.kamdari_order || 'BEFORE_SPLIT',
        pool_definition: rule.pool_definition || 'REVENUE_MINUS_SHARED_COSTS',
      });
    }
  }, [rule]);

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {};
    
    const landlordPct = parseFloat(String(formData.profit_split_landlord_pct || 0));
    const hariPct = parseFloat(String(formData.profit_split_hari_pct || 0));
    const kamdariPct = parseFloat(String(formData.kamdari_pct || 0));
    const total = landlordPct + hariPct;

    if (total !== 100) {
      newErrors.profit_split = `Landlord and HARI percentages must sum to 100% (currently ${total}%)`;
    }

    if (kamdariPct < 0 || kamdariPct > 100) {
      newErrors.kamdari_pct = 'Kamdari percentage must be between 0 and 100';
    }

    if (kamdariPct > 0 && !formData.kamdar_party_id) {
      newErrors.kamdar_party_id = 'Kamdar party is required when kamdari percentage is greater than 0';
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async () => {
    if (!id || !validateForm()) return;

    try {
      const payload = {
        ...formData,
        profit_split_landlord_pct: parseFloat(String(formData.profit_split_landlord_pct || 0)),
        profit_split_hari_pct: parseFloat(String(formData.profit_split_hari_pct || 0)),
        kamdari_pct: parseFloat(String(formData.kamdari_pct || 0)),
      };
      await updateMutation.mutateAsync({ projectId: id, payload });
      toast.success('Project rules updated successfully');
    } catch (error: any) {
      toast.error(error.message || 'Failed to update project rules');
    }
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!rule) {
    return <div>Project rules not found</div>;
  }

  const landlordPct = parseFloat(String(formData.profit_split_landlord_pct || 0));
  const hariPct = parseFloat(String(formData.profit_split_hari_pct || 0));
  const total = landlordPct + hariPct;

  return (
    <div>
      <div className="mb-6">
        <Link to={`/app/projects/${id}`} className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Project
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">Project Rules</h1>
      </div>

      <div className="bg-white rounded-lg shadow p-6">
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <FormField label="Landlord %" required error={errors.profit_split}>
              <input
                type="number"
                step="0.01"
                min="0"
                max="100"
                value={formData.profit_split_landlord_pct}
                onChange={(e) => {
                  setFormData({ ...formData, profit_split_landlord_pct: e.target.value });
                  setErrors({ ...errors, profit_split: '' });
                }}
                disabled={!canEdit}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
              />
            </FormField>
            <FormField label="HARI %" required>
              <input
                type="number"
                step="0.01"
                min="0"
                max="100"
                value={formData.profit_split_hari_pct}
                onChange={(e) => {
                  setFormData({ ...formData, profit_split_hari_pct: e.target.value });
                  setErrors({ ...errors, profit_split: '' });
                }}
                disabled={!canEdit}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
              />
            </FormField>
          </div>
          <div className="text-sm text-gray-600">
            Total: {total}% {total !== 100 && <span className="text-red-600">(Must be 100%)</span>}
          </div>

          <FormField label="Kamdari %" error={errors.kamdari_pct}>
            <input
              type="number"
              step="0.01"
              min="0"
              max="100"
              value={formData.kamdari_pct}
              onChange={(e) => {
                setFormData({ ...formData, kamdari_pct: e.target.value });
                setErrors({ ...errors, kamdari_pct: '' });
              }}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
          </FormField>

          {parseFloat(String(formData.kamdari_pct || 0)) > 0 && (
            <FormField label="Kamdar Party" required error={errors.kamdar_party_id}>
              <select
                value={formData.kamdar_party_id}
                onChange={(e) => {
                  setFormData({ ...formData, kamdar_party_id: e.target.value });
                  setErrors({ ...errors, kamdar_party_id: '' });
                }}
                disabled={!canEdit}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
              >
                <option value="">Select kamdar party</option>
                {kamdarParties.map((party) => (
                  <option key={party.id} value={party.id}>
                    {party.name}
                  </option>
                ))}
              </select>
            </FormField>
          )}

          {canEdit && (
            <div className="flex justify-end space-x-3 pt-4">
              <button
                onClick={handleSubmit}
                disabled={updateMutation.isPending || total !== 100}
                className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {updateMutation.isPending ? 'Saving...' : 'Save Rules'}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

import { useState, type ChangeEvent } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { shareRulesApi, type ShareRule, type CreateShareRulePayload } from '../api/shareRules';
import { partiesApi } from '../api/parties';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import toast from 'react-hot-toast';

type ShareRuleFormLine = { party_id: string; percentage: string; role?: string };
type ShareRuleFormState = Omit<CreateShareRulePayload, 'lines'> & { lines: ShareRuleFormLine[] };

export default function ShareRulesPage() {
  const queryClient = useQueryClient();
  const { hasRole } = useRole();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingRule, setEditingRule] = useState<ShareRule | null>(null);
  const [formData, setFormData] = useState<ShareRuleFormState>({
    name: '',
    applies_to: 'CROP_CYCLE',
    basis: 'MARGIN',
    effective_from: '',
    effective_to: '',
    is_active: true,
    lines: [],
  });
  const [errors, setErrors] = useState<Record<string, string>>({});

  const canCreate = hasRole(['tenant_admin', 'accountant']);

  const { data: rules, isLoading } = useQuery({
    queryKey: ['shareRules'],
    queryFn: () => shareRulesApi.list(),
  });

  const { data: parties } = useQuery({
    queryKey: ['parties'],
    queryFn: () => partiesApi.list(),
  });

  const createMutation = useMutation({
    mutationFn: (payload: CreateShareRulePayload) => shareRulesApi.create(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shareRules'] });
      toast.success('Share rule created successfully');
      setShowCreateModal(false);
      resetForm();
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to create share rule');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<CreateShareRulePayload> }) =>
      shareRulesApi.update(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['shareRules'] });
      toast.success('Share rule updated successfully');
      setEditingRule(null);
      resetForm();
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to update share rule');
    },
  });

  const resetForm = () => {
    setFormData({
      name: '',
      applies_to: 'CROP_CYCLE',
      basis: 'MARGIN',
      effective_from: '',
      effective_to: '',
      is_active: true,
      lines: [],
    });
    setErrors({});
  };

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {};
    if (!formData.name.trim()) {
      newErrors.name = 'Name is required';
    }
    if (!formData.effective_from) {
      newErrors.effective_from = 'Effective from date is required';
    }
    if (formData.lines.length === 0) {
      newErrors.lines = 'At least one party line is required';
    }
    const totalPercentage = formData.lines.reduce((sum, line) => sum + parseFloat(String(line.percentage)) || 0, 0);
    if (Math.abs(totalPercentage - 100) > 0.01) {
      newErrors.lines = `Percentages must sum to 100 (current: ${totalPercentage.toFixed(2)}%)`;
    }
    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleCreate = () => {
    if (!validateForm()) return;
    const payload: CreateShareRulePayload = {
      ...formData,
      lines: formData.lines.map((l) => ({ party_id: l.party_id, percentage: parseFloat(String(l.percentage)) || 0, role: l.role })),
    };
    createMutation.mutate(payload);
  };

  const handleUpdate = () => {
    if (!editingRule || !validateForm()) return;
    const payload: CreateShareRulePayload = {
      ...formData,
      lines: formData.lines.map((l) => ({ party_id: l.party_id, percentage: parseFloat(String(l.percentage)) || 0, role: l.role })),
    };
    updateMutation.mutate({ id: editingRule.id, payload });
  };

  const handleEdit = (rule: ShareRule) => {
    setEditingRule(rule);
    setFormData({
      name: rule.name,
      applies_to: rule.applies_to,
      basis: rule.basis,
      effective_from: rule.effective_from,
      effective_to: rule.effective_to || '',
      is_active: rule.is_active,
      lines: rule.lines?.map((line) => ({
        party_id: line.party_id,
        percentage: line.percentage != null ? String(line.percentage) : '',
        role: line.role || undefined,
      })) || [],
    });
    setShowCreateModal(true);
  };

  const addLine = () => {
    setFormData({
      ...formData,
      lines: [...formData.lines, { party_id: '', percentage: '', role: '' }],
    });
  };

  const removeLine = (index: number) => {
    setFormData({
      ...formData,
      lines: formData.lines.filter((_, i) => i !== index),
    });
  };

  const updateLine = (index: number, field: string, value: any) => {
    const newLines = [...formData.lines];
    newLines[index] = { ...newLines[index], [field]: value };
    setFormData({ ...formData, lines: newLines });
  };

  const columns: Column<ShareRule>[] = [
    { header: 'Name', accessor: 'name' },
    { header: 'Applies To', accessor: 'applies_to' },
    { header: 'Basis', accessor: 'basis' },
    { header: 'Effective From', accessor: 'effective_from' },
    { header: 'Effective To', accessor: (row) => row.effective_to || 'No end' },
    { header: 'Version', accessor: 'version' },
    { header: 'Active', accessor: (row) => (row.is_active ? 'Yes' : 'No') },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex gap-2">
          <button
            onClick={() => handleEdit(row)}
            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
            disabled={!canCreate}
          >
            Edit
          </button>
        </div>
      ),
    },
  ];

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div>
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Share Rules</h1>
        {canCreate && (
          <button
            onClick={() => {
              resetForm();
              setEditingRule(null);
              setShowCreateModal(true);
            }}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
          >
            New Share Rule
          </button>
        )}
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable data={rules || []} columns={columns} />
      </div>

      {showCreateModal && (
        <Modal
          isOpen={showCreateModal}
          title={editingRule ? 'Edit Share Rule' : 'Create Share Rule'}
          onClose={() => {
            setShowCreateModal(false);
            setEditingRule(null);
            resetForm();
          }}
        >
          <div className="space-y-4">
            <FormField label="Name" error={errors.name}>
              <input
                value={formData.name}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setFormData({ ...formData, name: e.target.value })}
                className="w-full px-3 py-2 border rounded"
              />
            </FormField>

            <FormField label="Applies To">
              <select
                value={formData.applies_to}
                onChange={(e: ChangeEvent<HTMLSelectElement>) => setFormData({ ...formData, applies_to: e.target.value as 'SALE' | 'PROJECT' | 'CROP_CYCLE' })}
                className="w-full px-3 py-2 border rounded"
              >
                <option value="CROP_CYCLE">Crop Cycle</option>
                <option value="PROJECT">Project</option>
                <option value="SALE">Sale</option>
              </select>
            </FormField>

            <FormField label="Basis">
              <select
                value={formData.basis}
                onChange={(e: ChangeEvent<HTMLSelectElement>) => setFormData({ ...formData, basis: e.target.value as 'MARGIN' | 'REVENUE' })}
                className="w-full px-3 py-2 border rounded"
              >
                <option value="MARGIN">Margin</option>
                <option value="REVENUE">Revenue</option>
              </select>
            </FormField>

            <FormField label="Effective From" error={errors.effective_from}>
              <input
                type="date"
                value={formData.effective_from}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setFormData({ ...formData, effective_from: e.target.value })}
                className="w-full px-3 py-2 border rounded"
              />
            </FormField>

            <FormField label="Effective To (optional)">
              <input
                type="date"
                value={formData.effective_to ?? ''}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setFormData({ ...formData, effective_to: e.target.value })}
                className="w-full px-3 py-2 border rounded"
              />
            </FormField>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Share Lines {errors.lines && <span className="text-red-600">({errors.lines})</span>}
              </label>
              {formData.lines.map((line, index) => (
                <div key={index} className="flex gap-2 mb-2">
                  <select
                    value={line.party_id}
                    onChange={(e) => updateLine(index, 'party_id', e.target.value)}
                    className="flex-1 border rounded px-3 py-2"
                  >
                    <option value="">Select Party</option>
                    {parties?.map((party) => (
                      <option key={party.id} value={party.id}>
                        {party.name}
                      </option>
                    ))}
                  </select>
                  <input
                    type="number"
                    value={line.percentage}
                    onChange={(e) => updateLine(index, 'percentage', e.target.value)}
                    className="w-24 border rounded px-3 py-2"
                    placeholder="%"
                    step="0.01"
                    min="0"
                    max="100"
                  />
                  <input
                    type="text"
                    value={line.role || ''}
                    onChange={(e) => updateLine(index, 'role', e.target.value)}
                    className="w-32 border rounded px-3 py-2"
                    placeholder="Role"
                  />
                  <button
                    onClick={() => removeLine(index)}
                    className="px-3 py-2 bg-red-600 text-white rounded hover:bg-red-700"
                  >
                    Remove
                  </button>
                </div>
              ))}
              <button
                onClick={addLine}
                className="mt-2 px-3 py-2 bg-gray-600 text-white rounded hover:bg-gray-700"
              >
                Add Line
              </button>
            </div>

            <div className="flex justify-end gap-2 mt-6">
              <button
                onClick={() => {
                  setShowCreateModal(false);
                  setEditingRule(null);
                  resetForm();
                }}
                className="px-4 py-2 border rounded"
              >
                Cancel
              </button>
              <button
                onClick={editingRule ? handleUpdate : handleCreate}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a]"
                disabled={createMutation.isPending || updateMutation.isPending}
              >
                {editingRule ? 'Update' : 'Create'}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}

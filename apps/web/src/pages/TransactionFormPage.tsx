import { useState, useEffect } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useOperationalTransaction, useCreateOperationalTransaction, useUpdateOperationalTransaction } from '../hooks/useOperationalTransactions';
import { useCropCycles } from '../hooks/useCropCycles';
import { useProjects } from '../hooks/useProjects';
import { FormField } from '../components/FormField';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { useRole } from '../hooks/useRole';
import toast from 'react-hot-toast';
import type { CreateOperationalTransactionPayload, TransactionType, TransactionClassification } from '../types';

export default function TransactionFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const isEdit = !!id;
  const { data: transaction, isLoading } = useOperationalTransaction(id || '');
  const createMutation = useCreateOperationalTransaction();
  const updateMutation = useUpdateOperationalTransaction();
  const { data: cropCycles } = useCropCycles();
  const { data: projects } = useProjects();
  const { hasRole } = useRole();

  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const [formData, setFormData] = useState<CreateOperationalTransactionPayload>({
    type: 'EXPENSE',
    transaction_date: new Date().toISOString().split('T')[0],
    amount: '',
    classification: 'SHARED',
    project_id: '',
    crop_cycle_id: '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (transaction && isEdit) {
      setFormData({
        type: transaction.type,
        transaction_date: transaction.transaction_date,
        amount: transaction.amount,
        classification: transaction.classification,
        project_id: transaction.project_id || '',
        crop_cycle_id: transaction.crop_cycle_id || '',
      });
    }
  }, [transaction, isEdit]);

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {};

    if (!formData.transaction_date) newErrors.transaction_date = 'Transaction date is required';
    if (!formData.amount || parseFloat(String(formData.amount)) <= 0) {
      newErrors.amount = 'Valid amount is required';
    }

    if (formData.classification === 'FARM_OVERHEAD') {
      if (formData.project_id) {
        newErrors.project_id = 'FARM_OVERHEAD transactions cannot have a project';
      }
      if (!formData.crop_cycle_id) {
        newErrors.crop_cycle_id = 'FARM_OVERHEAD transactions require a crop cycle';
      }
    } else {
      if (!formData.project_id) {
        newErrors.project_id = 'SHARED and HARI_ONLY transactions require a project';
      }
      if (!['SHARED', 'HARI_ONLY'].includes(formData.classification)) {
        newErrors.classification = 'Invalid classification for project transaction';
      }
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async () => {
    if (!validateForm()) return;

    try {
      // Prepare payload - for INCOME with Project, ensure classification is SHARED
      const payload: CreateOperationalTransactionPayload = { ...formData };
      if (payload.type === 'INCOME' && payload.project_id && payload.classification !== 'FARM_OVERHEAD') {
        payload.classification = 'SHARED';
      }

      if (isEdit && id) {
        await updateMutation.mutateAsync({ id, payload });
        toast.success('Transaction updated successfully');
      } else {
        await createMutation.mutateAsync(payload);
        toast.success('Transaction created successfully');
      }
      navigate('/app/transactions');
    } catch (error: any) {
      toast.error(error.message || 'Failed to save transaction');
    }
  };

  if (isLoading && isEdit) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (isEdit && transaction?.status !== 'DRAFT') {
    return (
      <div>
        <p className="text-red-600">This transaction cannot be edited because it is not in DRAFT status.</p>
        <Link to="/app/transactions" className="text-[#1F6F5C] hover:text-[#1a5a4a]">
          Back to Transactions
        </Link>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/transactions" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Transactions
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">
          {isEdit ? 'Edit Transaction' : 'New Transaction'}
        </h1>
      </div>

      <div className="bg-white rounded-lg shadow p-6">
        <div className="space-y-4">
          <FormField label="Type" required>
            <select
              value={formData.type}
              onChange={(e) => {
                const newType = e.target.value as TransactionType;
                const newData: any = { ...formData, type: newType };
                // For INCOME with Project destination, automatically set classification to SHARED
                if (newType === 'INCOME' && formData.classification !== 'FARM_OVERHEAD' && formData.project_id) {
                  newData.classification = 'SHARED';
                }
                setFormData(newData);
              }}
              disabled={!canEdit || isEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="EXPENSE">Expense</option>
              <option value="INCOME">Income</option>
            </select>
          </FormField>

          <FormField label="Destination" required>
            <select
              value={formData.classification === 'FARM_OVERHEAD' ? 'FARM_OVERHEAD' : 'PROJECT'}
              onChange={(e) => {
                const dest = e.target.value;
                if (dest === 'FARM_OVERHEAD') {
                  setFormData({
                    ...formData,
                    classification: 'FARM_OVERHEAD',
                    project_id: '',
                    crop_cycle_id: '',
                  });
                } else {
                  setFormData({
                    ...formData,
                    classification: 'SHARED',
                    crop_cycle_id: '',
                  });
                }
                setErrors({});
              }}
              disabled={!canEdit || isEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="PROJECT">Project</option>
              <option value="FARM_OVERHEAD">Farm Overhead</option>
            </select>
          </FormField>

          {formData.classification === 'FARM_OVERHEAD' ? (
            <FormField label="Crop Cycle" required error={errors.crop_cycle_id}>
              <select
                value={formData.crop_cycle_id}
                onChange={(e) => {
                  setFormData({ ...formData, crop_cycle_id: e.target.value });
                  setErrors({ ...errors, crop_cycle_id: '' });
                }}
                disabled={!canEdit}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
              >
                <option value="">Select crop cycle</option>
                {cropCycles?.map((cycle) => (
                  <option key={cycle.id} value={cycle.id}>
                    {cycle.name}
                  </option>
                ))}
              </select>
            </FormField>
          ) : (
            <>
              <FormField label="Project" required error={errors.project_id}>
                <select
                  value={formData.project_id}
                  onChange={(e) => {
                    // For INCOME transactions, automatically set classification to SHARED
                    const newData: any = { ...formData, project_id: e.target.value };
                    if (formData.type === 'INCOME') {
                      newData.classification = 'SHARED';
                    }
                    setFormData(newData);
                    setErrors({ ...errors, project_id: '' });
                  }}
                  disabled={!canEdit}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
                >
                  <option value="">Select project</option>
                  {projects?.map((project) => (
                    <option key={project.id} value={project.id}>
                      {project.name}
                    </option>
                  ))}
                </select>
              </FormField>
              {/* Only show classification for EXPENSE transactions with Project destination */}
              {formData.type === 'EXPENSE' && (
                <FormField label="Classification" required error={errors.classification}>
                  <select
                    value={formData.classification}
                    onChange={(e) => {
                      setFormData({ ...formData, classification: e.target.value as TransactionClassification });
                      setErrors({ ...errors, classification: '' });
                    }}
                    disabled={!canEdit}
                    className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
                  >
                    <option value="SHARED">Shared</option>
                    <option value="HARI_ONLY">HARI Only</option>
                  </select>
                </FormField>
              )}
            </>
          )}

          <FormField label="Transaction Date" required error={errors.transaction_date}>
            <input
              type="date"
              value={formData.transaction_date}
              onChange={(e) => {
                setFormData({ ...formData, transaction_date: e.target.value });
                setErrors({ ...errors, transaction_date: '' });
              }}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
          </FormField>

          <FormField label="Amount" required error={errors.amount}>
            <input
              type="number"
              step="0.01"
              min="0.01"
              value={formData.amount}
              onChange={(e) => {
                setFormData({ ...formData, amount: e.target.value });
                setErrors({ ...errors, amount: '' });
              }}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
          </FormField>

          {canEdit && (
            <div className="flex justify-end space-x-3 pt-4">
              <Link
                to="/app/transactions"
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
              >
                Cancel
              </Link>
              <button
                onClick={handleSubmit}
                disabled={createMutation.isPending || updateMutation.isPending}
                className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
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

import { useState, useEffect } from 'react';
import { useProjects } from '../hooks/useProjects';
import { useSettlementPreview, usePostSettlement, useSettlementOffsetPreview } from '../hooks/useSettlement';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import { settlementPreviewSchema, settlementPostSchema } from '../validation/settlementSchema';
import toast from 'react-hot-toast';
import { v4 as uuidv4 } from 'uuid';

export default function SettlementPage() {
  const { data: projects } = useProjects();
  const previewMutation = useSettlementPreview();
  const postMutation = usePostSettlement();
  const { canSettle } = useRole();
  const [selectedProjectId, setSelectedProjectId] = useState('');
  const [upToDate, setUpToDate] = useState(new Date().toISOString().split('T')[0]);
  const [preview, setPreview] = useState<any>(null);
  const [showPostModal, setShowPostModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(uuidv4());
  const [applyAdvanceOffset, setApplyAdvanceOffset] = useState(false);
  const [advanceOffsetAmount, setAdvanceOffsetAmount] = useState<number | ''>('');

  // Fetch offset preview when posting date or project changes
  const offsetPreviewQuery = useSettlementOffsetPreview(
    selectedProjectId,
    showPostModal ? postingDate : null,
    showPostModal && !!selectedProjectId
  );

  // Update offset amount when preview data changes
  useEffect(() => {
    if (offsetPreviewQuery.data && applyAdvanceOffset) {
      setAdvanceOffsetAmount(offsetPreviewQuery.data.suggested_offset || '');
    }
  }, [offsetPreviewQuery.data, applyAdvanceOffset]);

  const handlePreview = async () => {
    try {
      const validated = settlementPreviewSchema.parse({
        projectId: selectedProjectId,
        upToDate,
      });
      const result = await previewMutation.mutateAsync(validated);
      setPreview(result);
    } catch (error: any) {
      if (error.errors) {
        // Zod validation errors
        const firstError = error.errors[0];
        toast.error(firstError.message || 'Validation error');
      } else {
        toast.error(error.message || 'Failed to preview settlement');
      }
    }
  };

  const handlePost = async () => {
    try {
      // Validate with zod
      const validated = settlementPostSchema.parse({
        projectId: selectedProjectId,
        postingDate,
        upToDate,
        applyAdvanceOffset,
        advanceOffsetAmount: applyAdvanceOffset && advanceOffsetAmount ? Number(advanceOffsetAmount) : null,
      });

      // Additional validation for offset amount
      if (applyAdvanceOffset && validated.advanceOffsetAmount) {
        if (validated.advanceOffsetAmount <= 0) {
          toast.error('Please enter a valid offset amount');
          return;
        }
        if (offsetPreviewQuery.data) {
          const maxOffset = offsetPreviewQuery.data.max_offset;
          if (validated.advanceOffsetAmount > maxOffset) {
            toast.error(`Offset amount cannot exceed ${maxOffset.toFixed(2)}`);
            return;
          }
        }
      }

      const result = await postMutation.mutateAsync({
        projectId: validated.projectId,
        payload: {
          posting_date: validated.postingDate,
          up_to_date: validated.upToDate,
          idempotency_key: idempotencyKey,
          apply_advance_offset: validated.applyAdvanceOffset || false,
          advance_offset_amount: validated.advanceOffsetAmount || null,
          advance_offset_amount: applyAdvanceOffset && advanceOffsetAmount !== '' ? Number(advanceOffsetAmount) : undefined,
        },
      });
      
      const offsetMessage = applyAdvanceOffset && result.settlement?.offsets?.length
        ? ` Advance offset applied: ${parseFloat(result.settlement.offsets[0].offset_amount).toFixed(2)}`
        : '';
      
      const settlementId = result.settlement?.id || result.settlement_id || 'N/A';
      const postingGroupId = result.posting_group?.id || result.posting_group_id || 'N/A';
      
      toast.success(
        `Settlement posted successfully. Settlement ID: ${settlementId}, Posting Group: ${postingGroupId}.${offsetMessage}`
      );
      setShowPostModal(false);
      setPreview(null);
      setApplyAdvanceOffset(false);
      setAdvanceOffsetAmount('');
    } catch (error: any) {
      toast.error(error.message || 'Failed to post settlement');
    }
  };

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Settlement</h1>

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">Settlement Dashboard</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <FormField label="Project" required>
            <select
              value={selectedProjectId}
              onChange={(e) => setSelectedProjectId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">Select project</option>
              {projects?.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Up To Date" required>
            <input
              type="date"
              value={upToDate}
              onChange={(e) => setUpToDate(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </FormField>
          <div className="flex items-end">
            <button
              onClick={handlePreview}
              disabled={previewMutation.isPending || !selectedProjectId}
              className="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {previewMutation.isPending ? 'Loading...' : 'Preview Settlement'}
            </button>
          </div>
        </div>
      </div>

      {preview && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <div className="flex justify-between items-center mb-4">
            <h2 className="text-lg font-medium text-gray-900">Settlement Preview</h2>
            {canSettle && (
              <button
                onClick={() => setShowPostModal(true)}
                className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
              >
                Post Settlement
              </button>
            )}
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">Pool Revenue</dt>
              <dd className="text-lg font-semibold text-gray-900">{preview.pool_revenue}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Shared Costs</dt>
              <dd className="text-lg font-semibold text-gray-900">{preview.shared_costs}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Pool Profit</dt>
              <dd className="text-lg font-semibold text-gray-900">{preview.pool_profit}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Kamdari Amount</dt>
              <dd className="text-lg font-semibold text-gray-900">{preview.kamdari_amount}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Landlord Gross</dt>
              <dd className="text-lg font-semibold text-gray-900">{preview.landlord_gross}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">HARI Gross</dt>
              <dd className="text-lg font-semibold text-gray-900">{preview.hari_gross}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">HARI Only Deductions</dt>
              <dd className="text-lg font-semibold text-gray-900">{preview.hari_only_deductions}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">HARI Net</dt>
              <dd className="text-lg font-semibold text-gray-900">{preview.hari_net}</dd>
            </div>
          </div>
        </div>
      )}

      <Modal
        isOpen={showPostModal}
        onClose={() => {
          setShowPostModal(false);
          setApplyAdvanceOffset(false);
          setAdvanceOffsetAmount('');
        }}
        title="Post Settlement"
      >
        <div className="space-y-4">
          <FormField label="Posting Date" required>
            <input
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </FormField>
          <FormField label="Idempotency Key">
            <input
              type="text"
              value={idempotencyKey}
              readOnly
              className="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100"
            />
          </FormField>

          {/* Advance Offset Section */}
          {offsetPreviewQuery.data && offsetPreviewQuery.data.outstanding_advance > 0 && (
            <div className="border-t pt-4 mt-4">
              <h3 className="text-sm font-medium text-gray-900 mb-3">Advance Offset</h3>
              
              <div className="bg-gray-50 p-3 rounded-md mb-3 space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-gray-600">Hari Payable:</span>
                  <span className="font-medium">{offsetPreviewQuery.data.hari_payable_amount.toFixed(2)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-600">Outstanding Advance:</span>
                  <span className="font-medium">{offsetPreviewQuery.data.outstanding_advance.toFixed(2)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-600">Suggested Offset:</span>
                  <span className="font-medium text-blue-600">{offsetPreviewQuery.data.suggested_offset.toFixed(2)}</span>
                </div>
              </div>

              <div className="mb-3">
                <label className="flex items-center">
                  <input
                    type="checkbox"
                    checked={applyAdvanceOffset}
                    onChange={(e) => {
                      setApplyAdvanceOffset(e.target.checked);
                      if (e.target.checked) {
                        setAdvanceOffsetAmount(offsetPreviewQuery.data.suggested_offset || '');
                      } else {
                        setAdvanceOffsetAmount('');
                      }
                    }}
                    className="mr-2 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                  />
                  <span className="text-sm text-gray-700">Apply advance offset</span>
                </label>
                <p className="text-xs text-gray-500 mt-1 ml-6">
                  This reduces Hari payable and reduces Hari advance by the same amount.
                </p>
              </div>

              {applyAdvanceOffset && (
                <FormField label="Offset Amount" required>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    max={offsetPreviewQuery.data.max_offset}
                    value={advanceOffsetAmount}
                    onChange={(e) => {
                      const val = e.target.value === '' ? '' : parseFloat(e.target.value);
                      setAdvanceOffsetAmount(val);
                    }}
                    className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder={offsetPreviewQuery.data.suggested_offset.toFixed(2)}
                  />
                  <p className="text-xs text-gray-500 mt-1">
                    Maximum: {offsetPreviewQuery.data.max_offset.toFixed(2)}
                  </p>
                </FormField>
              )}

              {offsetPreviewQuery.isLoading && (
                <div className="text-sm text-gray-500">Loading offset preview...</div>
              )}
            </div>
          )}

          <div className="flex justify-end space-x-3">
            <button
              onClick={() => {
                setShowPostModal(false);
                setApplyAdvanceOffset(false);
                setAdvanceOffsetAmount('');
              }}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              onClick={handlePost}
              disabled={postMutation.isPending || (applyAdvanceOffset && (!advanceOffsetAmount || advanceOffsetAmount <= 0))}
              className="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {postMutation.isPending ? 'Posting...' : 'Post Settlement'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

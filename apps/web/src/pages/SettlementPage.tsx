import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useProjects } from '../hooks/useProjects';
import { useSettlementPreview, usePostSettlement, useSettlementOffsetPreview } from '../hooks/useSettlement';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import { settlementPreviewSchema, settlementPostSchema } from '../validation/settlementSchema';
import toast from 'react-hot-toast';
import { v4 as uuidv4 } from 'uuid';
import type { SettlementPreview } from '../types';

export default function SettlementPage() {
  const { data: projects } = useProjects();
  const previewMutation = useSettlementPreview();
  const postMutation = usePostSettlement();
  const { canSettle } = useRole();
  const [selectedProjectId, setSelectedProjectId] = useState('');
  const [upToDate, setUpToDate] = useState(new Date().toISOString().split('T')[0]);
  const [preview, setPreview] = useState<SettlementPreview | null>(null);
  const [expensesSectionExpanded, setExpensesSectionExpanded] = useState(false);
  const [showPostModal, setShowPostModal] = useState(false);
  const { formatMoney } = useFormatting();
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
      setExpensesSectionExpanded(false);
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
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
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
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex items-end">
            <button
              onClick={handlePreview}
              disabled={previewMutation.isPending || !selectedProjectId}
              className="w-full px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
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
          <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 border-b pb-4">
              <div>
                <dt className="text-sm font-medium text-gray-500">Total Revenue</dt>
                <dd className="text-xl font-semibold text-gray-900 tabular-nums">
                  {formatMoney(preview.total_revenue)}
                </dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-gray-500">Total expenses (including party-only)</dt>
                <dd className="text-xl font-semibold text-gray-900 tabular-nums">
                  {formatMoney(preview.total_expenses)}
                </dd>
                {(preview.expenses_included ?? preview.expenses_considered) && (
                  <div className="mt-2">
                    <Link
                      to={`/app/reports/general-ledger?from=${encodeURIComponent((preview.expenses_included ?? preview.expenses_considered)!.from)}&to=${encodeURIComponent((preview.expenses_included ?? preview.expenses_considered)!.to)}&project_id=${encodeURIComponent(selectedProjectId)}`}
                      className="text-sm text-[#1F6F5C] hover:underline font-medium"
                    >
                      View expense postings →
                    </Link>
                  </div>
                )}
              </div>
            </div>

            {(preview.expenses_included ?? preview.expenses_considered) && (
              <div className="border-b pb-4">
                <h3 className="text-sm font-medium text-[#2D3A3A] mb-2">Expenses included in this settlement</h3>
                {preview.expenses_included ? (
                  <div className="space-y-4">
                    <div className="flex justify-between items-baseline text-sm">
                      <span className="text-gray-600">Total:</span>
                      <span className="font-semibold text-gray-900 tabular-nums">{formatMoney(preview.expenses_included.total_expenses)}</span>
                    </div>
                    <div className="text-gray-500 text-xs mb-2">
                      {preview.expenses_included.posting_groups_count} posting(s) included
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                      <div className="bg-[#E6ECEA]/50 rounded-md p-3">
                        <div className="text-xs font-medium text-gray-500 mb-1">Pool (shared)</div>
                        <div className="font-semibold tabular-nums">{formatMoney(preview.expenses_included.shared_pool_expenses)}</div>
                        {preview.expenses_included.breakdown.pool.length > 0 && (
                          <ul className="mt-1 space-y-0.5 text-xs text-gray-700">
                            {preview.expenses_included.breakdown.pool.slice(0, 4).map((line, i) => (
                              <li key={i} className="flex justify-between"><span className="truncate pr-1">{line.label}</span><span className="shrink-0">{formatMoney(line.amount)}</span></li>
                            ))}
                          </ul>
                        )}
                      </div>
                      <div className="bg-[#E6ECEA]/50 rounded-md p-3">
                        <div className="text-xs font-medium text-gray-500 mb-1">Hari-only</div>
                        <div className="font-semibold tabular-nums">{formatMoney(preview.expenses_included.hari_only_deductions)}</div>
                        {preview.expenses_included.breakdown.hari_only.length > 0 && (
                          <ul className="mt-1 space-y-0.5 text-xs text-gray-700">
                            {preview.expenses_included.breakdown.hari_only.slice(0, 4).map((line, i) => (
                              <li key={i} className="flex justify-between"><span className="truncate pr-1">{line.label}</span><span className="shrink-0">{formatMoney(line.amount)}</span></li>
                            ))}
                          </ul>
                        )}
                      </div>
                      <div className="bg-[#E6ECEA]/50 rounded-md p-3">
                        <div className="text-xs font-medium text-gray-500 mb-1">Landlord-only</div>
                        <div className="font-semibold tabular-nums">{formatMoney(preview.expenses_included.landlord_only_costs)}</div>
                        {preview.expenses_included.breakdown.landlord_only.length > 0 && (
                          <ul className="mt-1 space-y-0.5 text-xs text-gray-700">
                            {preview.expenses_included.breakdown.landlord_only.slice(0, 4).map((line, i) => (
                              <li key={i} className="flex justify-between"><span className="truncate pr-1">{line.label}</span><span className="shrink-0">{formatMoney(line.amount)}</span></li>
                            ))}
                          </ul>
                        )}
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className="bg-[#E6ECEA]/50 rounded-md p-3 text-sm">
                    <div className="flex justify-between items-baseline mb-1">
                      <span className="text-gray-600">Total:</span>
                      <span className="font-semibold text-gray-900 tabular-nums">{formatMoney(preview.expenses_considered!.total)}</span>
                    </div>
                    <div className="text-gray-500 text-xs mb-2">
                      {preview.expenses_considered!.posting_groups_count} posting(s) included
                    </div>
                    {preview.expenses_considered!.lines.length > 0 && (
                      <div className="space-y-1">
                        {(expensesSectionExpanded ? preview.expenses_considered!.lines : preview.expenses_considered!.lines.slice(0, 6)).map((line, i) => (
                          <div key={i} className="flex justify-between tabular-nums">
                            <span className="text-gray-700 truncate pr-2">{line.label}</span>
                            <span className="text-gray-900 font-medium shrink-0">{formatMoney(line.amount)}</span>
                          </div>
                        ))}
                        {preview.expenses_considered!.lines.length > 6 && (
                          <button
                            type="button"
                            onClick={() => setExpensesSectionExpanded((e) => !e)}
                            className="text-[#1F6F5C] text-xs font-medium hover:underline mt-1"
                          >
                            {expensesSectionExpanded ? 'Show less' : `Show all ${preview.expenses_considered!.lines.length} lines`}
                          </button>
                        )}
                      </div>
                    )}
                  </div>
                )}
              </div>
            )}

            <div>
              <h3 className="text-sm font-medium text-gray-700 mb-2">Cost breakdown</h3>
              <p className="text-xs text-gray-500 mb-2" title={preview.adjustments_explainer}>
                Posted expenses by scope (pool / party-only). These are settlement-time adjustments. Posted expenses are shown above.
              </p>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <dt className="text-sm font-medium text-gray-500">Pool (shared) expenses</dt>
                  <dd className="text-lg font-semibold text-gray-900">
                    {(preview.shared_pool_expenses ?? preview.shared_costs) === 0 || (preview.shared_pool_expenses ?? preview.shared_costs) === '0' ? 'None' : formatMoney(preview.shared_pool_expenses ?? preview.shared_costs)}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Landlord-only costs</dt>
                  <dd className="text-lg font-semibold text-gray-900">
                    {(preview.landlord_only_costs === 0 || preview.landlord_only_costs === '0') ? 'None' : formatMoney(preview.landlord_only_costs)}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Hari-only deductions</dt>
                  <dd className="text-lg font-semibold text-gray-900">
                    {(Number(preview.hari_only_deductions) === 0 ? 'None' : formatMoney(preview.hari_only_deductions))}
                  </dd>
                </div>
              </div>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <dt className="text-sm font-medium text-gray-500">Pool Revenue</dt>
                <dd className="text-lg font-semibold text-gray-900 tabular-nums">{formatMoney(preview.pool_revenue)}</dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-gray-500">Pool Profit</dt>
                <dd className="text-lg font-semibold text-gray-900 tabular-nums">{formatMoney(preview.pool_profit)}</dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-gray-500">Kamdari Amount</dt>
                <dd className="text-lg font-semibold text-gray-900 tabular-nums">{formatMoney(preview.kamdari_amount)}</dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-gray-500">Landlord Gross</dt>
                <dd className="text-lg font-semibold text-gray-900 tabular-nums">{formatMoney(preview.landlord_gross)}</dd>
              </div>
              {preview.landlord_net != null && (
                <div>
                  <dt className="text-sm font-medium text-gray-500">Landlord Net</dt>
                  <dd className="text-lg font-semibold text-gray-900 tabular-nums">{formatMoney(preview.landlord_net)}</dd>
                </div>
              )}
              <div>
                <dt className="text-sm font-medium text-gray-500">HARI Gross</dt>
                <dd className="text-lg font-semibold text-gray-900 tabular-nums">{formatMoney(preview.hari_gross)}</dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-gray-500">HARI Net</dt>
                <dd className="text-lg font-semibold text-gray-900 tabular-nums">{formatMoney(preview.hari_net)}</dd>
              </div>
            </div>
            {(preview.hari_deficit != null && preview.hari_deficit > 0) && (
              <div className="mt-4 p-4 bg-amber-50 border border-amber-200 rounded-md">
                <p className="text-sm font-medium text-amber-800">
                  Hari deficit of {formatMoney(String(preview.hari_deficit))} — this will be offset against advances or carried forward.
                </p>
                <p className="text-xs text-amber-700 mt-1">No AR is auto-created; settlement posting is not blocked unless policy requires.</p>
              </div>
            )}
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
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
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
                  <span className="font-medium text-[#1F6F5C]">{offsetPreviewQuery.data.suggested_offset.toFixed(2)}</span>
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
                    className="mr-2 h-4 w-4 text-[#1F6F5C] focus:ring-[#1F6F5C] border-gray-300 rounded"
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
                    className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
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

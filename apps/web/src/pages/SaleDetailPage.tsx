import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useSale, useDeleteSale, usePostSale, useReverseSale } from '../hooks/useSales';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { PostButton } from '../components/PostButton';
import { ReverseButton } from '../components/ReverseButton';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';

export default function SaleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: sale, isLoading } = useSale(id || '');
  const deleteMutation = useDeleteSale();
  const postMutation = usePostSale();
  const reverseMutation = useReverseSale();
  const { hasRole } = useRole();
  const { formatMoney, formatDate } = useFormatting();
  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reversalDate, setReversalDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');
  const [idempotencyKey] = useState(uuidv4());

  const isDraft = sale?.status === 'DRAFT';
  const isPosted = sale?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const isCycleClosed = sale?.crop_cycle?.status === 'CLOSED';

  const handleDelete = async () => {
    if (!id) return;
    try {
      await deleteMutation.mutateAsync(id);
      navigate('/app/sales');
    } catch (error: any) {
      // Error handled by mutation
    }
  };

  const handlePost = async () => {
    if (!id) return;
    try {
      await postMutation.mutateAsync({
        id,
        payload: {
          posting_date: postingDate,
          idempotency_key: idempotencyKey,
        },
      });
      setShowPostModal(false);
    } catch (error: any) {
      // Error handled by mutation
    }
  };

  const handleReverse = async () => {
    if (!id) return;
    try {
      await reverseMutation.mutateAsync({
        id,
        payload: {
          reversal_date: reversalDate,
          reason: reverseReason || undefined,
        },
      });
      setShowReverseModal(false);
      setReverseReason('');
    } catch (error: any) {
      // Error handled by mutation
    }
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!sale) {
    return <div>Sale not found</div>;
  }

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/sales" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Sales
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">Sale Details</h1>
      </div>

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="text-sm text-gray-900">
              <span className={`px-2 py-1 rounded text-xs ${
                sale.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'
              }`}>
                {sale.status}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Buyer</dt>
            <dd className="text-sm text-gray-900">{sale.buyer_party?.name || sale.buyer_party_id}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Amount</dt>
            <dd className="text-sm text-gray-900"><span className="tabular-nums">{formatMoney(sale.amount)}</span></dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Posting Date</dt>
            <dd className="text-sm text-gray-900">{formatDate(sale.posting_date)}</dd>
          </div>
          {sale.project && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Project</dt>
              <dd className="text-sm text-gray-900">{sale.project.name}</dd>
            </div>
          )}
          {sale.crop_cycle && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Crop Cycle</dt>
              <dd className="text-sm text-gray-900">{sale.crop_cycle.name}</dd>
            </div>
          )}
          {sale.notes && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Notes</dt>
              <dd className="text-sm text-gray-900">{sale.notes}</dd>
            </div>
          )}
          {sale.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Posting Group</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/posting-groups/${sale.posting_group_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  {sale.posting_group_id}
                </Link>
              </dd>
            </div>
          )}
          {sale.posted_at && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Posted At</dt>
              <dd className="text-sm text-gray-900">{formatDateTime(sale.posted_at)}</dd>
            </div>
          )}
        </dl>
      </div>

      {/* Sale Lines */}
      {sale.lines && sale.lines.length > 0 && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Sale Lines</h2>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Store</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Line Total</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {sale.lines.map((line) => (
                  <tr key={line.id}>
                    <td className="px-4 py-2 text-sm text-gray-900">{line.item?.name || line.inventory_item_id}</td>
                    <td className="px-4 py-2 text-sm text-gray-900">{line.store?.name || line.store_id || '-'}</td>
                    <td className="px-4 py-2 text-sm text-gray-900 text-right">{line.quantity}</td>
                    <td className="px-4 py-2 text-sm text-gray-900 text-right tabular-nums"><span className="tabular-nums">{formatMoney(line.unit_price)}</span></td>
                    <td className="px-4 py-2 text-sm text-gray-900 text-right tabular-nums"><span className="tabular-nums">{formatMoney(line.line_total)}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Margin Information (only if posted) */}
      {sale.status === 'POSTED' && sale.inventory_allocations && sale.inventory_allocations.length > 0 && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Margin Analysis</h2>
          <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <dt className="text-sm font-medium text-gray-500">Revenue Total</dt>
              <dd className="text-lg font-semibold text-gray-900">
                <span className="tabular-nums">{formatMoney(sale.lines?.reduce((sum, line) => sum + parseFloat(line.line_total), 0) || sale.amount)}</span>
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">COGS Total</dt>
              <dd className="text-lg font-semibold text-gray-900">
                <span className="tabular-nums">{formatMoney(sale.inventory_allocations.reduce((sum, alloc) => sum + parseFloat(alloc.total_cost), 0))}</span>
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Gross Margin</dt>
              <dd className="text-lg font-semibold text-green-600">
                <span className="tabular-nums">{formatMoney(
                  (sale.lines?.reduce((sum, line) => sum + parseFloat(line.line_total), 0) || parseFloat(sale.amount)) -
                  sale.inventory_allocations.reduce((sum, alloc) => sum + parseFloat(alloc.total_cost), 0)
                )}</span>
              </dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Gross Margin %</dt>
              <dd className="text-lg font-semibold text-green-600">
                {(() => {
                  const revenue = sale.lines?.reduce((sum, line) => sum + parseFloat(line.line_total), 0) || parseFloat(sale.amount);
                  const cogs = sale.inventory_allocations.reduce((sum, alloc) => sum + parseFloat(alloc.total_cost), 0);
                  const margin = revenue - cogs;
                  const marginPct = revenue > 0 ? (margin / revenue) * 100 : 0;
                  return `${marginPct.toFixed(2)}%`;
                })()}
              </dd>
            </div>
          </dl>
        </div>
      )}

      {isDraft && (
        <div className="bg-white rounded-lg shadow p-6">
          <div className="flex justify-between items-center">
            <div>
              <h2 className="text-lg font-medium text-gray-900 mb-2">Actions</h2>
              <p className="text-sm text-gray-600">
                This sale is in DRAFT status. Post it to create accounting entries.
              </p>
            </div>
            <div className="flex space-x-4">
              <Link
                to={`/app/sales/${id}/edit`}
                className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
              >
                Edit
              </Link>
              {canPost && isDraft && (
                <PostButton
                  onClick={() => setShowPostModal(true)}
                  isCycleClosed={isCycleClosed}
                  cycleName={sale?.crop_cycle?.name}
                />
              )}
              <button
                onClick={() => setShowDeleteConfirm(true)}
                className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
              >
                Delete
              </button>
            </div>
          </div>
        </div>
      )}

      {isPosted && canPost && (
        <div className="bg-white rounded-lg shadow p-6">
          <div className="flex justify-between items-center">
            <div>
              <h2 className="text-lg font-medium text-gray-900 mb-2">Actions</h2>
              <p className="text-sm text-gray-600">
                This sale has been posted. You can reverse it to create offsetting accounting entries.
              </p>
            </div>
            <div className="flex space-x-4">
              <ReverseButton
                onClick={() => setShowReverseModal(true)}
                isCycleClosed={isCycleClosed}
                cycleName={sale?.crop_cycle?.name}
                isPending={reverseMutation.isPending}
              />
            </div>
          </div>
        </div>
      )}

      {showPostModal && (
        <Modal
          isOpen={showPostModal}
          title="Post Sale"
          onClose={() => setShowPostModal(false)}
        >
          <div className="space-y-4">
            {isCycleClosed && (
              <div className="bg-yellow-50 border border-yellow-200 rounded-md p-3 text-sm text-yellow-800">
                <strong>Cannot post:</strong> {sale?.crop_cycle?.name ? `Crop cycle "${sale.crop_cycle.name}" is closed.` : 'Crop cycle is closed.'} Posting is disabled for closed cycles.
              </div>
            )}
            {!isCycleClosed && (
              <div className="bg-orange-50 border border-orange-200 rounded-md p-3 text-sm text-orange-800">
                <strong>Warning:</strong> Posting this document will create accounting entries that cannot be modified. Only reversal is allowed.
              </div>
            )}
            <FormField label="Posting Date" required>
              <input
                type="date"
                value={postingDate}
                onChange={(e) => setPostingDate(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              />
            </FormField>
            <div className="bg-[#E6ECEA] border border-[#1F6F5C]/20 rounded-lg p-4">
              <p className="text-sm text-[#2D3A3A]">
                <strong>Note:</strong> Posting will create:
                <ul className="list-disc list-inside mt-2">
                  <li>Debit: Accounts Receivable (AR)</li>
                  <li>Credit: Project Revenue</li>
                  {sale.lines && sale.lines.length > 0 && (
                    <>
                      <li>Debit: Cost of Goods Sold (COGS)</li>
                      <li>Credit: Produce Inventory</li>
                      <li>Stock movements to reduce inventory</li>
                    </>
                  )}
                  <li>Allocation rows for revenue and COGS tracking</li>
                </ul>
                {sale.lines && sale.lines.length > 0 && (
                  <p className="mt-2 text-xs">
                    <strong>Warning:</strong> Posting requires sufficient inventory stock for all sale lines.
                  </p>
                )}
              </p>
            </div>
            <div className="flex justify-end space-x-4 pt-4">
              <button
                onClick={() => setShowPostModal(false)}
                className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={handlePost}
                disabled={postMutation.isPending || isCycleClosed}
                className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {postMutation.isPending ? 'Posting...' : 'Post Sale'}
              </button>
            </div>
          </div>
        </Modal>
      )}

      {showReverseModal && (
        <Modal
          isOpen={showReverseModal}
          title="Reverse Sale"
          onClose={() => {
            setShowReverseModal(false);
            setReverseReason('');
          }}
        >
          <div className="space-y-4">
            {isCycleClosed && (
              <div className="bg-yellow-50 border border-yellow-200 rounded-md p-3 text-sm text-yellow-800">
                <strong>Cannot reverse:</strong> {sale?.crop_cycle?.name ? `Crop cycle "${sale.crop_cycle.name}" is closed.` : 'Crop cycle is closed.'} Reversals are disabled for closed cycles.
              </div>
            )}
            {!isCycleClosed && (
              <div className="bg-red-50 border border-red-200 rounded-md p-3 text-sm text-red-800">
                <strong>Warning:</strong> Reversing this sale will create offsetting accounting entries. This action cannot be undone.
              </div>
            )}
            <FormField label="Reversal Date" required>
              <input
                type="date"
                value={reversalDate}
                onChange={(e) => setReversalDate(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              />
            </FormField>
            <FormField label="Reason (optional)">
              <textarea
                value={reverseReason}
                onChange={(e) => setReverseReason(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                rows={3}
                placeholder="Reason for reversal"
              />
            </FormField>
            <div className="bg-[#E6ECEA] border border-[#1F6F5C]/20 rounded-lg p-4">
              <p className="text-sm text-[#2D3A3A]">
                <strong>Note:</strong> Reversing will create offsetting entries:
                <ul className="list-disc list-inside mt-2">
                  <li>Credit: Accounts Receivable (AR)</li>
                  <li>Debit: Project Revenue</li>
                  {sale.lines && sale.lines.length > 0 && (
                    <>
                      <li>Credit: Cost of Goods Sold (COGS)</li>
                      <li>Debit: Produce Inventory</li>
                      <li>Stock movements to restore inventory</li>
                    </>
                  )}
                </ul>
              </p>
            </div>
            <div className="flex justify-end space-x-4 pt-4">
              <button
                onClick={() => {
                  setShowReverseModal(false);
                  setReverseReason('');
                }}
                className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={handleReverse}
                disabled={reverseMutation.isPending || isCycleClosed}
                className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {reverseMutation.isPending ? 'Reversing...' : 'Reverse Sale'}
              </button>
            </div>
          </div>
        </Modal>
      )}

      {showDeleteConfirm && (
        <ConfirmDialog
          isOpen={showDeleteConfirm}
          onClose={() => setShowDeleteConfirm(false)}
          title="Delete Sale"
          message="Are you sure you want to delete this sale? This action cannot be undone."
          onConfirm={handleDelete}
          confirmText="Delete"
          variant="danger"
        />
      )}
    </div>
  );
}

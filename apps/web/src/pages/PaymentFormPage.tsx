import { useState, useEffect } from 'react';
import { useParams, Link, useNavigate, useSearchParams } from 'react-router-dom';
import { usePayment, useCreatePayment, useUpdatePayment } from '../hooks/usePayments';
import { useParties, usePartyBalanceSummary } from '../hooks/useParties';
import { paymentsApi } from '../api/payments';
import { useQuery } from '@tanstack/react-query';
import { FormField } from '../components/FormField';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import type { CreatePaymentPayload, PaymentDirection, PaymentMethod, AllocationPreview, OpenSale } from '../types';

export default function PaymentFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const isEdit = !!id;
  const { data: payment, isLoading } = usePayment(id || '');
  const createMutation = useCreatePayment();
  const updateMutation = useUpdatePayment();
  const { data: parties } = useParties();
  const { hasRole } = useRole();
  const { formatMoney, formatDate } = useFormatting();
  
  // Get query params for prefill
  const prefilledPartyId = searchParams.get('partyId') || searchParams.get('party_id');
  const prefilledDirection = searchParams.get('direction') as PaymentDirection | null;
  const prefilledPurpose = (searchParams.get('purpose') as 'GENERAL' | 'WAGES') || 'GENERAL';
  const prefilledAmount = searchParams.get('amount') || '';

  const [formData, setFormData] = useState<CreatePaymentPayload>({
    direction: prefilledDirection || 'OUT',
    party_id: prefilledPartyId || '',
    amount: prefilledAmount,
    payment_date: new Date().toISOString().split('T')[0],
    method: 'CASH',
    reference: '',
    settlement_id: '',
    notes: '',
    purpose: prefilledPurpose === 'WAGES' ? 'WAGES' : 'GENERAL',
  });

  // Get balances for selected party (for OUT payable validation; skip when purpose=WAGES — labour uses lab_worker_balances)
  const selectedPartyId = formData.party_id || prefilledPartyId || '';
  const isWages = formData.direction === 'OUT' && formData.purpose === 'WAGES';
  const { data: balances } = usePartyBalanceSummary(
    isWages ? '' : selectedPartyId,
    undefined
  );

  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [allocationMode, setAllocationMode] = useState<'FIFO' | 'MANUAL'>('FIFO');
  const [manualAllocations, setManualAllocations] = useState<Record<string, string>>({});

  // Fetch allocation preview for Payment IN
  const shouldFetchPreview = formData.direction === 'IN' && 
    selectedPartyId && 
    formData.amount && 
    parseFloat(String(formData.amount)) > 0 && 
    formData.payment_date;

  const { data: allocationPreview, isLoading: previewLoading } = useQuery<AllocationPreview>({
    queryKey: ['payment-allocation-preview', selectedPartyId, formData.amount, formData.payment_date],
    queryFn: () => paymentsApi.getAllocationPreview(
      selectedPartyId,
      String(formData.amount),
      formData.payment_date
    ),
    enabled: Boolean(shouldFetchPreview) && !isEdit,
  });

  useEffect(() => {
    if (payment && isEdit) {
      setFormData({
        direction: payment.direction,
        party_id: payment.party_id,
        amount: payment.amount,
        payment_date: payment.payment_date,
        method: payment.method,
        reference: payment.reference || '',
        settlement_id: payment.settlement_id || '',
        notes: payment.notes || '',
        purpose: ((payment as { purpose?: string }).purpose === 'WAGES' ? 'WAGES' : 'GENERAL') as 'GENERAL' | 'WAGES',
      });
    } else if (!isEdit && (prefilledPartyId || prefilledDirection || prefilledPurpose || prefilledAmount)) {
      setFormData((prev) => ({
        ...prev,
        party_id: prefilledPartyId || prev.party_id,
        direction: prefilledDirection || prev.direction,
        purpose: prefilledPurpose === 'WAGES' ? 'WAGES' : prev.purpose || 'GENERAL',
        amount: prefilledAmount || prev.amount,
      }));
    }
  }, [payment, isEdit, prefilledPartyId, prefilledDirection, prefilledPurpose, prefilledAmount]);

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {};

    if (!formData.payment_date) newErrors.payment_date = 'Payment date is required';
    if (!formData.party_id) newErrors.party_id = 'Party is required';
    if (!formData.amount || parseFloat(String(formData.amount)) <= 0) {
      newErrors.amount = 'Valid amount is required';
    }

    // Validation: prevent overpayment (skip OUT when purpose=WAGES — API validates against lab_worker_balances)
    if (formData.amount && formData.party_id) {
      const amount = parseFloat(String(formData.amount));
      if (formData.direction === 'OUT' && formData.purpose !== 'WAGES') {
        const outstandingPayable = parseFloat(balances?.outstanding_total || '0');
        if (amount > outstandingPayable) {
          newErrors.amount = `Amount exceeds outstanding payable (${formatMoney(outstandingPayable)}). Extra becomes an Advance (Phase 5). Create an Advance instead.`;
        }
      } else if (formData.direction === 'IN') {
        // Phase 7: Payment IN must clear receivables
        const outstandingReceivable = parseFloat(balances?.receivable_balance || '0');
        if (outstandingReceivable <= 0) {
          newErrors.amount = 'Party has no outstanding receivable balance. Create a Sale first.';
        } else if (amount > outstandingReceivable) {
          newErrors.amount = `Amount exceeds outstanding receivable (${formatMoney(outstandingReceivable)}).`;
        }
      }
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async () => {
    if (!validateForm()) return;

    try {
      const payload: CreatePaymentPayload = {
        ...formData,
        settlement_id: formData.settlement_id || undefined,
        reference: formData.reference || undefined,
        notes: formData.notes || undefined,
        purpose: formData.purpose || 'GENERAL',
      };

      if (isEdit && id) {
        await updateMutation.mutateAsync({ id, payload });
      } else {
        await createMutation.mutateAsync(payload);
      }
      navigate('/app/payments');
    } catch (error: any) {
      // Error handled by mutation
    }
  };

  // Initialize manual allocations from preview when switching to MANUAL mode
  useEffect(() => {
    if (allocationMode === 'MANUAL' && allocationPreview?.suggested_allocations) {
      const initial: Record<string, string> = {};
      allocationPreview.suggested_allocations.forEach((alloc: { sale_id: string; amount: string }) => {
        initial[alloc.sale_id] = alloc.amount;
      });
      setManualAllocations(initial);
    } else if (allocationMode === 'FIFO') {
      setManualAllocations({});
    }
  }, [allocationMode, allocationPreview]);

  if (isLoading && isEdit) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (isEdit && payment?.status !== 'DRAFT') {
    return (
      <div>
        <p className="text-red-600">This payment cannot be edited because it is not in DRAFT status.</p>
        <Link to="/app/payments" className="text-[#1F6F5C] hover:text-[#1a5a4a]">
          Back to Payments
        </Link>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/payments" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Payments
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">
          {isEdit ? 'Edit Payment' : 'New Payment'}
        </h1>
      </div>

      <div className="bg-white rounded-lg shadow p-6">
        <div className="space-y-4">
          <FormField label="Direction" required>
            <select
              value={formData.direction}
              onChange={(e) => {
                const newDirection = e.target.value as PaymentDirection;
                setFormData({ ...formData, direction: newDirection });
                // Clear amount error when direction changes
                if (errors.amount) {
                  setErrors((prev) => {
                    const newErrors = { ...prev };
                    delete newErrors.amount;
                    return newErrors;
                  });
                }
              }}
              disabled={!canEdit || isEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="OUT">OUT</option>
              <option value="IN">IN</option>
            </select>
          </FormField>

          {formData.direction === 'OUT' && (
            <FormField label="Purpose">
              <select
                value={formData.purpose || 'GENERAL'}
                onChange={(e) => setFormData({ ...formData, purpose: e.target.value as 'GENERAL' | 'WAGES' })}
                disabled={!canEdit || isEdit}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
              >
                <option value="GENERAL">General (settlement/advance)</option>
                <option value="WAGES">Wages</option>
              </select>
            </FormField>
          )}

          {formData.direction === 'IN' && selectedPartyId && (
            <>
              <div className={`rounded-lg p-4 mb-4 ${
                parseFloat(balances?.receivable_balance || '0') > 0
                  ? 'bg-[#E6ECEA] border border-[#1F6F5C]/20'
                  : 'bg-yellow-50 border border-yellow-200'
              }`}>
                {parseFloat(balances?.receivable_balance || '0') > 0 ? (
                  <p className="text-sm text-[#2D3A3A]">
                    <strong>Outstanding receivable:</strong> <span className="tabular-nums">{formatMoney(balances?.receivable_balance || '0')}</span>
                    <br />
                    <span className="text-xs">Sales: <span className="tabular-nums">{formatMoney(balances?.receivable_sales_total || '0')}</span> | Payments Received: <span className="tabular-nums">{formatMoney(balances?.receivable_payments_in_total || '0')}</span></span>
                  </p>
                ) : (
                  <div className="text-sm text-yellow-800">
                    <p className="mb-2">
                      <strong>No outstanding receivable balance.</strong> Create a Sale first to record revenue and create a receivable.
                    </p>
                    <Link
                      to={`/app/sales/new?buyerPartyId=${selectedPartyId}`}
                      className="inline-block px-3 py-1 bg-yellow-600 text-white rounded hover:bg-yellow-700 text-sm font-medium"
                    >
                      Create Sale
                    </Link>
                  </div>
                )}
              </div>

              {!isEdit && formData.amount && parseFloat(String(formData.amount)) > 0 && formData.payment_date && (
                <div className="bg-white border border-gray-200 rounded-lg p-4 mb-4">
                  <div className="flex justify-between items-center mb-4">
                    <h4 className="text-sm font-medium text-gray-900">Payment Allocation</h4>
                    <div className="flex items-center space-x-4">
                      <label className="flex items-center">
                        <input
                          type="radio"
                          checked={allocationMode === 'FIFO'}
                          onChange={() => setAllocationMode('FIFO')}
                          className="mr-2"
                        />
                        <span className="text-sm">FIFO (Auto)</span>
                      </label>
                      <label className="flex items-center">
                        <input
                          type="radio"
                          checked={allocationMode === 'MANUAL'}
                          onChange={() => setAllocationMode('MANUAL')}
                          className="mr-2"
                        />
                        <span className="text-sm">Manual</span>
                      </label>
                    </div>
                  </div>

                  {previewLoading ? (
                    <LoadingSpinner size="sm" />
                  ) : allocationPreview ? (
                    <div className="space-y-3">
                      {allocationMode === 'FIFO' ? (
                        <div>
                          <p className="text-xs text-gray-600 mb-2">Suggested allocations (FIFO):</p>
                          <div className="space-y-2">
                            {allocationPreview.suggested_allocations.map((alloc: { sale_id: string; sale_no?: string; posting_date: string; due_date: string; outstanding: string; amount: string }) => (
                              <div key={alloc.sale_id} className="flex justify-between text-sm bg-gray-50 p-2 rounded">
                                <span>
                                  {alloc.sale_no || 'Sale'} ({formatDate(alloc.posting_date)}) - Outstanding: <span className="tabular-nums">{formatMoney(alloc.outstanding)}</span>
                                </span>
                                <span className="font-medium"><span className="tabular-nums">{formatMoney(alloc.amount)}</span></span>
                              </div>
                            ))}
                            {parseFloat(allocationPreview.unallocated_amount) > 0 && (
                              <p className="text-xs text-red-600 mt-2">
                                Warning: <span className="tabular-nums">{formatMoney(allocationPreview.unallocated_amount)}</span> will remain unallocated (exceeds receivable)
                              </p>
                            )}
                          </div>
                        </div>
                      ) : (
                        <div>
                          <p className="text-xs text-gray-600 mb-2">Manual allocation:</p>
                          <div className="space-y-2 max-h-64 overflow-y-auto">
                            {allocationPreview.open_sales.map((sale: OpenSale) => (
                              <div key={sale.sale_id} className="flex items-center space-x-4 bg-gray-50 p-2 rounded">
                                <div className="flex-1 text-sm">
                                  <div className="font-medium">{sale.sale_no || 'Sale'} ({formatDate(sale.posting_date)})</div>
                                  <div className="text-xs text-gray-500">
                                    Outstanding: <span className="tabular-nums">{formatMoney(sale.outstanding)}</span>
                                  </div>
                                </div>
                                <div className="w-32">
                                  <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max={sale.outstanding}
                                    value={manualAllocations[sale.sale_id] || '0'}
                                    onChange={(e) => {
                                      const value = e.target.value;
                                      setManualAllocations((prev) => ({
                                        ...prev,
                                        [sale.sale_id]: value,
                                      }));
                                    }}
                                    className="w-full px-2 py-1 border border-gray-300 rounded text-sm"
                                    placeholder="0.00"
                                  />
                                </div>
                              </div>
                            ))}
                          </div>
                          <div className="mt-3 pt-3 border-t border-gray-200">
                            <div className="flex justify-between text-sm">
                              <span>Total Allocated:</span>
                              <span className="font-medium">
                                <span className="tabular-nums">{formatMoney(
                                  Object.values(manualAllocations).reduce((sum, val) => sum + parseFloat(val || '0'), 0)
                                )}</span>
                              </span>
                            </div>
                            <div className="flex justify-between text-sm mt-1">
                              <span>Payment Amount:</span>
                              <span className="font-medium"><span className="tabular-nums">{formatMoney(formData.amount)}</span></span>
                            </div>
                            {Math.abs(
                              Object.values(manualAllocations).reduce((sum, val) => sum + parseFloat(val || '0'), 0) -
                              parseFloat(String(formData.amount))
                            ) > 0.01 && (
                              <p className="text-xs text-red-600 mt-2">
                                Total allocated must equal payment amount
                              </p>
                            )}
                          </div>
                        </div>
                      )}
                    </div>
                  ) : (
                    <p className="text-sm text-gray-500">Enter amount and date to see allocation preview</p>
                  )}
                </div>
              )}
            </>
          )}

          <FormField label="Party" required error={errors.party_id}>
            <select
              value={formData.party_id}
              onChange={(e) => {
                setFormData({ ...formData, party_id: e.target.value });
                if (errors.party_id) {
                  setErrors((prev) => {
                    const newErrors = { ...prev };
                    delete newErrors.party_id;
                    return newErrors;
                  });
                }
              }}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="">Select a party</option>
              {parties?.map((party) => (
                <option key={party.id} value={party.id}>
                  {party.name}
                </option>
              ))}
            </select>
          </FormField>

          {formData.party_id && balances && formData.direction === 'OUT' && formData.purpose !== 'WAGES' && (
            <div className="bg-[#E6ECEA] border border-[#1F6F5C]/20 rounded-lg p-4 mb-4">
              <p className="text-sm text-[#2D3A3A]">
                <strong>Outstanding Payable:</strong> <span className="tabular-nums">{formatMoney(balances.outstanding_total || '0')}</span>
              </p>
              {parseFloat(balances.outstanding_total || '0') > 0 && (
                <p className="text-xs text-[#1F6F5C] mt-1">
                  You can pay up to <span className="tabular-nums">{formatMoney(balances.outstanding_total)}</span>
                </p>
              )}
            </div>
          )}
          {formData.direction === 'OUT' && formData.purpose === 'WAGES' && (
            <div className="bg-[#E6ECEA] border border-[#1F6F5C]/20 rounded-lg p-4 mb-4">
              <p className="text-sm text-[#2D3A3A]">Wage payment. Party must be linked to a worker. Amount is validated on post.</p>
            </div>
          )}

          <FormField label="Amount" required error={errors.amount}>
            <input
              type="number"
              step="0.01"
              min="0.01"
              value={formData.amount}
              onChange={(e) => {
                setFormData({ ...formData, amount: e.target.value });
                if (errors.amount) {
                  setErrors((prev) => {
                    const newErrors = { ...prev };
                    delete newErrors.amount;
                    return newErrors;
                  });
                }
              }}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
            {formData.amount && formData.party_id && formData.direction === 'OUT' && formData.purpose !== 'WAGES' && (() => {
              const amount = parseFloat(String(formData.amount));
              const outstandingPayable = parseFloat(balances?.outstanding_total || '0');
              if (amount > outstandingPayable) {
                return (
                  <div className="mt-2">
                    <Link to={`/app/advances/new?partyId=${formData.party_id}&type=HARI_ADVANCE&direction=OUT`} className="text-[#1F6F5C] hover:text-[#1a5a4a] underline text-sm">Create Advance instead</Link>
                  </div>
                );
              }
              return null;
            })()}
          </FormField>

          <FormField label="Payment Date" required error={errors.payment_date}>
            <input
              type="date"
              value={formData.payment_date}
              onChange={(e) => setFormData({ ...formData, payment_date: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
          </FormField>

          <FormField label="Method" required>
            <select
              value={formData.method}
              onChange={(e) => setFormData({ ...formData, method: e.target.value as PaymentMethod })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            >
              <option value="CASH">CASH</option>
              <option value="BANK">BANK</option>
            </select>
          </FormField>

          <FormField label="Reference">
            <input
              type="text"
              value={formData.reference}
              onChange={(e) => setFormData({ ...formData, reference: e.target.value })}
              disabled={!canEdit}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
          </FormField>

          <FormField label="Settlement ID (Optional)">
            <input
              type="text"
              value={formData.settlement_id}
              onChange={(e) => setFormData({ ...formData, settlement_id: e.target.value })}
              disabled={!canEdit}
              placeholder="UUID of settlement (optional)"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
          </FormField>

          <FormField label="Notes">
            <textarea
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              disabled={!canEdit}
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100"
            />
          </FormField>

          {canEdit && (
            <div className="flex justify-end space-x-4 pt-4">
              <Link
                to="/app/payments"
                className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </Link>
              <button
                onClick={handleSubmit}
                disabled={createMutation.isPending || updateMutation.isPending}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
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

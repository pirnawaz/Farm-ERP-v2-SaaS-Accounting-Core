import { useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { PageContainer } from '../components/PageContainer';
import { PageHeader } from '../components/PageHeader';
import toast from 'react-hot-toast';

export default function SupplierCreditNoteNewPage() {
  const [search] = useSearchParams();
  const navigate = useNavigate();
  const partyId = search.get('party_id') || '';
  const supplierInvoiceId = search.get('supplier_invoice_id') || '';

  const [creditDate, setCreditDate] = useState(() => new Date().toISOString().split('T')[0]);
  const [totalAmount, setTotalAmount] = useState('');
  const [referenceNo, setReferenceNo] = useState('');
  const [postingDate, setPostingDate] = useState(() => new Date().toISOString().split('T')[0]);

  const canSubmit = useMemo(() => {
    return partyId && supplierInvoiceId && creditDate && parseFloat(totalAmount) > 0;
  }, [partyId, supplierInvoiceId, creditDate, totalAmount]);

  const createM = useMutation({
    mutationFn: async () => {
      const body = {
        party_id: partyId,
        supplier_invoice_id: supplierInvoiceId,
        credit_date: creditDate,
        total_amount: parseFloat(totalAmount),
        reference_no: referenceNo || null,
      };
      const created = await apiClient.post<{ id: string }>('/api/supplier-credit-notes', body);
      const pg = await apiClient.post<{ id: string }>(`/api/supplier-credit-notes/${created.id}/post`, {
        posting_date: postingDate,
        idempotency_key: crypto.randomUUID(),
      });
      return { created, pg };
    },
    onSuccess: () => {
      toast.success('Credit note posted');
      navigate(supplierInvoiceId ? `/app/accounting/supplier-invoices/${supplierInvoiceId}` : '/app/accounting/bills');
    },
    onError: (e: Error) => toast.error(e.message || 'Failed'),
  });

  if (!partyId || !supplierInvoiceId) {
    return (
      <PageContainer>
        <PageHeader title="New supplier credit note" backTo="/app/accounting/bills" breadcrumbs={[{ label: 'Accounting' }, { label: 'Credit note' }]} />
        <p className="text-sm text-gray-600">
          Open this screen from a bill detail page using <strong>Record supplier credit</strong>, or append{' '}
          <code className="text-xs bg-gray-100 px-1 rounded">party_id</code> and{' '}
          <code className="text-xs bg-gray-100 px-1 rounded">supplier_invoice_id</code> to the URL.
        </p>
        <Link to="/app/accounting/bills" className="text-[#1F6F5C] hover:underline text-sm mt-4 inline-block">
          ← Bills
        </Link>
      </PageContainer>
    );
  }

  return (
    <PageContainer className="space-y-6 max-w-lg">
      <PageHeader
        title="New supplier credit note"
        backTo={`/app/accounting/supplier-invoices/${supplierInvoiceId}`}
        breadcrumbs={[
          { label: 'Accounting', to: '/app/accounting/bills' },
          { label: 'Bill', to: `/app/accounting/supplier-invoices/${supplierInvoiceId}` },
          { label: 'Credit note' },
        ]}
      />
      <p className="text-sm text-gray-600">
        Creates a draft credit linked to this bill, then posts it (Dr AP, Cr inputs expense). The original bill is not
        modified.
      </p>
      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <div>
          <label className="block text-xs font-medium text-gray-600 mb-1">Credit date</label>
          <input type="date" className="w-full border rounded px-3 py-2 text-sm" value={creditDate} onChange={(e) => setCreditDate(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs font-medium text-gray-600 mb-1">Posting date</label>
          <input type="date" className="w-full border rounded px-3 py-2 text-sm" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs font-medium text-gray-600 mb-1">Amount</label>
          <input
            type="number"
            min="0.01"
            step="0.01"
            className="w-full border rounded px-3 py-2 text-sm tabular-nums"
            value={totalAmount}
            onChange={(e) => setTotalAmount(e.target.value)}
            placeholder="0.00"
          />
        </div>
        <div>
          <label className="block text-xs font-medium text-gray-600 mb-1">Reference (optional)</label>
          <input className="w-full border rounded px-3 py-2 text-sm" value={referenceNo} onChange={(e) => setReferenceNo(e.target.value)} />
        </div>
        <button
          type="button"
          disabled={!canSubmit || createM.isPending}
          onClick={() => createM.mutate()}
          className="w-full rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#185647] disabled:opacity-50"
        >
          {createM.isPending ? 'Posting…' : 'Create and post'}
        </button>
      </div>
    </PageContainer>
  );
}

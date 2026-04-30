import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { apiClient } from '@farm-erp/shared';
import { PageContainer } from '../components/PageContainer';
import { PageHeader } from '../components/PageHeader';
import { useFormatting } from '../hooks/useFormatting';
import { useRole } from '../hooks/useRole';
import { useTenantSettings } from '../hooks/useTenantSettings';
import type { SupplierInvoiceDetail, SupplierStatementResponse } from '../types';
import toast from 'react-hot-toast';
import { supplierInvoicePoMatchesApi, type SupplierInvoiceLinePoMatch } from '../lib/api/procurement/supplierInvoicePoMatches';
import { purchaseOrdersApi, type PurchaseOrderMatchingLine } from '../lib/api/procurement/purchaseOrders';

export default function SupplierInvoiceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const { formatMoney, formatDate } = useFormatting();
  const { hasRole } = useRole();
  const { settings } = useTenantSettings();
  const [postingDate, setPostingDate] = useState(() => new Date().toISOString().slice(0, 10));

  const { data: invoice, isLoading, error } = useQuery({
    queryKey: ['supplier-invoice', id],
    queryFn: () => apiClient.get<SupplierInvoiceDetail>(`/api/supplier-invoices/${id}`),
    enabled: !!id,
  });

  const postM = useMutation({
    mutationFn: () =>
      apiClient.post<{ id: string }>(`/api/supplier-invoices/${id}/post`, {
        posting_date: postingDate,
        idempotency_key: crypto.randomUUID(),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['supplier-invoice', id] });
      queryClient.invalidateQueries({ queryKey: ['supplier-invoices'] });
      toast.success('Bill posted');
    },
    onError: (e: Error) => toast.error(e.message || 'Post failed'),
  });

  const functionalCc = (settings?.currency_code || 'GBP').toUpperCase();
  const txCc = (invoice?.currency_code || functionalCc).toUpperCase();
  const isForeign = invoice ? txCc !== functionalCc : false;

  const partyId = invoice?.party_id;

  const [poId, setPoId] = useState('');
  const [poLines, setPoLines] = useState<PurchaseOrderMatchingLine[]>([]);
  const [poLoading, setPoLoading] = useState(false);
  const [poMatches, setPoMatches] = useState<SupplierInvoiceLinePoMatch[]>([]);
  const [poMatchDraft, setPoMatchDraft] = useState<Record<string, { purchase_order_line_id: string; matched_qty: string; matched_amount: string }>>({});

  const { data: poMatchesRes } = useQuery({
    queryKey: ['supplier-invoice-po-matches', id],
    queryFn: () => supplierInvoicePoMatchesApi.get(id!),
    enabled: !!id,
  });

  useEffect(() => {
    if (!poMatchesRes) return;
    setPoMatches(poMatchesRes.matches || []);
    const next: Record<string, { purchase_order_line_id: string; matched_qty: string; matched_amount: string }> = {};
    (poMatchesRes.matches || []).forEach((m) => {
      next[m.supplier_invoice_line_id] = {
        purchase_order_line_id: m.purchase_order_line_id,
        matched_qty: String(m.matched_qty),
        matched_amount: String(m.matched_amount),
      };
    });
    setPoMatchDraft(next);
  }, [poMatchesRes]);

  const { data: statement } = useQuery({
    queryKey: ['supplier-statement', partyId, id],
    queryFn: () => {
      const params = new URLSearchParams();
      const to = new Date().toISOString().split('T')[0];
      const from = new Date(new Date().setFullYear(new Date().getFullYear() - 1)).toISOString().split('T')[0];
      params.set('from', from);
      params.set('to', to);
      return apiClient.get<SupplierStatementResponse>(`/api/parties/${partyId}/supplier-statement?${params.toString()}`);
    },
    enabled: !!partyId && !!invoice,
  });

  if (!id) {
    return null;
  }

  if (isLoading) {
    return (
      <PageContainer>
        <div className="text-gray-600 p-4">Loading…</div>
      </PageContainer>
    );
  }

  if (error || !invoice) {
    return (
      <PageContainer>
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{error instanceof Error ? error.message : 'Not found'}</p>
          <Link to="/app/accounting/supplier-invoices" className="text-[#1F6F5C] hover:underline mt-2 inline-block">
            ← Back to supplier bills / invoices
          </Link>
        </div>
      </PageContainer>
    );
  }

  const isFarmBill = invoice.billing_scope === 'farm_overhead';
  const detailTitle = invoice.reference_no
    ? `${isFarmBill ? 'Bill' : 'Supplier bill / invoice'} · ${invoice.reference_no}`
    : isFarmBill
      ? 'Bill'
      : 'Supplier bill / invoice';

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title={detailTitle}
        backTo={isFarmBill ? '/app/accounting/bills' : '/app/accounting/supplier-invoices'}
        breadcrumbs={[
          { label: 'Reports', to: '/app/reports' },
          isFarmBill
            ? { label: 'Bills', to: '/app/accounting/bills' }
            : { label: 'Supplier bills / invoices', to: '/app/accounting/supplier-invoices' },
          { label: 'Detail' },
        ]}
      />

      {(invoice.status === 'POSTED' || invoice.status === 'PAID') && (
        <div
          className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
          role="status"
        >
          This invoice is <strong>{invoice.status}</strong> — details are read-only in the app. Changes require
          support workflows; posting or edits are enforced on the server.
        </div>
      )}

      <div className="flex flex-wrap gap-4 text-sm text-gray-600">
        <span>
          Status: <strong className="text-gray-900">{invoice.status}</strong>
        </span>
        {invoice.billing_scope && (
          <span>
            Belongs to:{' '}
            <strong className="text-gray-900">
              {invoice.billing_scope === 'farm_overhead'
                ? 'Cost center (farm overhead)'
                : invoice.billing_scope === 'project'
                  ? 'Project'
                  : 'Not set'}
            </strong>
          </span>
        )}
        {invoice.party && (
          <span>
            Supplier:{' '}
            <Link to={`/app/parties/${invoice.party_id}`} className="text-[#1F6F5C] font-semibold hover:underline">
              {invoice.party.name}
            </Link>
          </span>
        )}
        {invoice.cost_center && (
          <span>
            Cost center:{' '}
            <strong className="text-gray-900">
              {invoice.cost_center.name}
              {invoice.cost_center.code ? ` (${invoice.cost_center.code})` : ''}
            </strong>
          </span>
        )}
        {invoice.project?.name && (
          <span>
            Project: <strong className="text-gray-900">{invoice.project.name}</strong>
          </span>
        )}
        {invoice.due_date && (
          <span>
            Due: <strong className="text-gray-900">{formatDate(invoice.due_date)}</strong>
          </span>
        )}
        {invoice.payment_terms && (
          <span>
            Terms: <strong className="text-gray-900">{invoice.payment_terms}</strong>
          </span>
        )}
        <span>
          Transaction currency: <strong className="text-gray-900">{txCc}</strong>
        </span>
        <span>
          Functional (reporting): <strong className="text-gray-900">{functionalCc}</strong>
          {isForeign && invoice.posting_group && (
            <span className="text-gray-500"> — GL uses rate at post date for {functionalCc} equivalents</span>
          )}
        </span>
        {invoice.posting_group?.posting_date && (
          <span>
            Posted: <strong className="text-gray-900">{formatDate(invoice.posting_group.posting_date)}</strong>
          </span>
        )}
        {invoice.posting_group_id && (
          <span>
            <Link to={`/app/posting-groups/${invoice.posting_group_id}`} className="text-[#1F6F5C] hover:underline">
              View posting group
            </Link>
          </span>
        )}
      </div>

      {invoice.status === 'DRAFT' && !invoice.grn_id && (
        <section className="bg-white rounded-lg shadow p-6 space-y-4">
          <h2 className="text-lg font-semibold">Post to accounts</h2>
          <p className="text-sm text-gray-600">
            Posting creates the posting group, allocation rows, and ledger entries. This cannot be undone from the UI.
          </p>
          <div className="flex flex-wrap items-end gap-4">
            <div>
              <label htmlFor="post-date" className="block text-xs font-medium text-gray-600 mb-1">
                Posting date
              </label>
              <input
                id="post-date"
                type="date"
                className="rounded border border-gray-300 px-3 py-2 text-sm"
                value={postingDate}
                onChange={(e) => setPostingDate(e.target.value)}
              />
            </div>
            <button
              type="button"
              disabled={postM.isPending}
              onClick={() => postM.mutate()}
              className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#185647] disabled:opacity-50"
            >
              {postM.isPending ? 'Posting…' : 'Post bill'}
            </button>
            <Link
              to={
                isFarmBill
                  ? `/app/accounting/bills/${invoice.id}/edit`
                  : `/app/accounting/supplier-invoices/${invoice.id}/edit`
              }
              className="text-sm text-gray-700 underline hover:text-gray-900"
            >
              Edit draft
            </Link>
          </div>
        </section>
      )}

      <section className="bg-white rounded-lg shadow p-6">
        <h2 className="text-lg font-semibold mb-1">Amounts</h2>
        <p className="text-sm text-gray-600 mb-4">
          Figures below are in <strong>{txCc}</strong> (document / transaction currency). Posted ledger entries also
          carry <strong>{functionalCc}</strong> base amounts for reporting.
        </p>
        {invoice.payment_terms === 'CREDIT' ? (
          <dl className="grid grid-cols-1 sm:grid-cols-4 gap-4 text-sm">
            <div>
              <dt className="text-gray-500">Cash amount (base)</dt>
              <dd className="mt-1 font-medium tabular-nums">
                {(() => {
                  const v = (invoice.lines || []).reduce((acc, l) => acc + (parseFloat(String(l.base_cash_amount ?? 0)) || 0), 0);
                  return formatMoney(v.toFixed(2));
                })()}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500">Credit premium</dt>
              <dd className="mt-1 font-semibold tabular-nums text-amber-900">
                {(() => {
                  const v = (invoice.lines || []).reduce((acc, l) => acc + (parseFloat(String(l.credit_premium_amount ?? 0)) || 0), 0);
                  return formatMoney(v.toFixed(2));
                })()}
              </dd>
            </div>
            <div>
              <dt className="text-gray-500">Tax</dt>
              <dd className="mt-1 font-medium tabular-nums">{invoice.tax_amount != null ? formatMoney(invoice.tax_amount) : '—'}</dd>
            </div>
            <div>
              <dt className="text-gray-500">Total payable</dt>
              <dd className="mt-1 font-semibold tabular-nums">{invoice.total_amount != null ? formatMoney(invoice.total_amount) : '—'}</dd>
            </div>
          </dl>
        ) : (
          <dl className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div>
              <dt className="text-gray-500">Subtotal</dt>
              <dd className="mt-1 font-medium tabular-nums">{invoice.subtotal_amount != null ? formatMoney(invoice.subtotal_amount) : '—'}</dd>
            </div>
            <div>
              <dt className="text-gray-500">Tax</dt>
              <dd className="mt-1 font-medium tabular-nums">{invoice.tax_amount != null ? formatMoney(invoice.tax_amount) : '—'}</dd>
            </div>
            <div>
              <dt className="text-gray-500">Total</dt>
              <dd className="mt-1 font-semibold tabular-nums">{invoice.total_amount != null ? formatMoney(invoice.total_amount) : '—'}</dd>
            </div>
          </dl>
        )}
      </section>

      {invoice.outstanding_amount != null && (invoice.status === 'POSTED' || invoice.status === 'PAID') && (
        <section className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold mb-1">Outstanding payable</h2>
          <p className="text-sm text-gray-600 mb-2">
            After payment allocations and posted supplier credits linked to this bill (subledger view; GL remains source
            of truth).
          </p>
          <p className="text-xl font-semibold tabular-nums">{formatMoney(invoice.outstanding_amount)}</p>
          <div className="mt-3 flex flex-wrap gap-4">
            {hasRole(['tenant_admin', 'accountant', 'operator']) && parseFloat(String(invoice.outstanding_amount)) > 0 && (
              <Link
                to={`/app/payments/new?partyId=${encodeURIComponent(invoice.party_id)}&direction=OUT&amount=${encodeURIComponent(String(invoice.outstanding_amount))}`}
                className="text-sm font-medium text-[#1F6F5C] hover:underline"
              >
                Record supplier payment
              </Link>
            )}
            {hasRole(['tenant_admin', 'accountant']) && parseFloat(String(invoice.outstanding_amount)) > 0 && (
              <Link
                to={`/app/accounting/supplier-credit-notes/new?party_id=${encodeURIComponent(invoice.party_id)}&supplier_invoice_id=${encodeURIComponent(invoice.id)}`}
                className="text-sm font-medium text-[#1F6F5C] hover:underline"
              >
                Record supplier credit
              </Link>
            )}
          </div>
        </section>
      )}

      {invoice.payment_applications && invoice.payment_applications.length > 0 && (
        <section className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold mb-2">Payments applied to this bill</h2>
          <p className="text-sm text-gray-600 mb-3">
            Subledger applications only; each payment is posted as its own financial event. Open a payment for allocation
            detail.
          </p>
          <div className="overflow-x-auto border border-gray-100 rounded">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left">Payment</th>
                  <th className="px-3 py-2 text-left">Date</th>
                  <th className="px-3 py-2 text-right">Applied</th>
                </tr>
              </thead>
              <tbody>
                {invoice.payment_applications.map((row) => (
                  <tr key={row.allocation_id} className="border-t border-gray-100">
                    <td className="px-3 py-2">
                      <Link to={`/app/payments/${row.payment_id}`} className="text-[#1F6F5C] hover:underline font-medium">
                        {row.payment_reference || row.payment_id.slice(0, 8)}
                      </Link>
                      {row.payment_status && (
                        <span className="text-xs text-gray-500 ml-2">({row.payment_status})</span>
                      )}
                    </td>
                    <td className="px-3 py-2 text-gray-700">
                      {row.payment_date ? formatDate(row.payment_date) : '—'}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums font-medium">{formatMoney(row.amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {invoice.ap_match_summary && (
        <section className="bg-white rounded-lg shadow p-6 space-y-3">
          <h2 className="text-lg font-semibold">Receipt ↔ bill matching</h2>
          <p className="text-sm text-gray-600">
            Traceability only — does not post additional accounting. Matched to posted goods receipt lines:{' '}
            <span className="font-medium tabular-nums">{formatMoney(String(invoice.ap_match_summary.matched_amount))}</span>
            {' · '}
            Unmatched on this bill:{' '}
            <span className="font-medium tabular-nums">{formatMoney(String(invoice.ap_match_summary.unmatched_amount))}</span>
          </p>
          {invoice.ap_match_summary.matches?.length ? (
            <div className="overflow-x-auto border border-gray-100 rounded">
              <table className="min-w-full text-sm">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-3 py-2 text-left">GRN</th>
                    <th className="px-3 py-2 text-right">Qty</th>
                    <th className="px-3 py-2 text-right">Matched amount</th>
                  </tr>
                </thead>
                <tbody>
                  {invoice.ap_match_summary.matches.map((m) => (
                    <tr key={m.id} className="border-t border-gray-100">
                      <td className="px-3 py-2">
                        {m.grn?.id ? (
                          <Link to={`/app/inventory/grns/${m.grn.id}`} className="text-[#1F6F5C] hover:underline">
                            {m.grn.doc_no || m.grn.id}
                          </Link>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">{m.matched_qty}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{formatMoney(m.matched_amount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-sm text-gray-500">No GRN line matches recorded for this bill.</p>
          )}
        </section>
      )}

      <section className="bg-white rounded-lg shadow p-6 space-y-3">
        <h2 className="text-lg font-semibold">Purchase order ↔ invoice matching</h2>
        <p className="text-sm text-gray-600">
          Traceability only — does not post additional accounting. PO rollups count matches only when the invoice is
          <strong> POSTED</strong> or <strong>PAID</strong>.
        </p>

        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">Purchase order ID</label>
            <input
              className="rounded border border-gray-300 px-3 py-2 text-sm w-[28rem] max-w-full"
              value={poId}
              onChange={(e) => setPoId(e.target.value)}
              placeholder="Paste PO id…"
              disabled={invoice.status !== 'DRAFT'}
            />
          </div>
          <button
            type="button"
            className="rounded bg-[#1F6F5C] px-3 py-2 text-sm text-white disabled:opacity-50"
            disabled={invoice.status !== 'DRAFT' || !poId.trim() || poLoading}
            onClick={async () => {
              setPoLoading(true);
              try {
                const res = await purchaseOrdersApi.matching(poId.trim());
                setPoLines(res.lines || []);
                toast.success('PO loaded');
              } catch (e: any) {
                toast.error(e?.message ?? 'Failed to load PO');
              } finally {
                setPoLoading(false);
              }
            }}
          >
            {poLoading ? 'Loading…' : 'Load PO lines'}
          </button>
        </div>

        {invoice.status !== 'DRAFT' && (
          <div className="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            This invoice is {invoice.status}. PO matches are view-only.
          </div>
        )}

        <div className="overflow-x-auto border border-gray-100 rounded">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-2 text-left">Invoice line</th>
                <th className="px-3 py-2 text-left">PO line</th>
                <th className="px-3 py-2 text-right">Matched qty</th>
                <th className="px-3 py-2 text-right">Matched amount</th>
              </tr>
            </thead>
            <tbody>
              {invoice.lines?.map((l) => {
                const draft = poMatchDraft[l.id] || { purchase_order_line_id: '', matched_qty: '', matched_amount: '' };
                return (
                  <tr key={l.id} className="border-t border-gray-100">
                    <td className="px-3 py-2">
                      <div className="font-medium">#{l.line_no ?? '—'}</div>
                      <div className="text-gray-600">{l.description ?? '—'}</div>
                    </td>
                    <td className="px-3 py-2">
                      <select
                        className="rounded border border-gray-300 px-2 py-1 text-sm w-[26rem] max-w-full"
                        value={draft.purchase_order_line_id}
                        disabled={invoice.status !== 'DRAFT'}
                        onChange={(e) =>
                          setPoMatchDraft((s) => ({
                            ...s,
                            [l.id]: { ...draft, purchase_order_line_id: e.target.value },
                          }))
                        }
                      >
                        <option value="">—</option>
                        {poLines.map((pl) => (
                          <option key={pl.purchase_order_line_id} value={pl.purchase_order_line_id}>
                            Line {pl.line_no} · {pl.description || pl.item_name || ''} · remaining {pl.qty_remaining_to_bill}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="px-3 py-2 text-right">
                      <input
                        className="w-28 rounded border border-gray-300 px-2 py-1 text-sm text-right tabular-nums"
                        value={draft.matched_qty}
                        disabled={invoice.status !== 'DRAFT'}
                        onChange={(e) =>
                          setPoMatchDraft((s) => ({
                            ...s,
                            [l.id]: { ...draft, matched_qty: e.target.value },
                          }))
                        }
                      />
                    </td>
                    <td className="px-3 py-2 text-right">
                      <input
                        className="w-32 rounded border border-gray-300 px-2 py-1 text-sm text-right tabular-nums"
                        value={draft.matched_amount}
                        disabled={invoice.status !== 'DRAFT'}
                        onChange={(e) =>
                          setPoMatchDraft((s) => ({
                            ...s,
                            [l.id]: { ...draft, matched_amount: e.target.value },
                          }))
                        }
                      />
                    </td>
                  </tr>
                );
              })}
              {!invoice.lines?.length ? (
                <tr>
                  <td className="px-3 py-6 text-gray-500" colSpan={4}>
                    No lines.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>

        {invoice.status === 'DRAFT' && (
          <div className="flex justify-end">
            <button
              type="button"
              className="rounded bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
              disabled={!id}
              onClick={async () => {
                try {
                  const payload = Object.entries(poMatchDraft)
                    .map(([supplier_invoice_line_id, row]) => ({
                      supplier_invoice_line_id,
                      purchase_order_line_id: row.purchase_order_line_id,
                      matched_qty: parseFloat(row.matched_qty || '0'),
                      matched_amount: parseFloat(row.matched_amount || '0'),
                    }))
                    .filter((r) => r.purchase_order_line_id && r.matched_qty > 0);

                  await supplierInvoicePoMatchesApi.sync(id!, { matches: payload });
                  const refreshed = await supplierInvoicePoMatchesApi.get(id!);
                  setPoMatches(refreshed.matches || []);
                  toast.success('PO matches saved');
                } catch (e: any) {
                  toast.error(e?.message ?? 'Save failed');
                }
              }}
            >
              Save PO matches
            </button>
          </div>
        )}

        {invoice.status !== 'DRAFT' && poMatches.length > 0 && (
          <p className="text-xs text-gray-500">Matches: {poMatches.length}</p>
        )}
      </section>

      {invoice.grn && (
        <section className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold mb-2">Linked GRN</h2>
          <Link to={`/app/inventory/grns/${invoice.grn_id}`} className="text-[#1F6F5C] hover:underline">
            {invoice.grn.doc_no || invoice.grn_id}
          </Link>
        </section>
      )}

      <section className="bg-white rounded-lg shadow overflow-hidden">
        <h2 className="text-lg font-semibold p-6 pb-0">Lines</h2>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Line total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {invoice.lines?.map((line) => (
                <tr key={line.id}>
                  <td className="px-4 py-2 text-sm tabular-nums">{line.line_no ?? '—'}</td>
                  <td className="px-4 py-2 text-sm">{line.description ?? '—'}</td>
                  <td className="px-4 py-2 text-sm text-right tabular-nums">{line.qty ?? '—'}</td>
                  <td className="px-4 py-2 text-sm text-right tabular-nums">
                    {line.line_total != null ? formatMoney(line.line_total) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {statement && (
        <section className="bg-white rounded-lg shadow p-6 space-y-4">
          <h2 className="text-lg font-semibold">Supplier statement (preview)</h2>
          <p className="text-sm text-gray-600">
            Period {statement.period.from} → {statement.period.to}. Subledger outstanding at period end:{' '}
            <span className="font-semibold tabular-nums">{formatMoney(statement.reconciliation.subledger_outstanding_at_to)}</span>
            . Statement running balance:{' '}
            <span className="font-semibold tabular-nums">{formatMoney(statement.reconciliation.statement_balance_at_to)}</span>
            {statement.reconciliation.delta !== '0.00' && (
              <>
                {' '}
                (delta {formatMoney(statement.reconciliation.delta)} — e.g. unapplied supplier payments)
              </>
            )}
            .
          </p>
          <div className="overflow-x-auto max-h-80 border border-gray-100 rounded">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50 sticky top-0">
                <tr>
                  <th className="px-3 py-2 text-left">Date</th>
                  <th className="px-3 py-2 text-left">Description</th>
                  <th className="px-3 py-2 text-right">Debit</th>
                  <th className="px-3 py-2 text-right">Credit</th>
                  <th className="px-3 py-2 text-right">Balance</th>
                </tr>
              </thead>
              <tbody>
                {statement.lines.map((line, i) => (
                  <tr key={`${line.source_type}-${line.source_id}-${i}`} className="border-t border-gray-100">
                    <td className="px-3 py-1.5 whitespace-nowrap">{formatDate(line.posting_date)}</td>
                    <td className="px-3 py-1.5">{line.description}</td>
                    <td className="px-3 py-1.5 text-right tabular-nums">{line.debit !== '0.00' ? formatMoney(line.debit) : '—'}</td>
                    <td className="px-3 py-1.5 text-right tabular-nums">{line.credit !== '0.00' ? formatMoney(line.credit) : '—'}</td>
                    <td className="px-3 py-1.5 text-right tabular-nums font-medium">{formatMoney(line.running_balance)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </PageContainer>
  );
}

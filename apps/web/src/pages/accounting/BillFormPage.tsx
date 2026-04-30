import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { PageContainer } from '../../components/PageContainer';
import { PageHeader } from '../../components/PageHeader';
import { FormField } from '../../components/FormField';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useParties } from '../../hooks/useParties';
import { useCostCenters } from '../../hooks/useCostCenters';
import { useProjects } from '../../hooks/useProjects';
import { useTenantSettings } from '../../hooks/useTenantSettings';
import { purchaseOrdersApi } from '../../lib/api/procurement/purchaseOrders';
import type { SupplierInvoiceDetail } from '../../types';
import toast from 'react-hot-toast';

export type BillFormMode = 'overhead' | 'supplier';

type LineRow = {
  description: string;
  qty: string;
  cash_unit_price: string;
  credit_unit_price: string;
  po?: {
    qty_ordered: string;
    qty_received: string;
    qty_invoiced: string;
    remaining_qty: string;
  };
};

const emptyLine = (): LineRow => ({
  description: '',
  qty: '1',
  cash_unit_price: '',
  credit_unit_price: '',
  po: undefined,
});

export default function BillFormPage(props: { mode: BillFormMode; isNew: boolean; invoiceId?: string }) {
  const { mode, isNew, invoiceId } = props;
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { settings } = useTenantSettings();
  const [searchParams] = useSearchParams();
  const poId = mode === 'supplier' && isNew ? (searchParams.get('po_id') ?? '') : '';

  const { data: parties = [], isLoading: partiesLoading } = useParties();
  const { data: costCenters = [], isLoading: ccLoading } = useCostCenters('ACTIVE');
  const { data: projects = [], isLoading: projLoading } = useProjects();

  const { data: existing, isLoading: invoiceLoading } = useQuery({
    queryKey: ['supplier-invoice', invoiceId],
    queryFn: () => apiClient.get<SupplierInvoiceDetail>(`/api/supplier-invoices/${invoiceId}`),
    enabled: !!invoiceId && !isNew,
  });

  const [scope, setScope] = useState<'farm' | 'project'>(mode === 'overhead' ? 'farm' : 'project');
  const [partyId, setPartyId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [costCenterId, setCostCenterId] = useState('');
  const [referenceNo, setReferenceNo] = useState('');
  const [invoiceDate, setInvoiceDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [dueDate, setDueDate] = useState('');
  const [currencyCode, setCurrencyCode] = useState(() => (settings?.currency_code || 'GBP').toUpperCase());
  const [paymentTerms, setPaymentTerms] = useState<'CASH' | 'CREDIT'>('CASH');
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<LineRow[]>([emptyLine()]);
  const [initialized, setInitialized] = useState(false);
  const [prefillDone, setPrefillDone] = useState(false);

  const { data: poPrepare, isLoading: poPrepareLoading } = useQuery({
    queryKey: ['purchase-order-prepare-invoice', poId],
    queryFn: () => purchaseOrdersApi.prepareInvoice(poId),
    enabled: mode === 'supplier' && isNew && Boolean(poId),
  });

  useEffect(() => {
    if (isNew && settings?.currency_code) {
      setCurrencyCode(settings.currency_code.toUpperCase());
    }
  }, [isNew, settings?.currency_code]);

  useEffect(() => {
    if (mode !== 'supplier' || !isNew || !poId || prefillDone) return;
    if (!poPrepare) return;

    const prep = (poPrepare as unknown as { data?: typeof poPrepare }).data ?? poPrepare;
    setPartyId(prep.party_id);
    setCurrencyCode((prep.currency_code || currencyCode).toUpperCase());

    const preparedLines: LineRow[] = (prep.lines ?? [])
      .map((l) => {
        const remaining = parseFloat(l.remaining_qty ?? '0') || 0;
        if (remaining <= 0.000001) return null;
        const unit = parseFloat(l.unit_price ?? '0') || 0;
        return {
          description: l.description ?? '',
          qty: remaining % 1 === 0 ? String(Math.trunc(remaining)) : String(remaining),
          cash_unit_price: unit > 0 ? String(unit) : '',
          credit_unit_price: unit > 0 ? String(unit) : '',
          po: {
            qty_ordered: l.qty_ordered ?? '0.000000',
            qty_received: l.qty_received ?? '0.000000',
            qty_invoiced: l.qty_invoiced ?? '0.000000',
            remaining_qty: l.remaining_qty ?? '0.000000',
          },
        };
      })
      .filter(Boolean) as LineRow[];

    if (preparedLines.length > 0) {
      setLines(preparedLines);
      toast.success('Prefilled from purchase order');
    } else {
      toast('No remaining quantity to invoice on this PO.');
    }

    setPrefillDone(true);
  }, [mode, isNew, poId, poPrepare, prefillDone, currencyCode]);

  useEffect(() => {
    // Defensive: keep mode-deterministic defaults.
    setScope(mode === 'overhead' ? 'farm' : 'project');
    if (mode === 'overhead') {
      setPaymentTerms('CASH');
    }
  }, [mode]);

  useEffect(() => {
    if (isNew || !existing || initialized) return;
    if (existing.status !== 'DRAFT') {
      toast.error('Only draft bills can be edited.');
      navigate(`/app/accounting/supplier-invoices/${invoiceId}`, { replace: true });
      return;
    }
    if (existing.grn_id) {
      navigate(`/app/accounting/supplier-invoices/${invoiceId}`, { replace: true });
      return;
    }
    const farm = Boolean(existing.cost_center_id && !existing.project_id);
    setScope(mode === 'overhead' ? 'farm' : farm ? 'farm' : 'project');
    setPartyId(existing.party_id);
    setProjectId(existing.project_id ?? '');
    setCostCenterId(existing.cost_center_id ?? '');
    setReferenceNo(existing.reference_no ?? '');
    setInvoiceDate(existing.invoice_date?.slice(0, 10) ?? invoiceDate);
    setDueDate(existing.due_date?.slice(0, 10) ?? '');
    setCurrencyCode((existing.currency_code || currencyCode).toUpperCase());
    setPaymentTerms(mode === 'supplier' ? ((existing.payment_terms as 'CASH' | 'CREDIT' | null) || 'CASH') : 'CASH');
    setNotes(existing.notes ?? '');
    if (existing.lines?.length) {
      setLines(
        existing.lines.map((l) => ({
          description: l.description ?? '',
          qty: l.qty != null ? String(l.qty) : '1',
          cash_unit_price:
            l.cash_unit_price != null
              ? String(l.cash_unit_price)
              : l.unit_price != null
                ? String(l.unit_price)
                : '',
          credit_unit_price:
            mode === 'supplier'
              ? l.credit_unit_price != null
                ? String(l.credit_unit_price)
                : l.selected_unit_price != null
                  ? String(l.selected_unit_price)
                  : l.unit_price != null
                    ? String(l.unit_price)
                    : ''
              : '',
        }))
      );
    }
    setInitialized(true);
  }, [existing, invoiceId, isNew, initialized, navigate, invoiceDate, currencyCode, mode]);

  const lineCalcs = useMemo(() => {
    return lines.map((l) => {
      const qty = parseFloat(l.qty) || 0;
      const cash = parseFloat(l.cash_unit_price) || 0;
      const creditRaw = parseFloat(l.credit_unit_price) || 0;
      const credit = paymentTerms === 'CREDIT' ? creditRaw : cash;
      const selectedUnit = paymentTerms === 'CREDIT' ? credit : cash;
      const baseCashAmount = qty * cash;
      const premiumAmount = paymentTerms === 'CREDIT' ? Math.max(0, qty * (credit - cash)) : 0;
      const lineTotal = qty * selectedUnit;
      return { qty, cash, credit, selectedUnit, baseCashAmount, premiumAmount, lineTotal };
    });
  }, [lines, paymentTerms]);

  const lineSum = useMemo(() => lineCalcs.reduce((acc, c) => acc + (c.lineTotal || 0), 0), [lineCalcs]);
  const baseCashSum = useMemo(() => lineCalcs.reduce((acc, c) => acc + (c.baseCashAmount || 0), 0), [lineCalcs]);
  const premiumSum = useMemo(() => lineCalcs.reduce((acc, c) => acc + (c.premiumAmount || 0), 0), [lineCalcs]);

  const addLine = () => setLines((l) => [...l, emptyLine()]);
  const removeLine = (i: number) => setLines((l) => (l.length > 1 ? l.filter((_, idx) => idx !== i) : l));
  const updateLine = (i: number, patch: Partial<LineRow>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...patch } : row)));

  const buildPayload = () => {
    const total = Math.round(lineSum * 100) / 100;
    const payloadLines = lines
      .map((l, idx) => {
        const c = lineCalcs[idx];
        const qty = c?.qty || 0;
        const cash = c?.cash || 0;
        const credit = c?.credit || 0;
        const selectedUnit = c?.selectedUnit || 0;
        const baseCashAmount = c?.baseCashAmount || 0;
        const premiumAmount = c?.premiumAmount || 0;
        const lineTotal = c?.lineTotal || 0;
        return {
          description: l.description.trim() || undefined,
          qty: qty || 1,
          unit_price: Math.round(selectedUnit * 100) / 100,
          cash_unit_price: Math.round(cash * 100) / 100,
          credit_unit_price: mode === 'supplier' ? Math.round(credit * 100) / 100 : undefined,
          selected_unit_price: mode === 'supplier' ? Math.round(selectedUnit * 100) / 100 : undefined,
          base_cash_amount: Math.round(baseCashAmount * 100) / 100,
          credit_premium_amount: mode === 'supplier' ? Math.round(premiumAmount * 100) / 100 : undefined,
          line_total: Math.round(lineTotal * 100) / 100,
          line_no: idx + 1,
        };
      })
      .filter((l) => l.line_total > 0.009);

    return {
      party_id: partyId,
      project_id: mode === 'supplier' ? (projectId || null) : null,
      cost_center_id: mode === 'overhead' ? (costCenterId || null) : null,
      grn_id: null as string | null,
      reference_no: referenceNo.trim() || undefined,
      invoice_date: invoiceDate,
      due_date: dueDate.trim() || undefined,
      currency_code: currencyCode.trim().toUpperCase() || 'GBP',
      payment_terms: mode === 'supplier' ? paymentTerms : null,
      subtotal_amount: total,
      tax_amount: 0,
      total_amount: total,
      notes: notes.trim() || undefined,
      lines: payloadLines,
    };
  };

  const saveM = useMutation({
    mutationFn: async () => {
      const body = buildPayload();
      if (!body.party_id) throw new Error('Choose a supplier.');
      if (scope === 'farm' && !body.cost_center_id) throw new Error('Choose a cost center.');
      if (scope === 'project' && !body.project_id) throw new Error('Choose a project.');
      if (body.lines.length === 0) throw new Error('Add at least one line with a positive amount.');
      if (mode === 'supplier' && body.payment_terms === 'CREDIT') {
        const bad = body.lines.find((l: any) => (l.credit_unit_price ?? 0) + 0.000001 < (l.cash_unit_price ?? 0));
        if (bad) throw new Error('For CREDIT terms, credit unit price must be ≥ cash unit price on all lines.');
      }
      if (isNew) {
        return apiClient.post<SupplierInvoiceDetail>('/api/supplier-invoices', body);
      }
      return apiClient.put<SupplierInvoiceDetail>(`/api/supplier-invoices/${invoiceId}`, body);
    },
    onSuccess: (inv) => {
      queryClient.invalidateQueries({ queryKey: ['supplier-invoices'] });
      queryClient.invalidateQueries({ queryKey: ['supplier-invoice', inv.id] });
      toast.success(isNew ? 'Draft bill saved' : 'Bill updated');
      navigate(`/app/accounting/supplier-invoices/${inv.id}`);
    },
    onError: (e: Error) => toast.error(e.message || 'Save failed'),
  });

  const loading = partiesLoading || ccLoading || projLoading || (!isNew && invoiceLoading);
  const prefillLoading = poPrepareLoading && mode === 'supplier' && isNew && Boolean(poId) && !prefillDone;

  if (!isNew && !invoiceId) {
    return (
      <PageContainer className="space-y-4 max-w-2xl">
        <PageHeader
          title="Bill form unavailable"
          backTo={mode === 'overhead' ? '/app/accounting/bills' : '/app/accounting/supplier-invoices'}
          breadcrumbs={[
            {
              label: mode === 'overhead' ? 'Farm overhead bills' : 'Supplier bills / invoices',
              to: mode === 'overhead' ? '/app/accounting/bills' : '/app/accounting/supplier-invoices',
            },
          ]}
        />
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          This page needs an invoice id, but none was provided. Use the Bills list to create a new bill or open an
          existing draft.
        </div>
        <Link
          to={mode === 'overhead' ? '/app/accounting/bills' : '/app/accounting/supplier-invoices'}
          className="text-sm font-medium text-[#1F6F5C] hover:underline"
        >
          ← Back
        </Link>
      </PageContainer>
    );
  }

  if (!isNew && invoiceLoading) {
    return (
      <PageContainer>
        <LoadingSpinner />
      </PageContainer>
    );
  }

  return (
    <PageContainer className="space-y-6 max-w-4xl">
      <PageHeader
        title={
          mode === 'overhead'
            ? isNew
              ? 'New farm overhead bill'
              : 'Edit farm overhead bill'
            : isNew
              ? 'New supplier bill / invoice'
              : 'Edit supplier bill / invoice'
        }
        backTo={
          mode === 'overhead'
            ? '/app/accounting/bills'
            : !isNew && invoiceId
              ? `/app/accounting/supplier-invoices/${invoiceId}`
              : '/app/accounting/supplier-invoices'
        }
        breadcrumbs={[
          {
            label: mode === 'overhead' ? 'Farm overhead bills' : 'Supplier bills / invoices',
            to: mode === 'overhead' ? '/app/accounting/bills' : '/app/accounting/supplier-invoices',
          },
          { label: isNew ? 'New' : 'Edit' },
        ]}
      />

      {loading && (
        <p className="text-sm text-gray-500">Loading reference data…</p>
      )}
      {prefillLoading && (
        <p className="text-sm text-gray-500">Preparing invoice from purchase order…</p>
      )}

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-lg font-semibold">Belongs to</h2>
        {mode === 'overhead' ? (
          <>
            <p className="text-sm text-gray-600">
              Farm overhead bills must belong to a <strong>cost center</strong>.
            </p>
            <FormField label="Cost center">
              <select
                id="cc"
                className="w-full max-w-md rounded border border-gray-300 px-3 py-2 text-sm"
                value={costCenterId}
                onChange={(e) => setCostCenterId(e.target.value)}
              >
                <option value="">Select…</option>
                {costCenters.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                    {c.code ? ` (${c.code})` : ''}
                  </option>
                ))}
              </select>
            </FormField>
          </>
        ) : (
          <>
            <p className="text-sm text-gray-600">
              Supplier bills / invoices should be <strong>project-linked</strong> (crop costs). For farm overhead use
              Farm overhead bills.
            </p>
            <FormField label="Project">
              <select
                id="proj"
                className="w-full max-w-md rounded border border-gray-300 px-3 py-2 text-sm"
                value={projectId}
                onChange={(e) => setProjectId(e.target.value)}
              >
                <option value="">Select…</option>
                {projects.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </FormField>
          </>
        )}
      </section>

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-lg font-semibold">Header</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <FormField label="Supplier">
            <select
              id="party"
              className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
              value={partyId}
              onChange={(e) => setPartyId(e.target.value)}
            >
              <option value="">Select…</option>
              {parties.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
          {mode === 'supplier' ? (
            <FormField label="Payment terms">
              <select
                id="terms"
                className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                value={paymentTerms}
                onChange={(e) => setPaymentTerms((e.target.value as 'CASH' | 'CREDIT') || 'CASH')}
              >
                <option value="CASH">Cash</option>
                <option value="CREDIT">Credit</option>
              </select>
              <p className="mt-1 text-xs text-gray-500">
                Credit terms post base cost plus a separate <strong>credit premium</strong>.
              </p>
            </FormField>
          ) : null}
          <FormField label="Reference / bill number">
            <input
              id="ref"
              className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
              value={referenceNo}
              onChange={(e) => setReferenceNo(e.target.value)}
            />
          </FormField>
          <FormField label="Bill date">
            <input
              id="inv-date"
              type="date"
              className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
              value={invoiceDate}
              onChange={(e) => setInvoiceDate(e.target.value)}
            />
          </FormField>
          <FormField label="Due date (optional)">
            <input
              id="due"
              type="date"
              className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
              value={dueDate}
              onChange={(e) => setDueDate(e.target.value)}
            />
          </FormField>
          <FormField label="Currency">
            <input
              id="ccy"
              className="w-full rounded border border-gray-300 px-3 py-2 text-sm uppercase"
              maxLength={3}
              value={currencyCode}
              onChange={(e) => setCurrencyCode(e.target.value.toUpperCase())}
            />
          </FormField>
        </div>
        <FormField label="Notes (optional)">
          <textarea
            id="notes"
            className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
            rows={2}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
          />
        </FormField>
      </section>

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold">Lines</h2>
          <button type="button" onClick={addLine} className="text-sm text-[#1F6F5C] font-medium hover:underline">
            Add line
          </button>
        </div>
        <p className="text-sm text-gray-600">
          {mode === 'supplier'
            ? 'Enter cash and (optional) credit unit prices. Line totals and credit premium are computed automatically from qty and the selected terms.'
            : 'Enter cash unit price. Line totals are computed automatically from qty.'}
        </p>
        <div className="space-y-3">
          {lines.map((line, i) => (
            <div key={i} className="flex flex-wrap gap-3 items-end border-b border-gray-100 pb-3">
              <div className="flex-1 min-w-[12rem]">
                <span className="block text-xs font-medium text-gray-600 mb-1">Description</span>
                <input
                  className="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                  value={line.description}
                  onChange={(e) => updateLine(i, { description: e.target.value })}
                />
              </div>
              <div className="w-24">
                <span className="block text-xs font-medium text-gray-600 mb-1">Qty</span>
                <input
                  className="w-full rounded border border-gray-300 px-3 py-2 text-sm tabular-nums"
                  value={line.qty}
                  onChange={(e) => updateLine(i, { qty: e.target.value })}
                />
                {mode === 'supplier' && isNew && poId && line.po ? (
                  <div className="mt-1 space-y-0.5 text-[11px] leading-tight text-gray-500 tabular-nums">
                    <div>Ordered: {line.po.qty_ordered}</div>
                    <div>Received: {line.po.qty_received}</div>
                    <div>Invoiced: {line.po.qty_invoiced}</div>
                    <div className="text-gray-700 font-medium">Remaining: {line.po.remaining_qty}</div>
                  </div>
                ) : null}
              </div>
              <div className="w-32">
                <span className="block text-xs font-medium text-gray-600 mb-1">Cash unit</span>
                <input
                  className="w-full rounded border border-gray-300 px-3 py-2 text-sm tabular-nums"
                  value={line.cash_unit_price}
                  onChange={(e) => updateLine(i, { cash_unit_price: e.target.value })}
                />
              </div>
              {mode === 'supplier' && paymentTerms === 'CREDIT' && (
                <div className="w-32">
                  <span className="block text-xs font-medium text-gray-600 mb-1">Credit unit</span>
                  <input
                    className="w-full rounded border border-gray-300 px-3 py-2 text-sm tabular-nums"
                    value={line.credit_unit_price}
                    onChange={(e) => updateLine(i, { credit_unit_price: e.target.value })}
                  />
                </div>
              )}
              <div className="w-32">
                <span className="block text-xs font-medium text-gray-600 mb-1">Line total</span>
                <div className="rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm tabular-nums text-right">
                  {(lineCalcs[i]?.lineTotal ?? 0) > 0.009 ? (lineCalcs[i]?.lineTotal ?? 0).toFixed(2) : '—'}
                </div>
              </div>
              {mode === 'supplier' && paymentTerms === 'CREDIT' && (
                <div className="w-32">
                  <span className="block text-xs font-medium text-gray-600 mb-1">Premium</span>
                  <div className="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm tabular-nums text-right text-amber-900 font-medium">
                    {(lineCalcs[i]?.premiumAmount ?? 0).toFixed(2)}
                  </div>
                </div>
              )}
              <button
                type="button"
                className="text-sm text-red-700 hover:underline pb-2"
                onClick={() => removeLine(i)}
                disabled={lines.length <= 1}
              >
                Remove
              </button>
            </div>
          ))}
        </div>
        {mode === 'supplier' && paymentTerms === 'CREDIT' ? (
          <div className="text-sm font-medium text-gray-900 space-y-1">
            <div>
              Cash amount: <span className="tabular-nums">{baseCashSum.toFixed(2)}</span> {currencyCode}
            </div>
            <div className="text-amber-900">
              Credit premium: <span className="tabular-nums">{premiumSum.toFixed(2)}</span> {currencyCode}
            </div>
            <div>
              Total payable: <span className="tabular-nums">{lineSum.toFixed(2)}</span> {currencyCode}
            </div>
          </div>
        ) : (
          <p className="text-sm font-medium text-gray-900">
            Total: <span className="tabular-nums">{lineSum.toFixed(2)}</span> {currencyCode}
          </p>
        )}
      </section>

      <div className="flex gap-3">
        <button
          type="button"
          disabled={saveM.isPending || lineSum <= 0}
          onClick={() => saveM.mutate()}
          className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#185647] disabled:opacity-50"
        >
          {saveM.isPending ? 'Saving…' : 'Save draft'}
        </button>
      </div>
    </PageContainer>
  );
}

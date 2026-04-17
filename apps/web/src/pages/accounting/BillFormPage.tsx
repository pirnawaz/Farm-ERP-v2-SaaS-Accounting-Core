import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
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
import type { SupplierInvoiceDetail } from '../../types';
import toast from 'react-hot-toast';

type LineRow = {
  description: string;
  qty: string;
  line_total: string;
};

const emptyLine = (): LineRow => ({
  description: '',
  qty: '1',
  line_total: '',
});

export default function BillFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { settings } = useTenantSettings();
  const isNew = id === 'new';

  const { data: parties = [], isLoading: partiesLoading } = useParties();
  const { data: costCenters = [], isLoading: ccLoading } = useCostCenters('ACTIVE');
  const { data: projects = [], isLoading: projLoading } = useProjects();

  const { data: existing, isLoading: invoiceLoading } = useQuery({
    queryKey: ['supplier-invoice', id],
    queryFn: () => apiClient.get<SupplierInvoiceDetail>(`/api/supplier-invoices/${id}`),
    enabled: !!id && !isNew,
  });

  const [scope, setScope] = useState<'farm' | 'project'>('farm');
  const [partyId, setPartyId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [costCenterId, setCostCenterId] = useState('');
  const [referenceNo, setReferenceNo] = useState('');
  const [invoiceDate, setInvoiceDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [dueDate, setDueDate] = useState('');
  const [currencyCode, setCurrencyCode] = useState(() => (settings?.currency_code || 'GBP').toUpperCase());
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<LineRow[]>([emptyLine()]);
  const [initialized, setInitialized] = useState(false);

  useEffect(() => {
    if (isNew && settings?.currency_code) {
      setCurrencyCode(settings.currency_code.toUpperCase());
    }
  }, [isNew, settings?.currency_code]);

  useEffect(() => {
    if (isNew || !existing || initialized) return;
    if (existing.status !== 'DRAFT') {
      toast.error('Only draft bills can be edited.');
      navigate(`/app/accounting/supplier-invoices/${id}`, { replace: true });
      return;
    }
    if (existing.grn_id) {
      navigate(`/app/accounting/supplier-invoices/${id}`, { replace: true });
      return;
    }
    const farm = Boolean(existing.cost_center_id && !existing.project_id);
    setScope(farm ? 'farm' : 'project');
    setPartyId(existing.party_id);
    setProjectId(existing.project_id ?? '');
    setCostCenterId(existing.cost_center_id ?? '');
    setReferenceNo(existing.reference_no ?? '');
    setInvoiceDate(existing.invoice_date?.slice(0, 10) ?? invoiceDate);
    setDueDate(existing.due_date?.slice(0, 10) ?? '');
    setCurrencyCode((existing.currency_code || currencyCode).toUpperCase());
    setNotes(existing.notes ?? '');
    if (existing.lines?.length) {
      setLines(
        existing.lines.map((l) => ({
          description: l.description ?? '',
          qty: l.qty != null ? String(l.qty) : '1',
          line_total: l.line_total != null ? String(l.line_total) : '',
        }))
      );
    }
    setInitialized(true);
  }, [existing, id, isNew, initialized, navigate, invoiceDate, currencyCode]);

  const lineSum = useMemo(
    () =>
      lines.reduce((acc, l) => {
        const v = parseFloat(l.line_total) || 0;
        return acc + v;
      }, 0),
    [lines]
  );

  const addLine = () => setLines((l) => [...l, emptyLine()]);
  const removeLine = (i: number) => setLines((l) => (l.length > 1 ? l.filter((_, idx) => idx !== i) : l));
  const updateLine = (i: number, patch: Partial<LineRow>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...patch } : row)));

  const buildPayload = () => {
    const total = Math.round(lineSum * 100) / 100;
    const payloadLines = lines
      .map((l, idx) => ({
        description: l.description.trim() || undefined,
        qty: parseFloat(l.qty) || 1,
        line_total: Math.round((parseFloat(l.line_total) || 0) * 100) / 100,
        line_no: idx + 1,
      }))
      .filter((l) => l.line_total > 0);

    return {
      party_id: partyId,
      project_id: scope === 'project' ? projectId || null : null,
      cost_center_id: scope === 'farm' ? costCenterId || null : null,
      grn_id: null as string | null,
      reference_no: referenceNo.trim() || undefined,
      invoice_date: invoiceDate,
      due_date: dueDate.trim() || undefined,
      currency_code: currencyCode.trim().toUpperCase() || 'GBP',
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
      if (isNew) {
        return apiClient.post<SupplierInvoiceDetail>('/api/supplier-invoices', body);
      }
      return apiClient.put<SupplierInvoiceDetail>(`/api/supplier-invoices/${id}`, body);
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

  if (!isNew && !id) {
    return null;
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
        title={isNew ? 'New bill' : 'Edit bill'}
        backTo={isNew ? '/app/accounting/bills' : `/app/accounting/supplier-invoices/${id}`}
        breadcrumbs={[
          { label: 'Bills', to: '/app/accounting/bills' },
          { label: isNew ? 'New' : 'Edit' },
        ]}
      />

      {loading && (
        <p className="text-sm text-gray-500">Loading reference data…</p>
      )}

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-lg font-semibold">Belongs to</h2>
        <p className="text-sm text-gray-600">
          Pick <strong>cost center</strong> for farm overhead, or <strong>project</strong> only when this payable is
          intentionally project-scoped (crop-linked). Do not use both.
        </p>
        <div className="flex flex-wrap gap-4 text-sm">
          <label className="inline-flex items-center gap-2 cursor-pointer">
            <input type="radio" name="scope" checked={scope === 'farm'} onChange={() => setScope('farm')} />
            Cost center (farm overhead)
          </label>
          <label className="inline-flex items-center gap-2 cursor-pointer">
            <input type="radio" name="scope" checked={scope === 'project'} onChange={() => setScope('project')} />
            Project
          </label>
        </div>
        {scope === 'farm' ? (
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
        ) : (
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
          Line totals must add up to the bill total. For cost-center bills, do not use stock lines — description and
          amount only.
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
              </div>
              <div className="w-32">
                <span className="block text-xs font-medium text-gray-600 mb-1">Line total</span>
                <input
                  className="w-full rounded border border-gray-300 px-3 py-2 text-sm tabular-nums"
                  value={line.line_total}
                  onChange={(e) => updateLine(i, { line_total: e.target.value })}
                />
              </div>
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
        <p className="text-sm font-medium text-gray-900">
          Total: <span className="tabular-nums">{lineSum.toFixed(2)}</span> {currencyCode}
        </p>
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

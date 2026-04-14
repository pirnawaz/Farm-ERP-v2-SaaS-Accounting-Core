import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useProductionUnit } from '../../hooks/useProductionUnits';
import { useProductionUnitSummary, useLivestockUnitStatus } from '../../hooks/useReports';
import { useLivestockEvents, useCreateLivestockEvent, useUpdateLivestockEvent, useDeleteLivestockEvent } from '../../hooks/useLivestockEvents';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { EmptyState } from '../../components/EmptyState';
import { KpiCard, KpiGrid } from '../../components/KpiCard';
import { useFormatting } from '../../hooks/useFormatting';
import { useOrchardLivestockAddonsEnabled } from '../../hooks/useModules';
import toast from 'react-hot-toast';
import type { LivestockEvent, LivestockEventType } from '../../types';

const currentYear = new Date().getFullYear();
const asOf = new Date().toISOString().split('T')[0];
const from = `${currentYear}-01-01`;
const to = `${currentYear}-12-31`;

const EVENT_TYPES: { value: LivestockEventType; label: string }[] = [
  { value: 'PURCHASE', label: 'Purchase' },
  { value: 'SALE', label: 'Sale' },
  { value: 'BIRTH', label: 'Birth' },
  { value: 'DEATH', label: 'Death' },
  { value: 'ADJUSTMENT', label: 'Adjustment' },
];

function EventModal({
  unitId,
  event,
  onClose,
  onSaved,
}: {
  unitId: string;
  event: LivestockEvent | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const createM = useCreateLivestockEvent();
  const updateM = useUpdateLivestockEvent();
  const isEdit = !!event;
  const [event_date, setEventDate] = useState(event?.event_date ?? new Date().toISOString().split('T')[0]);
  const [event_type, setEventType] = useState<LivestockEventType>(event?.event_type ?? 'PURCHASE');
  const [quantity, setQuantity] = useState(event ? String(Math.abs(event.quantity)) : '');
  const [notes, setNotes] = useState(event?.notes ?? '');

  const handleSubmit = async () => {
    const q = event_type === 'ADJUSTMENT' ? parseInt(quantity, 10) : Math.abs(parseInt(quantity, 10) || 0);
    if (event_type !== 'ADJUSTMENT' && (!quantity || q <= 0)) {
      toast.error('Quantity must be positive');
      return;
    }
    if (event_type === 'ADJUSTMENT' && (quantity === '' || q === 0)) {
      toast.error('Quantity must be non-zero for adjustment');
      return;
    }
    const payloadQ = event_type === 'ADJUSTMENT' ? q : Math.abs(q);
    try {
      if (isEdit) {
        await updateM.mutateAsync({
          id: event!.id,
          payload: { event_date, event_type, quantity: payloadQ, notes: notes || undefined },
        });
        toast.success('Event updated');
      } else {
        await createM.mutateAsync({
          production_unit_id: unitId,
          event_date,
          event_type,
          quantity: payloadQ,
          notes: notes || undefined,
        });
        toast.success('Event added');
      }
      onSaved();
      onClose();
    } catch (err: unknown) {
      const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } }; message?: string };
      const data = e?.response?.data;
      const msg = data?.errors
        ? Object.values(data.errors).flat().join(' ')
        : (data && 'message' in data ? data.message : null) ?? e?.message ?? 'Failed to save event';
      toast.error(String(msg));
    }
  };

  return (
    <Modal isOpen onClose={onClose} title={isEdit ? 'Edit event' : 'Add event'}>
      <div className="space-y-4">
        <FormField label="Date" required>
          <input
            type="date"
            value={event_date}
            onChange={(e) => setEventDate(e.target.value)}
            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
          />
        </FormField>
        <FormField label="Event type" required>
          <select
            value={event_type}
            onChange={(e) => setEventType(e.target.value as LivestockEventType)}
            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
          >
            {EVENT_TYPES.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={event_type === 'ADJUSTMENT' ? 'Quantity (+ or -)' : 'Quantity'} required>
          <input
            type="number"
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            placeholder={event_type === 'ADJUSTMENT' ? 'e.g. 5 or -3' : '0'}
            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
          />
        </FormField>
        <FormField label="Notes">
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={2}
            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
          />
        </FormField>
      </div>
      <div className="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
        <button type="button" onClick={onClose} className="w-full sm:w-auto px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
          Cancel
        </button>
        <button
          type="button"
          onClick={handleSubmit}
          disabled={createM.isPending || updateM.isPending || !event_date || !quantity}
          className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50"
        >
          {isEdit ? 'Update' : 'Add'}
        </button>
      </div>
    </Modal>
  );
}

export default function LivestockDetailPage() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const { showLivestock } = useOrchardLivestockAddonsEnabled();
  const { formatMoney } = useFormatting();
  const [showEventModal, setShowEventModal] = useState(false);
  const [editingEvent, setEditingEvent] = useState<LivestockEvent | null>(null);

  if (!showLivestock) {
    return (
      <div className="space-y-6" data-testid="livestock-detail-page">
        <PageHeader
          title="Livestock"
          backTo="/app/dashboard"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Livestock', to: '/app/livestock' },
            { label: 'Unit' },
          ]}
        />
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
          Livestock module is not enabled for this tenant.
        </div>
      </div>
    );
  }

  const { data: unit, isLoading: unitLoading } = useProductionUnit(id || '');
  const { data: status, isLoading: statusLoading } = useLivestockUnitStatus(
    { production_unit_id: id!, as_of: asOf },
    { enabled: !!id }
  );
  const { data: summary, isLoading: summaryLoading } = useProductionUnitSummary(
    { production_unit_id: id!, from, to },
    { enabled: !!id }
  );
  const { data: events = [], isLoading: eventsLoading } = useLivestockEvents(
    id ? { production_unit_id: id, from, to } : undefined
  );
  const deleteEvent = useDeleteLivestockEvent();

  if (unitLoading || !id) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!unit) {
    return (
      <div>
        <EmptyState
          title="Livestock unit not found"
          description="This unit may have been deleted, or you may not have access."
          action={{ label: 'Back to Livestock', onClick: () => navigate('/app/livestock') }}
        />
      </div>
    );
  }

  const headcount = status?.headcount_as_of ?? null;

  return (
    <div className="space-y-6" data-testid="livestock-detail-page">
      <PageHeader
        title={unit.name}
        description="Herd or flock unit for events, headcount, and tagged costs and sales."
        helper="Use this view for headcount, herd events, and this year’s economics. Record feed, medicine, and other costs with field jobs."
        backTo="/app/livestock"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Livestock', to: '/app/livestock' },
          { label: unit.name },
        ]}
      />

      {(unit.livestock_type || unit.herd_start_count != null) && (
        <p className="text-sm text-gray-600">
          {unit.livestock_type && <span>{unit.livestock_type}</span>}
          {unit.herd_start_count != null && (
            <span className={unit.livestock_type ? ' ml-2' : ''}>Start: {unit.herd_start_count} head</span>
          )}
        </p>
      )}

      {/* Headcount */}
      <section>
        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Headcount (now)</h2>
        {statusLoading ? (
          <div className="flex justify-center py-4">
            <LoadingSpinner />
          </div>
        ) : (
          <div className="rounded-xl border-2 border-[#1F6F5C]/20 bg-[#1F6F5C]/5 p-4 inline-block">
            <p className="text-sm font-medium text-gray-600">Headcount</p>
            <p className="text-2xl font-semibold text-gray-900 tabular-nums">{headcount ?? '—'}</p>
          </div>
        )}
      </section>

      {/* This year economics */}
      <section>
        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">This year ({currentYear})</h2>
        {summaryLoading ? (
          <div className="flex justify-center py-4">
            <LoadingSpinner />
          </div>
        ) : summary ? (
          <KpiGrid>
            <KpiCard label="Cost" value={formatMoney(parseFloat(summary.cost))} />
            <KpiCard label="Revenue" value={formatMoney(parseFloat(summary.revenue))} />
            <KpiCard label="Margin" value={formatMoney(parseFloat(summary.margin))} tone="good" emphasized />
          </KpiGrid>
        ) : (
          <p className="text-sm text-gray-500">No data for this period.</p>
        )}
      </section>

      {/* Quick actions */}
      <section>
        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Quick actions</h2>
        <div className="flex flex-wrap gap-3">
          <Link
            to={`/app/crop-ops/field-jobs/new?production_unit_id=${id}`}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] text-sm font-medium"
          >
            New field job
          </Link>
          <Link
            to={`/app/sales/new?production_unit_id=${id}`}
            className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium"
          >
            Record sale
          </Link>
        </div>
      </section>

      {/* Recent events */}
      <section>
        <div className="flex flex-col gap-2 sm:flex-row sm:justify-between sm:items-center mb-3">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Events ({currentYear})</h2>
          <button
            type="button"
            onClick={() => { setEditingEvent(null); setShowEventModal(true); }}
            className="w-full sm:w-auto px-3 py-1.5 bg-[#1F6F5C] text-white text-sm rounded-lg hover:bg-[#1a5a4a]"
          >
            Add event
          </button>
        </div>
        {eventsLoading ? (
          <div className="flex justify-center py-4">
            <LoadingSpinner />
          </div>
        ) : events.length > 0 ? (
          <ul className="rounded-lg border border-gray-200 divide-y divide-gray-200 bg-white">
            {events.slice(0, 20).map((ev) => (
              <li key={ev.id} className="px-4 py-2 flex justify-between items-center">
                <div>
                  <span className="font-medium text-gray-900">{ev.event_type}</span>
                  <span className="text-sm text-gray-500 ml-2">{ev.event_date}</span>
                  {ev.notes && <span className="text-sm text-gray-400 ml-2">— {ev.notes}</span>}
                </div>
                <div className="flex items-center gap-2">
                  <span className="tabular-nums font-medium">{ev.quantity > 0 ? `+${ev.quantity}` : ev.quantity}</span>
                  <button
                    type="button"
                    onClick={() => { setEditingEvent(ev); setShowEventModal(true); }}
                    className="text-sm text-[#1F6F5C] hover:underline"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    onClick={async () => {
                      if (window.confirm('Delete this event?')) {
                        try {
                          await deleteEvent.mutateAsync(ev.id);
                          toast.success('Event deleted');
                        } catch {
                          toast.error('Failed to delete event');
                        }
                      }
                    }}
                    className="text-sm text-red-600 hover:underline"
                  >
                    Delete
                  </button>
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-gray-500">No events this year.</p>
        )}
      </section>

      {showEventModal && (
        <EventModal
          unitId={id!}
          event={editingEvent}
          onClose={() => { setShowEventModal(false); setEditingEvent(null); }}
          onSaved={() => {}}
        />
      )}
    </div>
  );
}

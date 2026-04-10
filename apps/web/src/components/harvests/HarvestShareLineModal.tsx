import { useEffect, useState } from 'react';
import { Modal } from '../Modal';
import { FormField } from '../FormField';
import type {
  HarvestLine,
  HarvestRecipientRole,
  HarvestSettlementMode,
  HarvestShareBasis,
  HarvestShareLine,
  HarvestShareLinePayload,
  InvItem,
  InvStore,
  Machine,
  LabWorker,
  Party,
} from '../../types';

const ROLES: { value: HarvestRecipientRole; label: string }[] = [
  { value: 'OWNER', label: 'Owner retained' },
  { value: 'MACHINE', label: 'Machine' },
  { value: 'LABOUR', label: 'Labour' },
  { value: 'LANDLORD', label: 'Landlord' },
  { value: 'CONTRACTOR', label: 'Contractor' },
];

const SETTLEMENTS: { value: HarvestSettlementMode; label: string }[] = [
  { value: 'CASH', label: 'Cash' },
  { value: 'IN_KIND', label: 'In-kind (to a store)' },
];

const BASES: { value: HarvestShareBasis; label: string }[] = [
  { value: 'FIXED_QTY', label: 'Fixed quantity' },
  { value: 'PERCENT', label: 'Percent of line' },
  { value: 'RATIO', label: 'Ratio share' },
  { value: 'REMAINDER', label: 'Remainder (gets what is left after other buckets)' },
];

function toPayload(
  recipient_role: HarvestRecipientRole,
  settlement_mode: HarvestSettlementMode,
  share_basis: HarvestShareBasis,
  harvest_line_id: string,
  share_value: string,
  ratio_numerator: string,
  ratio_denominator: string,
  machine_id: string,
  worker_id: string,
  beneficiary_party_id: string,
  store_id: string,
  inventory_item_id: string,
  sort_order: string,
  notes: string
): HarvestShareLinePayload {
  const payload: HarvestShareLinePayload = {
    recipient_role,
    settlement_mode,
    share_basis,
    harvest_line_id: harvest_line_id || undefined,
    notes: notes.trim() || undefined,
    sort_order: sort_order.trim() ? parseInt(sort_order, 10) : undefined,
  };

  payload.remainder_bucket = share_basis === 'REMAINDER';

  if (share_basis === 'FIXED_QTY' || share_basis === 'PERCENT') {
    const v = parseFloat(share_value);
    if (!Number.isNaN(v)) payload.share_value = v;
  }
  if (share_basis === 'RATIO') {
    const n = parseFloat(ratio_numerator);
    const d = parseFloat(ratio_denominator);
    if (!Number.isNaN(n)) payload.ratio_numerator = n;
    if (!Number.isNaN(d)) payload.ratio_denominator = d;
  }

  if (recipient_role === 'MACHINE' && machine_id) payload.machine_id = machine_id;
  if (recipient_role === 'LABOUR' && worker_id) payload.worker_id = worker_id;
  if ((recipient_role === 'LANDLORD' || recipient_role === 'CONTRACTOR') && beneficiary_party_id) {
    payload.beneficiary_party_id = beneficiary_party_id;
  }

  if (settlement_mode === 'IN_KIND') {
    if (store_id) payload.store_id = store_id;
    if (inventory_item_id) payload.inventory_item_id = inventory_item_id;
  }

  return payload;
}

type Props = {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  harvestLines: HarvestLine[];
  machines: Machine[];
  workers: LabWorker[];
  parties: Party[];
  stores: InvStore[];
  items: InvItem[];
  initialLine: HarvestShareLine | null;
  saving: boolean;
  onSubmit: (payload: HarvestShareLinePayload) => void;
};

export function HarvestShareLineModal({
  isOpen,
  onClose,
  title,
  harvestLines,
  machines,
  workers,
  parties,
  stores,
  items,
  initialLine,
  saving,
  onSubmit,
}: Props) {
  const [recipient_role, setRecipientRole] = useState<HarvestRecipientRole>('OWNER');
  const [settlement_mode, setSettlementMode] = useState<HarvestSettlementMode>('CASH');
  const [share_basis, setShareBasis] = useState<HarvestShareBasis>('PERCENT');
  const [harvest_line_id, setHarvestLineId] = useState('');
  const [share_value, setShareValue] = useState('');
  const [ratio_numerator, setRatioNumerator] = useState('1');
  const [ratio_denominator, setRatioDenominator] = useState('1');
  const [machine_id, setMachineId] = useState('');
  const [worker_id, setWorkerId] = useState('');
  const [beneficiary_party_id, setBeneficiaryPartyId] = useState('');
  const [store_id, setStoreId] = useState('');
  const [inventory_item_id, setInventoryItemId] = useState('');
  const [sort_order, setSortOrder] = useState('');
  const [notes, setNotes] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    if (initialLine) {
      setRecipientRole(initialLine.recipient_role);
      setSettlementMode(initialLine.settlement_mode);
      setShareBasis(initialLine.share_basis);
      setHarvestLineId(initialLine.harvest_line_id || '');
      setShareValue(
        initialLine.share_value != null && initialLine.share_value !== ''
          ? String(initialLine.share_value)
          : ''
      );
      setRatioNumerator(initialLine.ratio_numerator != null ? String(initialLine.ratio_numerator) : '1');
      setRatioDenominator(
        initialLine.ratio_denominator != null ? String(initialLine.ratio_denominator) : '1'
      );
      setMachineId(initialLine.machine_id || '');
      setWorkerId(initialLine.worker_id || '');
      setBeneficiaryPartyId(initialLine.beneficiary_party_id || '');
      setStoreId(initialLine.store_id || '');
      setInventoryItemId(initialLine.inventory_item_id || '');
      setSortOrder(initialLine.sort_order != null ? String(initialLine.sort_order) : '');
      setNotes(initialLine.notes || '');
    } else {
      setRecipientRole('OWNER');
      setSettlementMode('CASH');
      setShareBasis('PERCENT');
      setHarvestLineId(harvestLines[0]?.id || '');
      setShareValue('');
      setRatioNumerator('1');
      setRatioDenominator('1');
      setMachineId('');
      setWorkerId('');
      setBeneficiaryPartyId('');
      setStoreId('');
      setInventoryItemId('');
      setSortOrder('');
      setNotes('');
    }
  }, [isOpen, initialLine, harvestLines]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const payload = toPayload(
      recipient_role,
      settlement_mode,
      share_basis,
      harvest_line_id,
      share_value,
      ratio_numerator,
      ratio_denominator,
      machine_id,
      worker_id,
      beneficiary_party_id,
      store_id,
      inventory_item_id,
      sort_order,
      notes
    );
    onSubmit(payload);
  };

  const showMachine = recipient_role === 'MACHINE';
  const showWorker = recipient_role === 'LABOUR';
  const showBeneficiary = recipient_role === 'LANDLORD' || recipient_role === 'CONTRACTOR';
  const showValue = share_basis === 'FIXED_QTY' || share_basis === 'PERCENT';
  const showRatio = share_basis === 'RATIO';
  const isRemainderBasis = share_basis === 'REMAINDER';
  const showInKindStores = settlement_mode === 'IN_KIND';

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={title}>
      <form onSubmit={handleSubmit} className="space-y-4 max-h-[70vh] overflow-y-auto pr-1">
        <p className="text-sm text-gray-600">
          Split output on a harvest line. Choose how much goes to each party; preview on the main page shows the effect
          using your books (no client-side costing).
        </p>

        <p className="text-xs text-gray-500 -mt-2 mb-1">Which harvest output line this bucket applies to.</p>
        <FormField label="Harvest output line">
          <select
            value={harvest_line_id}
            onChange={(e) => setHarvestLineId(e.target.value)}
            className="w-full px-3 py-2 border rounded"
            required
          >
            <option value="">Select line</option>
            {harvestLines.map((l) => (
              <option key={l.id} value={l.id}>
                {l.item?.name ?? 'Item'} — {l.quantity} {l.uom || ''}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="Who receives this share">
          <select
            value={recipient_role}
            onChange={(e) => setRecipientRole(e.target.value as HarvestRecipientRole)}
            className="w-full px-3 py-2 border rounded"
          >
            {ROLES.map((r) => (
              <option key={r.value} value={r.value}>
                {r.label}
              </option>
            ))}
          </select>
        </FormField>

        <p className="text-xs text-gray-500 -mt-2 mb-1">Cash vs moving product into a store (in-kind).</p>
        <FormField label="Settlement">
          <select
            value={settlement_mode}
            onChange={(e) => setSettlementMode(e.target.value as HarvestSettlementMode)}
            className="w-full px-3 py-2 border rounded"
          >
            {SETTLEMENTS.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </select>
        </FormField>

        {showInKindStores && (
          <>
            <FormField label="Destination store" required>
              <select
                value={store_id}
                onChange={(e) => setStoreId(e.target.value)}
                className="w-full px-3 py-2 border rounded"
                required={settlement_mode === 'IN_KIND'}
              >
                <option value="">Select store</option>
                {stores.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </FormField>
            <p className="text-xs text-gray-500 -mt-2 mb-1">Which product this bucket targets, if you need to be specific.</p>
            <FormField label="Product (optional)">
              <select
                value={inventory_item_id}
                onChange={(e) => setInventoryItemId(e.target.value)}
                className="w-full px-3 py-2 border rounded"
              >
                <option value="">Any / not specified</option>
                {items.map((i) => (
                  <option key={i.id} value={i.id}>
                    {i.name}
                  </option>
                ))}
              </select>
            </FormField>
          </>
        )}

        <FormField label="How the share is defined">
          <select
            value={share_basis}
            onChange={(e) => setShareBasis(e.target.value as HarvestShareBasis)}
            className="w-full px-3 py-2 border rounded"
          >
            {BASES.map((b) => (
              <option key={b.value} value={b.value}>
                {b.label}
              </option>
            ))}
          </select>
        </FormField>

        {showValue && (
          <FormField
            label={share_basis === 'PERCENT' ? 'Percent (0–100)' : 'Quantity'}
            required
          >
            <input
              type="number"
              step="any"
              min={share_basis === 'PERCENT' ? 0 : undefined}
              max={share_basis === 'PERCENT' ? 100 : undefined}
              value={share_value}
              onChange={(e) => setShareValue(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              required
            />
          </FormField>
        )}

        {showRatio && (
          <div className="grid grid-cols-2 gap-2">
            <FormField label="Ratio (top)" required>
              <input
                type="number"
                step="any"
                min="0"
                value={ratio_numerator}
                onChange={(e) => setRatioNumerator(e.target.value)}
                className="w-full px-3 py-2 border rounded"
                required
              />
            </FormField>
            <FormField label="Ratio (bottom)" required>
              <input
                type="number"
                step="any"
                min="0"
                value={ratio_denominator}
                onChange={(e) => setRatioDenominator(e.target.value)}
                className="w-full px-3 py-2 border rounded"
                required
              />
            </FormField>
          </div>
        )}

        {isRemainderBasis && (
          <p className="text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded px-3 py-2">
            This bucket receives whatever quantity is left on the line after all other buckets are calculated. Only one
            remainder bucket is allowed per line.
          </p>
        )}

        {showMachine && (
          <FormField label="Machine" required>
            <select
              value={machine_id}
              onChange={(e) => setMachineId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              required
            >
              <option value="">Select machine</option>
              {machines.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name || m.code}
                </option>
              ))}
            </select>
          </FormField>
        )}

        {showWorker && (
          <FormField label="Worker" required>
            <select
              value={worker_id}
              onChange={(e) => setWorkerId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              required
            >
              <option value="">Select worker</option>
              {workers.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </select>
          </FormField>
        )}

        {showBeneficiary && (
          <FormField label="Beneficiary (optional)">
            <select
              value={beneficiary_party_id}
              onChange={(e) => setBeneficiaryPartyId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
            >
              <option value="">—</option>
              {parties.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
        )}

        <p className="text-xs text-gray-500 -mt-2 mb-1">Lower numbers are applied first when splitting a line.</p>
        <FormField label="Sort order (optional)">
          <input
            type="number"
            value={sort_order}
            onChange={(e) => setSortOrder(e.target.value)}
            className="w-full px-3 py-2 border rounded"
            placeholder="Auto"
          />
        </FormField>

        <FormField label="Notes">
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className="w-full px-3 py-2 border rounded"
            rows={2}
          />
        </FormField>

        <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded">
            Cancel
          </button>
          <button
            type="submit"
            disabled={saving || harvestLines.length === 0}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded disabled:opacity-50"
          >
            {saving ? 'Saving…' : 'Save'}
          </button>
        </div>
      </form>
    </Modal>
  );
}

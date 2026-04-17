import { useState, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { PageHeader } from '../../components/PageHeader';
import { FormField } from '../../components/FormField';
import { useMachinesQuery, useCreateMachineryExternalIncome } from '../../hooks/useMachinery';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useParties } from '../../hooks/useParties';
import { useRole } from '../../hooks/useRole';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import type { CreateMachineryExternalIncomePayload } from '../../types';

/** Prefer customers for “rented out” flows; fall back to all parties if none tagged. */
function partyOptions(parties: { id: string; name: string; party_types?: string[] }[] | undefined) {
  if (!parties?.length) return [];
  const customers = parties.filter((p) => (p.party_types ?? []).includes('CUSTOMER'));
  return customers.length > 0 ? customers : parties;
}

export default function MachineryExternalIncomePage() {
  const { hasRole } = useRole();
  const canPost = hasRole(['tenant_admin', 'accountant']);

  const { data: machines, isLoading: loadingMachines } = useMachinesQuery();
  const { data: cycles, isLoading: loadingCycles } = useCropCycles();
  const { data: parties, isLoading: loadingParties } = useParties();
  const createM = useCreateMachineryExternalIncome();

  const [machineId, setMachineId] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [partyId, setPartyId] = useState('');
  const [amount, setAmount] = useState('');
  const [postingDate, setPostingDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [memo, setMemo] = useState('');
  const [lastResultId, setLastResultId] = useState<string | null>(null);

  const partyChoices = useMemo(() => partyOptions(parties), [parties]);

  const amountNum = amount !== '' ? parseFloat(amount) : NaN;
  const canSubmit =
    canPost &&
    machineId &&
    cropCycleId &&
    partyId &&
    !Number.isNaN(amountNum) &&
    amountNum > 0;

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setLastResultId(null);
    const payload: CreateMachineryExternalIncomePayload = {
      machine_id: machineId,
      crop_cycle_id: cropCycleId,
      party_id: partyId,
      amount: amountNum,
      posting_date: postingDate,
      memo: memo.trim() || undefined,
    };
    const res = await createM.mutateAsync(payload);
    setLastResultId(res.posting_group?.id ?? null);
  };

  const loading = loadingMachines || loadingCycles || loadingParties;

  return (
    <div className="space-y-6 pb-8 max-w-2xl">
      <PageHeader
        title="External machinery income"
        description="Record income when a machine does paid work for someone outside the farm (e.g. custom hire). This posts receivable and machine income—not a full invoice."
        backTo="/app/machinery"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Machinery', to: '/app/machinery' },
          { label: 'External income' },
        ]}
      />

      {!canPost && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
          Only tenant admins and accountants can post this entry. Ask your farm accountant to record external machinery income.
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow p-6 space-y-4">
          <div className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-800">
            <p className="font-medium text-gray-900">What this does</p>
            <p className="mt-1">
              The customer owes you (receivable), and the machine shows that amount as income. Cash receipt is recorded separately when they pay.
            </p>
          </div>

          <FormField label="Machine" required>
            <select
              value={machineId}
              onChange={(e) => setMachineId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              disabled={!canPost}
            >
              <option value="">Select machine</option>
              {machines?.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name} ({m.code})
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="Crop cycle" required>
            <select
              value={cropCycleId}
              onChange={(e) => setCropCycleId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              disabled={!canPost}
            >
              <option value="">Select season</option>
              {cycles?.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="Customer / party" required>
            <select
              value={partyId}
              onChange={(e) => setPartyId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              disabled={!canPost}
            >
              <option value="">Select customer</option>
              {partyChoices.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
            <p className="mt-1 text-xs text-gray-500">Uses customer-type parties when available; otherwise all parties.</p>
          </FormField>

          <FormField label="Amount" required>
            <input
              type="number"
              step="0.01"
              min="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              className="w-full px-3 py-2 border rounded tabular-nums"
              disabled={!canPost}
            />
          </FormField>

          <FormField label="Posting date" required>
            <input
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              disabled={!canPost}
            />
          </FormField>

          <FormField label="Notes (optional)">
            <textarea
              value={memo}
              onChange={(e) => setMemo(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              rows={2}
              disabled={!canPost}
              placeholder="e.g. Baling for neighbour, north field"
            />
          </FormField>

          {lastResultId && (
            <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-950">
              <p className="font-medium">Posted successfully.</p>
              <p className="mt-1">
                View posting:{' '}
                <Link to={`/app/posting-groups/${lastResultId}`} className="text-[#1F6F5C] font-medium underline">
                  {lastResultId}
                </Link>
              </p>
            </div>
          )}

          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-2">
            <Link
              to="/app/machinery"
              className="w-full sm:w-auto px-4 py-2 border border-gray-300 rounded-lg text-center hover:bg-gray-50"
            >
              Cancel
            </Link>
            <button
              type="button"
              onClick={handleSubmit}
              disabled={!canSubmit || createM.isPending}
              className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {createM.isPending ? 'Posting…' : 'Record income'}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

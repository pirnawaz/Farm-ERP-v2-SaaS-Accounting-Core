import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  useCreateWorkLog,
  useUpdateWorkLog,
  useWorkLogQuery,
  useMachinesQuery,
} from '../../hooks/useMachinery';
import { useProjects } from '../../hooks/useProjects';
import { useParties } from '../../hooks/useParties';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import type { MachineWorkLogCostCode } from '../../types';

type LineRow = {
  cost_code: MachineWorkLogCostCode;
  description: string;
  amount: string;
  party_id: string;
};

const COST_CODES: MachineWorkLogCostCode[] = ['FUEL', 'OPERATOR', 'MAINTENANCE', 'OTHER'];

function emptyLine(): LineRow {
  return { cost_code: 'FUEL', description: '', amount: '', party_id: '' };
}

export default function WorkLogFormPage() {
  const { id } = useParams<{ id: string }>();
  const isEdit = Boolean(id && id !== 'new');
  const navigate = useNavigate();
  const { data: workLog, isLoading: loadingLog } = useWorkLogQuery(id ?? '');
  const { data: machines } = useMachinesQuery();
  const { data: projects } = useProjects();
  const { data: parties } = useParties();
  const createM = useCreateWorkLog();
  const updateM = useUpdateWorkLog();
  const { formatMoney } = useFormatting();

  const [machine_id, setMachineId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [work_date, setWorkDate] = useState(new Date().toISOString().split('T')[0]);
  const [meter_start, setMeterStart] = useState('');
  const [meter_end, setMeterEnd] = useState('');
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<LineRow[]>([emptyLine()]);

  useEffect(() => {
    if (!workLog || !isEdit) return;
    setMachineId(workLog.machine_id);
    setProjectId(workLog.project_id);
    setWorkDate(workLog.work_date ?? new Date().toISOString().split('T')[0]);
    setMeterStart(workLog.meter_start ?? '');
    setMeterEnd(workLog.meter_end ?? '');
    setNotes(workLog.notes ?? '');
    const lns = (workLog.lines ?? []).map((l) => ({
      cost_code: (l.cost_code as MachineWorkLogCostCode) ?? 'FUEL',
      description: l.description ?? '',
      amount: String(l.amount ?? ''),
      party_id: l.party_id ?? '',
    }));
    setLines(lns.length ? lns : [emptyLine()]);
  }, [workLog, isEdit]);

  const selectedProject = projects?.find((p) => p.id === project_id);
  const usageQty =
    meter_start !== '' && meter_end !== ''
      ? Math.max(0, parseFloat(meter_end) - parseFloat(meter_start))
      : 0;

  const addLine = () => setLines((l) => [...l, emptyLine()]);
  const removeLine = (i: number) => setLines((l) => (l.length > 1 ? l.filter((_, idx) => idx !== i) : l));
  const updateLine = (i: number, f: Partial<LineRow>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const validLines = lines.filter(
    (l) => l.cost_code && parseFloat(l.amount) > 0
  );
  const totalAmount = validLines.reduce((s, l) => s + parseFloat(l.amount), 0);
  const meterValid =
    meter_start === '' ||
    meter_end === '' ||
    parseFloat(meter_end) >= parseFloat(meter_start);
  const canSubmit =
    machine_id &&
    project_id &&
    validLines.length >= 1 &&
    validLines.every((l) => parseFloat(l.amount) > 0) &&
    meterValid;

  const handleSubmit = async () => {
    if (!canSubmit) return;
    const payload = {
      machine_id,
      project_id,
      work_date: work_date || undefined,
      meter_start: meter_start !== '' ? parseFloat(meter_start) : undefined,
      meter_end: meter_end !== '' ? parseFloat(meter_end) : undefined,
      notes: notes || undefined,
      lines: validLines.map((l) => ({
        cost_code: l.cost_code,
        description: l.description || undefined,
        amount: parseFloat(l.amount),
        party_id: l.party_id || undefined,
      })),
    };

    if (isEdit && id) {
      await updateM.mutateAsync({ id, payload });
      navigate(`/app/machinery/work-logs/${id}`);
    } else {
      const created = await createM.mutateAsync(payload);
      navigate(`/app/machinery/work-logs/${created.id}`);
    }
  };

  if (isEdit && loadingLog) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }
  if (isEdit && id && !workLog) {
    return <div>Work log not found.</div>;
  }
  if (isEdit && workLog && workLog.status !== 'DRAFT') {
    return <div>Only DRAFT work logs can be edited.</div>;
  }

  return (
    <div>
      <PageHeader
        title={isEdit ? `Edit Work Log ${workLog?.work_log_no ?? ''}` : 'New Work Log'}
        backTo={isEdit ? `/app/machinery/work-logs/${id}` : '/app/machinery/work-logs'}
        breadcrumbs={[
          { label: 'Machinery', to: '/app/machinery' },
          { label: 'Work Logs', to: '/app/machinery/work-logs' },
          { label: isEdit ? (workLog?.work_log_no ?? 'Edit') : 'New' },
        ]}
      />
      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Machine" required>
            <select
              value={machine_id}
              onChange={(e) => setMachineId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              disabled={isEdit}
            >
              <option value="">Select machine</option>
              {machines?.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name} ({m.code})
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Project" required>
            <select
              value={project_id}
              onChange={(e) => setProjectId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              disabled={isEdit}
            >
              <option value="">Select project</option>
              {projects?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Crop cycle (from project)">
            <span className="text-gray-700">
              {selectedProject?.crop_cycle?.name ?? '—'}
            </span>
          </FormField>
          <FormField label="Work date">
            <input
              type="date"
              value={work_date}
              onChange={(e) => setWorkDate(e.target.value)}
              className="w-full px-3 py-2 border rounded"
            />
          </FormField>
          <FormField label="Meter start">
            <input
              type="number"
              step="0.01"
              min="0"
              value={meter_start}
              onChange={(e) => setMeterStart(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              placeholder="0"
            />
          </FormField>
          <FormField label="Meter end">
            <input
              type="number"
              step="0.01"
              min="0"
              value={meter_end}
              onChange={(e) => setMeterEnd(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              placeholder="0"
            />
          </FormField>
          <FormField label="Usage (computed)">
            <span className="tabular-nums">{usageQty}</span>
            {!meterValid && meter_start !== '' && meter_end !== '' && (
              <p className="mt-1 text-sm text-red-600">Meter end must be ≥ meter start.</p>
            )}
          </FormField>
          <div className="md:col-span-2">
            <FormField label="Notes">
              <textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                className="w-full px-3 py-2 border rounded"
                rows={2}
              />
            </FormField>
          </div>
        </div>

        <div>
          <div className="flex justify-between items-center mb-2">
            <h3 className="font-medium">Cost lines</h3>
            <button
              type="button"
              onClick={addLine}
              className="text-sm text-[#1F6F5C] hover:text-[#1a5a4a]"
            >
              + Add line
            </button>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full border">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-3 py-2 text-left text-xs text-gray-500">Cost code</th>
                  <th className="px-3 py-2 text-left text-xs text-gray-500">Description</th>
                  <th className="px-3 py-2 text-left text-xs text-gray-500">Amount</th>
                  <th className="px-3 py-2 text-left text-xs text-gray-500">Party</th>
                  <th className="w-10" />
                </tr>
              </thead>
              <tbody>
                {lines.map((line, i) => (
                  <tr key={i}>
                    <td className="px-3 py-2">
                      <select
                        value={line.cost_code}
                        onChange={(e) =>
                          updateLine(i, { cost_code: e.target.value as MachineWorkLogCostCode })
                        }
                        className="w-full px-2 py-1 border rounded text-sm"
                      >
                        {COST_CODES.map((c) => (
                          <option key={c} value={c}>
                            {c}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="px-3 py-2">
                      <input
                        value={line.description}
                        onChange={(e) => updateLine(i, { description: e.target.value })}
                        className="w-full px-2 py-1 border rounded text-sm"
                        placeholder="Optional"
                      />
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        value={line.amount}
                        onChange={(e) => updateLine(i, { amount: e.target.value })}
                        className="w-28 px-2 py-1 border rounded text-sm"
                      />
                    </td>
                    <td className="px-3 py-2">
                      <select
                        value={line.party_id}
                        onChange={(e) => updateLine(i, { party_id: e.target.value })}
                        className="w-full px-2 py-1 border rounded text-sm"
                      >
                        <option value="">—</option>
                        {parties?.map((p) => (
                          <option key={p.id} value={p.id}>
                            {p.name}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td>
                      <button
                        type="button"
                        onClick={() => removeLine(i)}
                        className="text-red-600 text-sm"
                      >
                        Del
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-2 text-sm font-medium">
            Total: <span className="tabular-nums">{formatMoney(totalAmount)}</span>
          </p>
        </div>

        <div className="flex gap-2 pt-4">
          <button
            type="button"
            onClick={() => navigate(isEdit ? `/app/machinery/work-logs/${id}` : '/app/machinery/work-logs')}
            className="px-4 py-2 border rounded"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSubmit}
            disabled={!canSubmit || createM.isPending || updateM.isPending}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {isEdit ? (updateM.isPending ? 'Saving…' : 'Save') : createM.isPending ? 'Creating…' : 'Create'}
          </button>
        </div>
      </div>
    </div>
  );
}

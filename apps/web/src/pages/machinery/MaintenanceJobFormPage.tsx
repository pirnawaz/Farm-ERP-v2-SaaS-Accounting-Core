import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  useCreateMaintenanceJob,
  useUpdateMaintenanceJob,
  useMaintenanceJobQuery,
  useMaintenanceTypesQuery,
} from '../../hooks/useMachinery';
import { useMachinesQuery } from '../../hooks/useMachinery';
import { useParties } from '../../hooks/useParties';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import type { CreateMachineMaintenanceJobPayload, UpdateMachineMaintenanceJobPayload } from '../../types';

type Line = { description: string; amount: string };

export default function MaintenanceJobFormPage() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const isEdit = !!id;
  const createM = useCreateMaintenanceJob();
  const updateM = useUpdateMaintenanceJob();
  const { data: job } = useMaintenanceJobQuery(id || '');
  const { data: machines } = useMachinesQuery();
  const { data: maintenanceTypes } = useMaintenanceTypesQuery({ is_active: true });
  const { data: parties } = useParties();
  const { formatMoney } = useFormatting();

  const [machine_id, setMachineId] = useState('');
  const [maintenance_type_id, setMaintenanceTypeId] = useState('');
  const [vendor_party_id, setVendorPartyId] = useState('');
  const [job_date, setJobDate] = useState(new Date().toISOString().split('T')[0]);
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<Line[]>([{ description: '', amount: '' }]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  // Initialize form from job when editing
  useEffect(() => {
    if (job && isEdit) {
      setMachineId(job.machine_id);
      setMaintenanceTypeId(job.maintenance_type_id || '');
      setVendorPartyId(job.vendor_party_id || '');
      setJobDate(job.job_date);
      setNotes(job.notes || '');
      setLines(
        job.lines && job.lines.length > 0
          ? job.lines.map((l) => ({ description: l.description || '', amount: l.amount }))
          : [{ description: '', amount: '' }]
      );
    }
  }, [job, isEdit]);

  const isDraft = job?.status === 'DRAFT';
  const canEdit = isEdit ? isDraft : true;

  const addLine = () => setLines((l) => [...l, { description: '', amount: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<Line>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!machine_id) e.machine_id = 'Machine is required';
    if (!job_date) e.job_date = 'Job date is required';
    const validLines = lines.filter((l) => parseFloat(l.amount) > 0);
    if (validLines.length === 0) e.lines = 'At least one line with amount > 0 is required';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const totalAmount = lines.reduce((sum, line) => sum + (parseFloat(line.amount) || 0), 0);

  const handleSubmit = async () => {
    if (!validate()) return;
    const validLines = lines
      .filter((l) => parseFloat(l.amount) > 0)
      .map((l) => ({
        description: l.description || undefined,
        amount: parseFloat(l.amount),
      }));

    if (isEdit && id) {
      const payload: UpdateMachineMaintenanceJobPayload = {
        maintenance_type_id: maintenance_type_id || undefined,
        vendor_party_id: vendor_party_id || undefined,
        job_date,
        notes: notes || undefined,
        lines: validLines,
      };
      await updateM.mutateAsync({ id, payload });
      navigate(`/app/machinery/maintenance-jobs/${id}`);
    } else {
      const payload: CreateMachineMaintenanceJobPayload = {
        machine_id,
        maintenance_type_id: maintenance_type_id || undefined,
        vendor_party_id: vendor_party_id || undefined,
        job_date,
        notes: notes || undefined,
        lines: validLines,
      };
      const newJob = await createM.mutateAsync(payload);
      navigate(`/app/machinery/maintenance-jobs/${newJob.id}`);
    }
  };

  return (
    <div>
      <PageHeader
        title={isEdit ? 'Edit Maintenance Job' : 'New Maintenance Job'}
        backTo="/app/machinery/maintenance-jobs"
        breadcrumbs={[
          { label: 'Machinery', to: '/app/machinery' },
          { label: 'Maintenance Jobs', to: '/app/machinery/maintenance-jobs' },
          { label: isEdit ? 'Edit' : 'New' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Machine" required error={errors.machine_id}>
            <select
              value={machine_id}
              onChange={(e) => setMachineId(e.target.value)}
              disabled={!canEdit || isEdit}
              className="w-full px-3 py-2 border rounded disabled:bg-gray-100"
            >
              <option value="">Select machine</option>
              {machines?.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.code} - {m.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Maintenance Type">
            <select
              value={maintenance_type_id}
              onChange={(e) => setMaintenanceTypeId(e.target.value)}
              disabled={!canEdit}
              className="w-full px-3 py-2 border rounded disabled:bg-gray-100"
            >
              <option value="">Select type (optional)</option>
              {maintenanceTypes?.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Vendor Party">
            <select
              value={vendor_party_id}
              onChange={(e) => setVendorPartyId(e.target.value)}
              disabled={!canEdit}
              className="w-full px-3 py-2 border rounded disabled:bg-gray-100"
            >
              <option value="">Select vendor (optional)</option>
              {parties?.filter((p) => p.party_types?.includes('VENDOR')).map((party) => (
                <option key={party.id} value={party.id}>
                  {party.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Job Date" required error={errors.job_date}>
            <input
              type="date"
              value={job_date}
              onChange={(e) => setJobDate(e.target.value)}
              disabled={!canEdit}
              className="w-full px-3 py-2 border rounded disabled:bg-gray-100"
            />
          </FormField>
          <FormField label="Notes" className="md:col-span-2">
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              disabled={!canEdit}
              className="w-full px-3 py-2 border rounded disabled:bg-gray-100"
              rows={2}
            />
          </FormField>
        </div>

        <div>
          <div className="flex justify-between items-center mb-2">
            <h3 className="font-medium">Lines</h3>
            {canEdit && (
              <button type="button" onClick={addLine} className="text-sm text-[#1F6F5C]">
                + Add line
              </button>
            )}
          </div>
          {errors.lines && <p className="text-sm text-red-600 mb-2">{errors.lines}</p>}
          <div className="overflow-x-auto">
            <table className="min-w-full border">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Description</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Amount</th>
                  <th className="px-3 py-2 w-10" />
                </tr>
              </thead>
              <tbody>
                {lines.map((line, idx) => (
                  <tr key={idx}>
                    <td className="px-3 py-2">
                      <input
                        type="text"
                        value={line.description}
                        onChange={(e) => updateLine(idx, { description: e.target.value })}
                        disabled={!canEdit}
                        className="w-full px-2 py-1 border rounded disabled:bg-gray-100"
                        placeholder="Description"
                      />
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        value={line.amount}
                        onChange={(e) => updateLine(idx, { amount: e.target.value })}
                        disabled={!canEdit}
                        className="w-full px-2 py-1 border rounded text-right tabular-nums disabled:bg-gray-100"
                        placeholder="0.00"
                      />
                    </td>
                    <td className="px-3 py-2">
                      {canEdit && lines.length > 1 && (
                        <button
                          type="button"
                          onClick={() => removeLine(idx)}
                          className="text-red-600 hover:text-red-800"
                        >
                          ×
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="bg-gray-50">
                <tr>
                  <td className="px-3 py-2 text-right font-medium" colSpan={2}>
                    Total: <span className="tabular-nums">{formatMoney(totalAmount)}</span>
                  </td>
                  <td />
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        {!isEdit || isDraft ? (
          <div className="flex gap-2 pt-4">
            <button
              type="button"
              onClick={() => navigate('/app/machinery/maintenance-jobs')}
              className="px-4 py-2 border rounded"
            >
              Cancel
            </button>
            <button
              onClick={handleSubmit}
              disabled={
                (isEdit ? updateM.isPending : createM.isPending) ||
                !machine_id ||
                !job_date ||
                totalAmount <= 0 ||
                !canEdit
              }
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {isEdit ? (updateM.isPending ? 'Saving...' : 'Save') : createM.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        ) : (
          <div className="pt-4 text-sm text-gray-600">
            This maintenance job cannot be edited because it is not in DRAFT status.
          </div>
        )}
      </div>
    </div>
  );
}

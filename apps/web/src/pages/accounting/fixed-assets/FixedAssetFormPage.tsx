import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { Project } from '@farm-erp/shared';
import { apiClient } from '@farm-erp/shared';
import { fixedAssetsApi } from '../../../api/fixedAssets';
import { PageHeader } from '../../../components/PageHeader';

export default function FixedAssetFormPage() {
  const navigate = useNavigate();
  const [projects, setProjects] = useState<Project[]>([]);
  const [loadingProjects, setLoadingProjects] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [projectId, setProjectId] = useState('');
  const [assetCode, setAssetCode] = useState('');
  const [name, setName] = useState('');
  const [category, setCategory] = useState('');
  const [acquisitionDate, setAcquisitionDate] = useState('');
  const [inServiceDate, setInServiceDate] = useState('');
  const [currencyCode, setCurrencyCode] = useState('USD');
  const [acquisitionCost, setAcquisitionCost] = useState('');
  const [residualValue, setResidualValue] = useState('0');
  const [usefulLifeMonths, setUsefulLifeMonths] = useState('60');
  const [notes, setNotes] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const list = await apiClient.get<Project[]>('/api/projects');
        if (!cancelled) setProjects(list);
      } catch {
        if (!cancelled) setProjects([]);
      } finally {
        if (!cancelled) setLoadingProjects(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    const cost = parseFloat(acquisitionCost);
    const life = parseInt(usefulLifeMonths, 10);
    const res = parseFloat(residualValue || '0');
    if (!assetCode.trim() || !name.trim() || !category.trim() || !acquisitionDate) {
      setError('Asset code, name, category, and acquisition date are required.');
      return;
    }
    if (!Number.isFinite(cost) || cost < 0) {
      setError('Acquisition cost must be a valid non-negative number.');
      return;
    }
    if (!Number.isFinite(life) || life < 1) {
      setError('Useful life (months) must be at least 1.');
      return;
    }
    if (!Number.isFinite(res) || res < 0) {
      setError('Residual value must be valid.');
      return;
    }
    try {
      setSubmitting(true);
      const created = await fixedAssetsApi.create({
        project_id: projectId || null,
        asset_code: assetCode.trim(),
        name: name.trim(),
        category: category.trim(),
        acquisition_date: acquisitionDate,
        in_service_date: inServiceDate || null,
        currency_code: currencyCode.trim().toUpperCase().slice(0, 3),
        acquisition_cost: cost,
        residual_value: res,
        useful_life_months: life,
        depreciation_method: 'STRAIGHT_LINE',
        notes: notes.trim() || null,
      });
      navigate(`/app/accounting/fixed-assets/${created.id}`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create asset');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="space-y-6 max-w-3xl">
      <PageHeader
        title="New fixed asset (draft)"
        backTo="/app/accounting/fixed-assets"
        breadcrumbs={[
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'Fixed assets', to: '/app/accounting/fixed-assets' },
          { label: 'New' },
        ]}
      />

      <form onSubmit={submit} className="bg-white rounded-lg shadow p-6 space-y-4">
        {error && (
          <div className="bg-red-50 border border-red-200 text-red-800 rounded-md p-3 text-sm" role="alert">
            {error}
          </div>
        )}

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Project (optional)</label>
          <select
            className="w-full border rounded-md px-3 py-2"
            value={projectId}
            onChange={(e) => setProjectId(e.target.value)}
            disabled={loadingProjects}
          >
            <option value="">— None —</option>
            {projects.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Asset code *</label>
            <input
              className="w-full border rounded-md px-3 py-2"
              value={assetCode}
              onChange={(e) => setAssetCode(e.target.value)}
              required
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Name *</label>
            <input
              className="w-full border rounded-md px-3 py-2"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
          </div>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Category *</label>
          <input
            className="w-full border rounded-md px-3 py-2"
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            placeholder="e.g. Tractor, Building"
            required
          />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Acquisition date *</label>
            <input
              type="date"
              className="w-full border rounded-md px-3 py-2"
              value={acquisitionDate}
              onChange={(e) => setAcquisitionDate(e.target.value)}
              required
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">In service date</label>
            <input
              type="date"
              className="w-full border rounded-md px-3 py-2"
              value={inServiceDate}
              onChange={(e) => setInServiceDate(e.target.value)}
            />
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Currency *</label>
            <input
              className="w-full border rounded-md px-3 py-2 uppercase"
              maxLength={3}
              value={currencyCode}
              onChange={(e) => setCurrencyCode(e.target.value)}
              required
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Acquisition cost *</label>
            <input
              type="number"
              step="0.01"
              min="0"
              className="w-full border rounded-md px-3 py-2"
              value={acquisitionCost}
              onChange={(e) => setAcquisitionCost(e.target.value)}
              required
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Residual value</label>
            <input
              type="number"
              step="0.01"
              min="0"
              className="w-full border rounded-md px-3 py-2"
              value={residualValue}
              onChange={(e) => setResidualValue(e.target.value)}
            />
          </div>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Useful life (months) *</label>
          <input
            type="number"
            min={1}
            className="w-full border rounded-md px-3 py-2 sm:max-w-xs"
            value={usefulLifeMonths}
            onChange={(e) => setUsefulLifeMonths(e.target.value)}
            required
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Depreciation method</label>
          <input className="w-full border rounded-md px-3 py-2 bg-gray-50" readOnly value="Straight line (STRAIGHT_LINE)" />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
          <textarea
            className="w-full border rounded-md px-3 py-2"
            rows={3}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
          />
        </div>

        <div className="flex gap-3 pt-2">
          <button
            type="submit"
            disabled={submitting}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {submitting ? 'Saving…' : 'Create draft'}
          </button>
          <button
            type="button"
            onClick={() => navigate('/app/accounting/fixed-assets')}
            className="px-4 py-2 border border-gray-300 rounded-md"
          >
            Cancel
          </button>
        </div>
      </form>
    </div>
  );
}

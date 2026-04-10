import { useHarvestEconomicsDocument } from '../../hooks/useReports';
import { useModules } from '../../contexts/ModulesContext';
import { LoadingSpinner } from '../LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';

const SHARE_LABELS: Record<string, string> = {
  machine: 'Machinery',
  labour: 'Labour',
  landlord: 'Landlord',
  contractor: 'Contractor',
};

type Props = {
  harvestId: string;
};

/**
 * Posted harvest only: value of output, what stayed on farm vs shared — from reporting API (read-only).
 */
export function HarvestEconomicsCard({ harvestId }: Props) {
  const { isModuleEnabled } = useModules();
  const reportsOn = isModuleEnabled('reports');
  const { formatMoney } = useFormatting();
  const { data, isLoading, error } = useHarvestEconomicsDocument(harvestId, { enabled: reportsOn });

  if (!reportsOn) return null;

  if (isLoading) {
    return (
      <div className="bg-white rounded-lg shadow p-6 flex justify-center py-10">
        <LoadingSpinner />
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-900">
        Could not load harvest value breakdown. You may need access to reports.
      </div>
    );
  }

  if (!data) return null;

  const e = data.economics;
  const shared = e.shared;
  const sharedValueTotal = Object.values(shared).reduce((s, x) => s + (x?.value ?? 0), 0);
  const sharedEntries = Object.entries(SHARE_LABELS)
    .map(([key, label]) => ({
      key,
      label,
      qty: shared[key as keyof typeof shared]?.quantity ?? 0,
      value: shared[key as keyof typeof shared]?.value ?? 0,
    }))
    .filter((x) => x.value > 0 || x.qty > 0);

  return (
    <div className="bg-white rounded-lg shadow overflow-hidden">
      <div className="bg-gradient-to-r from-[#1F6F5C]/10 to-transparent px-6 py-4 border-b border-gray-100">
        <h3 className="text-lg font-semibold text-gray-900">Harvest value</h3>
        <p className="text-sm text-gray-600 mt-0.5">Totals from when this harvest was posted — who received what.</p>
      </div>
      <div className="p-6 space-y-6">
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div className="rounded-lg border border-gray-100 bg-slate-50/80 p-4">
            <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">Total crop value</p>
            <p className="text-xl font-semibold tabular-nums text-gray-900 mt-1">{formatMoney(e.total_output_value)}</p>
            <p className="text-xs text-gray-500 mt-1 tabular-nums">{e.total_output_qty.toFixed(3)} units</p>
          </div>
          <div className="rounded-lg border border-teal-100 bg-teal-50/60 p-4">
            <p className="text-xs font-medium text-teal-800">Kept on farm</p>
            <p className="text-xl font-semibold tabular-nums text-teal-900 mt-1">{formatMoney(e.retained_value)}</p>
            <p className="text-xs text-teal-700/80 mt-1 tabular-nums">{e.retained_qty.toFixed(3)} units</p>
          </div>
          <div className="rounded-lg border border-violet-100 bg-violet-50/50 p-4">
            <p className="text-xs font-medium text-violet-800">Shared out</p>
            <p className="text-xl font-semibold tabular-nums text-violet-900 mt-1">{formatMoney(sharedValueTotal)}</p>
            <p className="text-xs text-violet-700/80 mt-1">Value to others</p>
          </div>
        </div>

        {sharedEntries.length > 0 && (
          <div>
            <h4 className="text-sm font-semibold text-gray-800 mb-3">Shared with</h4>
            <div className="overflow-hidden rounded-lg border border-gray-200">
              <table className="min-w-full text-sm">
                <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wider">
                  <tr>
                    <th className="px-4 py-2 font-medium">Who</th>
                    <th className="px-4 py-2 font-medium text-right">Quantity</th>
                    <th className="px-4 py-2 font-medium text-right">Value</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 bg-white">
                  {sharedEntries.map((row) => (
                    <tr key={row.key}>
                      <td className="px-4 py-2.5 text-gray-900">{row.label}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums text-gray-700">{row.qty.toFixed(3)}</td>
                      <td className="px-4 py-2.5 text-right tabular-nums font-medium text-gray-900">{formatMoney(row.value)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

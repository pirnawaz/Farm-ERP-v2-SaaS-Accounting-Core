import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { useAlertPreferences } from '../../hooks/useAlertPreferences';
import { getActiveCropCycleId } from '../../utils/formDefaults';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';

export default function NegativeMarginFieldsAlertPage() {
  const today = useMemo(() => new Date().toISOString().split('T')[0], []);
  const { formatMoney } = useFormatting();
  const { preferences } = useAlertPreferences();
  const threshold = preferences.negativeMarginThreshold;

  const { data: cropCycles } = useCropCycles();
  const activeCycleId = useMemo(() => getActiveCropCycleId(cropCycles), [cropCycles]);
  const activeCycle = useMemo(
    () => cropCycles?.find((c) => c.id === activeCycleId),
    [cropCycles, activeCycleId]
  );
  const cycleStart = activeCycle?.start_date ?? today;
  const cycleEnd =
    activeCycle?.end_date && activeCycle.end_date < today ? activeCycle.end_date : today;

  const { data: projectPLRows = [], isLoading } = useQuery({
    queryKey: ['reports', 'project-pl', { from: cycleStart, to: cycleEnd }],
    queryFn: () => apiClient.getProjectPL({ from: cycleStart, to: cycleEnd }),
    enabled: !!cycleStart && !!cycleEnd,
    staleTime: 2 * 60 * 1000,
  });

  const { data: projectsForCycle } = useProjects(activeCycleId ?? undefined);
  const projectNameById = useMemo(() => {
    const m: Record<string, string> = {};
    (projectsForCycle ?? []).forEach((p) => {
      m[p.id] = p.name;
    });
    return m;
  }, [projectsForCycle]);

  const projectIdsInCycle = useMemo(
    () => new Set((projectsForCycle ?? []).map((p) => p.id)),
    [projectsForCycle]
  );

  const rows = useMemo(() => {
    return projectPLRows
      .filter(
        (row) =>
          (!activeCycleId || projectIdsInCycle.has(row.project_id)) &&
          parseFloat(row.net_profit) < threshold
      )
      .map((row) => ({
        project_id: row.project_id,
        name: projectNameById[row.project_id] || row.project_id,
        cost: parseFloat(row.expenses),
        revenue: parseFloat(row.income),
        margin: parseFloat(row.net_profit),
      }))
      .sort((a, b) => a.margin - b.margin);
  }, [projectPLRows, projectNameById, activeCycleId, projectIdsInCycle, threshold]);

  if (isLoading) {
    return (
      <div className="max-w-2xl mx-auto pb-24 sm:pb-6">
        <PageHeader
          title="Negative margin fields"
          backTo="/app/alerts"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Alerts', to: '/app/alerts' },
            { label: 'Negative margin fields' },
          ]}
        />
        <div className="flex justify-center py-12">
          <LoadingSpinner />
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-2xl mx-auto pb-24 sm:pb-6">
      <PageHeader
        title="Negative margin fields"
        backTo="/app/alerts"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Alerts', to: '/app/alerts' },
          { label: 'Negative margin fields' },
        ]}
      />

      <p className="text-sm text-gray-500 mb-4">
        Projects with margin &lt; {formatMoney(threshold)} in the active cycle
        {activeCycle ? ` (${activeCycle.name}: ${cycleStart} – ${cycleEnd})` : ''}.
      </p>

      {rows.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-500">
          No projects below the threshold. Adjust alert settings or view the full report.
        </div>
      ) : (
        <div className="rounded-xl border border-gray-200 bg-white overflow-hidden mb-6">
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left font-medium text-gray-600">Field / Project</th>
                  <th className="px-3 py-2 text-right font-medium text-gray-600">Cost</th>
                  <th className="px-3 py-2 text-right font-medium text-gray-600">Revenue</th>
                  <th className="px-3 py-2 text-right font-medium text-gray-600">Margin</th>
                  <th className="px-3 py-2 w-20" aria-label="Actions" />
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.project_id} className="border-t border-gray-100">
                    <td className="px-3 py-2">
                      <Link
                        to={`/app/projects/${row.project_id}`}
                        className="text-[#1F6F5C] hover:underline font-medium"
                      >
                        {row.name}
                      </Link>
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatMoney(row.cost)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatMoney(row.revenue)}</td>
                    <td className="px-3 py-2 text-right tabular-nums text-red-600">
                      {formatMoney(row.margin)}
                    </td>
                    <td className="px-3 py-2">
                      <Link
                        to={`/app/projects/${row.project_id}`}
                        className="text-[#1F6F5C] text-xs font-medium hover:underline"
                      >
                        View
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="flex flex-wrap gap-3">
        <Link
          to={`/app/reports/crop-profitability?from=${cycleStart}&to=${cycleEnd}`}
          className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          Crop profitability report
        </Link>
        <Link
          to="/app/reports/project-pl"
          className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          Project P&L
        </Link>
      </div>
    </div>
  );
}

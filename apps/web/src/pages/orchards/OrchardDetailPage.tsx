import { useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useProductionUnit } from '../../hooks/useProductionUnits';
import { useProductionUnitSummary } from '../../hooks/useReports';
import { useQuery } from '@tanstack/react-query';
import { harvestsApi } from '../../api/harvests';
import { salesApi } from '../../api/sales';
import { PageHeader } from '../../components/PageHeader';
import { EmptyState } from '../../components/EmptyState';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { KpiCard, KpiGrid } from '../../components/KpiCard';
import { useFormatting } from '../../hooks/useFormatting';
import { useOrchardLivestockAddonsEnabled } from '../../hooks/useModules';
import type { Harvest, Sale } from '../../types';

const currentYear = new Date().getFullYear();

export default function OrchardDetailPage() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const [searchParams] = useSearchParams();
  const { showOrchards } = useOrchardLivestockAddonsEnabled();
  const yearParam = searchParams.get('year');
  const [year, setYear] = useState(() => (yearParam ? parseInt(yearParam, 10) : currentYear));
  const { formatMoney } = useFormatting();

  if (!showOrchards) {
    return (
      <div className="space-y-6" data-testid="orchard-detail-page">
        <PageHeader
          title="Orchard"
          backTo="/app/dashboard"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Orchards', to: '/app/orchards' },
            { label: 'Orchard' },
          ]}
        />
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
          Orchards module is not enabled for this tenant.
        </div>
      </div>
    );
  }

  const { data: unit, isLoading: unitLoading } = useProductionUnit(id || '');
  const from = `${year}-01-01`;
  const to = `${year}-12-31`;
  const { data: summary, isLoading: summaryLoading } = useProductionUnitSummary(
    { production_unit_id: id!, from, to },
    { enabled: !!id }
  );

  const { data: harvests = [] } = useQuery({
    queryKey: ['harvests', { production_unit_id: id, from, to }],
    queryFn: () => harvestsApi.list({ production_unit_id: id!, from, to }),
    enabled: !!id,
  });

  const { data: sales = [] } = useQuery({
    queryKey: ['sales', { production_unit_id: id, date_from: from, date_to: to }],
    queryFn: () => salesApi.list({ production_unit_id: id!, date_from: from, date_to: to }),
    enabled: !!id,
  });

  const yearOptions = useMemo(() => {
    const options: number[] = [];
    for (let y = currentYear; y >= currentYear - 10; y--) options.push(y);
    return options;
  }, []);

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
          title="Orchard not found"
          description="This orchard may have been deleted, or you may not have access."
          action={{ label: 'Back to Orchards', onClick: () => navigate('/app/orchards') }}
        />
      </div>
    );
  }

  return (
    <div className="space-y-6" data-testid="orchard-detail-page">
      <PageHeader
        title={unit.name}
        description="Long-lived orchard block for tagging work, harvests, and economics by year."
        helper="Costs and revenue here roll up from activities and sales tagged to this unit."
        backTo="/app/orchards"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Orchards', to: '/app/orchards' },
          { label: unit.name },
        ]}
      />

      {(unit.orchard_crop || unit.planting_year != null) && (
        <p className="text-sm text-gray-600">
          {unit.orchard_crop && <span>{unit.orchard_crop}</span>}
          {unit.planting_year != null && <span className={unit.orchard_crop ? ' ml-2' : ''}>Planted {unit.planting_year}</span>}
          {unit.area_acres != null && unit.area_acres !== '' && <span> · {unit.area_acres} acres</span>}
          {unit.tree_count != null && <span> · {unit.tree_count} trees</span>}
        </p>
      )}

      {/* Year selector */}
      <div className="flex flex-wrap gap-4 items-end">
        <span className="text-sm font-medium text-gray-700">Year:</span>
        <select
          value={year}
          onChange={(e) => setYear(parseInt(e.target.value, 10))}
          className="px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
        >
          {yearOptions.map((y) => (
            <option key={y} value={y}>
              {y}
            </option>
          ))}
        </select>
      </div>

      {/* Cost, Revenue, Margin */}
      <section>
        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Economics ({year})</h2>
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

      {/* Quick Actions */}
      <section>
        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Quick Actions</h2>
        <div className="flex flex-wrap gap-3">
          <Link
            to={`/app/harvests/new?production_unit_id=${id}`}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] text-sm font-medium"
          >
            Add harvest
          </Link>
          <Link
            to={`/app/labour/work-logs/new?production_unit_id=${id}`}
            className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium"
          >
            Log work
          </Link>
          <Link
            to={`/app/crop-ops/activities/new?production_unit_id=${id}`}
            className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium"
          >
            Log activity
          </Link>
          <Link
            to={`/app/sales/new?production_unit_id=${id}`}
            className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-sm font-medium"
          >
            New sale
          </Link>
        </div>
      </section>

      {/* Recent harvests & sales */}
      <section>
        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Recent activity ({year})</h2>
        <div className="space-y-4">
          {harvests.length > 0 && (
            <div>
              <p className="text-xs font-medium text-gray-500 uppercase mb-2">Harvests</p>
              <ul className="rounded-lg border border-gray-200 divide-y divide-gray-200 bg-white overflow-x-auto">
                {harvests.slice(0, 10).map((h: Harvest) => (
                  <li key={h.id} className="px-4 py-2 flex justify-between items-center">
                    <Link to={`/app/harvests/${h.id}`} className="text-[#1F6F5C] font-medium hover:underline">
                      {h.harvest_no || h.id.slice(0, 8)}
                    </Link>
                    <span className="text-sm text-gray-500">{h.harvest_date}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {sales.length > 0 && (
            <div>
              <p className="text-xs font-medium text-gray-500 uppercase mb-2">Sales</p>
              <ul className="rounded-lg border border-gray-200 divide-y divide-gray-200 bg-white overflow-x-auto">
                {sales.slice(0, 10).map((s: Sale) => (
                  <li key={s.id} className="px-4 py-2 flex justify-between items-center">
                    <Link to={`/app/sales/${s.id}`} className="text-[#1F6F5C] font-medium hover:underline">
                      {s.sale_no || s.id.slice(0, 8)}
                    </Link>
                    <span className="text-sm text-gray-500 tabular-nums">{formatMoney(parseFloat(s.amount))}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {harvests.length === 0 && sales.length === 0 && (
            <p className="text-sm text-gray-500">No harvests or sales for this year.</p>
          )}
        </div>
      </section>
    </div>
  );
}

import { Link, Navigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useRole } from '../../hooks';
import { term } from '../../config/terminology';

function IntegrityCard({
  title,
  count,
  link,
  linkLabel,
}: {
  title: string;
  count: number;
  link: string;
  linkLabel: string;
}) {
  return (
    <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
      <p className="text-sm font-medium text-gray-500">{title}</p>
      <p className="mt-1 text-xl font-semibold text-gray-900 tabular-nums">{count}</p>
      <Link
        to={link}
        className="mt-2 inline-block text-sm font-medium text-[#1F6F5C] hover:underline"
      >
        {linkLabel}
      </Link>
    </div>
  );
}

export default function FarmIntegrityPage() {
  const { hasRole } = useRole();

  if (!hasRole('tenant_admin')) {
    return <Navigate to="/app/dashboard" replace />;
  }

  const { data, isLoading, error } = useQuery({
    queryKey: ['internal', 'farm-integrity'],
    queryFn: () => apiClient.getFarmIntegrity(),
    staleTime: 60 * 1000,
  });

  return (
    <div className="max-w-2xl mx-auto pb-24 sm:pb-6">
      <PageHeader
        title="Farm Integrity"
        backTo="/app/dashboard"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Farm Integrity' },
        ]}
      />

      <p className="mt-2 text-sm text-gray-500">
        Read-only validation signals. Use these to spot data gaps or follow-up items.
      </p>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner />
        </div>
      ) : error ? (
        <div className="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          {(error as Error).message}
        </div>
      ) : data ? (
        <div className="mt-6 grid gap-4 sm:grid-cols-2">
          <IntegrityCard
            title={`${term('activities')} missing production unit`}
            count={data.activities_missing_production_unit}
            link="/app/crop-ops/activities"
            linkLabel={`View ${term('activities').toLowerCase()}`}
          />
          <IntegrityCard
            title="Harvest without sale"
            count={data.harvest_without_sale}
            link="/app/harvests"
            linkLabel="View harvests"
          />
          <IntegrityCard
            title="Sales overdue, no payment (&gt;30 days)"
            count={data.sales_overdue_no_payment}
            link="/app/reports/ar-ageing"
            linkLabel="View AR ageing"
          />
          <IntegrityCard
            title="Negative inventory items"
            count={data.negative_inventory_items}
            link="/app/inventory/stock-on-hand"
            linkLabel="View stock on hand"
          />
          <IntegrityCard
            title="Production units, no activity (last 30 days)"
            count={data.production_units_no_activity_last_30_days}
            link="/app/production-units"
            linkLabel="View production units"
          />
          <IntegrityCard
            title="Livestock units, negative headcount"
            count={data.livestock_units_negative_headcount}
            link="/app/livestock"
            linkLabel="View livestock"
          />
        </div>
      ) : null}
    </div>
  );
}

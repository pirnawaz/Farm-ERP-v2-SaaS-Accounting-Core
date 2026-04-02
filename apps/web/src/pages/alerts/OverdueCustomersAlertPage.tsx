import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageContainer } from '../../components/PageContainer';
import { useFormatting } from '../../hooks/useFormatting';
import { useAlertPreferences } from '../../hooks/useAlertPreferences';
import { isOverdueInBucketOrWorse } from '../../utils/alertOverdueBucket';
import type { ARAgeingReport } from '../../types';

const BUCKET_LABELS: Record<string, string> = {
  '31_60': '31–60 days',
  '61_90': '61–90 days',
  '90_plus': '90+ days',
};

export default function OverdueCustomersAlertPage() {
  const asOf = useMemo(() => new Date().toISOString().split('T')[0], []);
  const { formatMoney, formatDate } = useFormatting();
  const { preferences } = useAlertPreferences();
  const bucket = preferences.overdueBucket;

  const { data: report, isLoading } = useQuery<ARAgeingReport>({
    queryKey: ['ar-ageing', asOf],
    queryFn: () => {
      const params = new URLSearchParams();
      params.append('as_of', asOf);
      return apiClient.get<ARAgeingReport>(`/api/reports/ar-ageing?${params.toString()}`);
    },
    staleTime: 2 * 60 * 1000,
  });

  const rows = useMemo(() => {
    if (!report?.rows?.length) return [];
    return report.rows.filter((row) => isOverdueInBucketOrWorse(row, bucket));
  }, [report, bucket]);

  if (isLoading) {
    return (
      <PageContainer className="pb-24 sm:pb-6">
        <PageHeader
          title="Overdue customers"
          backTo="/app/alerts"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Alerts', to: '/app/alerts' },
            { label: 'Overdue customers' },
          ]}
        />
        <div className="flex justify-center py-12">
          <LoadingSpinner />
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer className="pb-24 sm:pb-6">
      <PageHeader
        title="Overdue customers"
        backTo="/app/alerts"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Alerts', to: '/app/alerts' },
          { label: 'Overdue customers' },
        ]}
      />

      <p className="text-sm text-gray-500 mb-4">
        Customers with receivables in {BUCKET_LABELS[bucket]} or worse (as of {formatDate(asOf)}).
      </p>

      {report?.totals && (
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
          <div>
            <span className="text-gray-500">31–60</span>
            <p className="font-semibold tabular-nums">{formatMoney(report.totals.bucket_31_60)}</p>
          </div>
          <div>
            <span className="text-gray-500">61–90</span>
            <p className="font-semibold tabular-nums">{formatMoney(report.totals.bucket_61_90)}</p>
          </div>
          <div>
            <span className="text-gray-500">90+</span>
            <p className="font-semibold tabular-nums">{formatMoney(report.totals.bucket_90_plus)}</p>
          </div>
          <div>
            <span className="text-gray-500">Total outstanding</span>
            <p className="font-semibold tabular-nums">{formatMoney(report.totals.total_outstanding)}</p>
          </div>
        </div>
      )}

      {rows.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-500">
          No customers in the selected overdue bucket. Adjust alert settings or view the full report.
        </div>
      ) : (
        <ul className="space-y-2 mb-6">
          {rows.map((row) => (
            <li key={row.buyer_party_id}>
              <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="font-medium text-gray-900">{row.buyer_name}</p>
                  <p className="text-sm tabular-nums text-gray-600">
                    Total outstanding: {formatMoney(row.total_outstanding)}
                  </p>
                  <div className="flex flex-wrap gap-3 mt-1 text-xs text-gray-500">
                    <span>31–60: {formatMoney(row.bucket_31_60)}</span>
                    <span>61–90: {formatMoney(row.bucket_61_90)}</span>
                    <span>90+: {formatMoney(row.bucket_90_plus)}</span>
                  </div>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      <Link
        to="/app/reports/ar-ageing"
        className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
      >
        Full AR Ageing report
      </Link>
    </PageContainer>
  );
}

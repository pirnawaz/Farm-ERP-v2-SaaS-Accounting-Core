import { Link } from 'react-router-dom';
import { PageHeader } from '../components/PageHeader';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { PageContainer } from '../components/PageContainer';
import { useAlerts } from '../hooks/useAlerts';
import type { Alert } from '../types/alerts';

function severityStyles(severity: Alert['severity']) {
  switch (severity) {
    case 'critical':
      return 'border-red-200 bg-red-50 text-red-900';
    case 'warning':
      return 'border-amber-200 bg-amber-50 text-amber-900';
    case 'info':
    default:
      return 'border-blue-200 bg-blue-50 text-blue-900';
  }
}

function AlertCard({ alert }: { alert: Alert }) {
  const style = severityStyles(alert.severity);
  return (
    <div className={`rounded-xl border p-4 ${style}`}>
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div className="min-w-0">
          <h3 className="font-semibold">{alert.title}</h3>
          <p className="text-sm mt-0.5 opacity-90">{alert.description}</p>
          {alert.count != null && (
            <p className="text-sm font-medium mt-1 tabular-nums">
              {alert.count} {alert.count === 1 ? 'item' : 'items'}
            </p>
          )}
        </div>
        <Link
          to={alert.ctaHref}
          className="flex-shrink-0 inline-flex items-center justify-center px-4 py-2 rounded-lg font-medium bg-white/80 hover:bg-white border border-current/30 transition"
        >
          {alert.ctaLabel}
        </Link>
      </div>
    </div>
  );
}

export default function AlertsPage() {
  const { alerts, isLoading } = useAlerts();

  return (
    <PageContainer className="pb-24 sm:pb-6">
      <PageHeader
        title="Alert Center"
        backTo="/app/dashboard"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Alerts' },
        ]}
      />

      <div className="mt-4 space-y-6">
        {isLoading ? (
          <div className="flex justify-center py-12">
            <LoadingSpinner />
          </div>
        ) : alerts.length === 0 ? (
          <div className="rounded-xl border border-gray-200 bg-white p-6 text-center text-gray-500">
            <p className="font-medium">No alerts right now</p>
            <p className="text-sm mt-1">Check back later or after posting new transactions.</p>
          </div>
        ) : (
          alerts.map((alert) => <AlertCard key={alert.id} alert={alert} />)
        )}
      </div>

      <div className="mt-6 pt-4 border-t border-gray-200">
        <Link
          to="/app/alerts/settings"
          className="text-sm font-medium text-[#1F6F5C] hover:underline"
        >
          Alert settings
        </Link>
      </div>
    </PageContainer>
  );
}

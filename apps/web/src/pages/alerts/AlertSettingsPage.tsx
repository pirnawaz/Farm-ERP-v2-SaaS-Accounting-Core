import { PageHeader } from '../../components/PageHeader';
import { PageContainer } from '../../components/PageContainer';
import { useAlertPreferences } from '../../hooks/useAlertPreferences';
import type { AlertType } from '../../types/alerts';
import type { OverdueBucket } from '../../types/alertPreferences';

const ALERT_LABELS: Record<AlertType, string> = {
  PENDING_REVIEW: 'Pending review (draft transactions)',
  OVERDUE_CUSTOMERS: 'Overdue customers',
  UNPAID_LABOUR: 'Unpaid labour',
  LOW_STOCK: 'Low stock (placeholder)',
  NEGATIVE_MARGIN_FIELDS: 'Negative margin fields',
};

const BUCKET_OPTIONS: { value: OverdueBucket; label: string }[] = [
  { value: '31_60', label: '31–60 days or worse' },
  { value: '61_90', label: '61–90 days or worse' },
  { value: '90_plus', label: '90+ days only' },
];

export default function AlertSettingsPage() {
  const {
    preferences,
    setEnabled,
    setOverdueBucket,
    setNegativeMarginThreshold,
    setShowComingSoon,
    resetToDefaults,
  } = useAlertPreferences();

  return (
    <PageContainer className="pb-24 sm:pb-6">
      <PageHeader
        title="Alert settings"
        backTo="/app/alerts"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Alerts', to: '/app/alerts' },
          { label: 'Settings' },
        ]}
      />

      <p className="text-sm text-gray-500 mb-6">
        Control which alerts appear and how they are calculated. Stored on this device only.
      </p>

      <div className="space-y-6">
        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            Alert types
          </h2>
          <div className="rounded-xl border border-gray-200 bg-white divide-y divide-gray-100">
            {(Object.keys(ALERT_LABELS) as AlertType[]).map((type) => (
              <label
                key={type}
                className="flex items-center justify-between px-4 py-3 gap-3 cursor-pointer hover:bg-gray-50"
              >
                <span className="text-sm font-medium text-gray-900">{ALERT_LABELS[type]}</span>
                <input
                  type="checkbox"
                  checked={preferences.enabled[type]}
                  onChange={(e) => setEnabled(type, e.target.checked)}
                  className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C]"
                />
              </label>
            ))}
          </div>
        </section>

        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            Overdue customers
          </h2>
          <p className="text-sm text-gray-500 mb-2">
            Count customers whose overdue amount is in this bucket or worse.
          </p>
          <select
            value={preferences.overdueBucket}
            onChange={(e) => setOverdueBucket(e.target.value as OverdueBucket)}
            className="w-full sm:max-w-xs px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
          >
            {BUCKET_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
        </section>

        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            Negative margin threshold
          </h2>
          <p className="text-sm text-gray-500 mb-2">
            Count projects with net profit below this value (e.g. 0 for negative only).
          </p>
          <input
            type="number"
            value={preferences.negativeMarginThreshold}
            onChange={(e) => setNegativeMarginThreshold(Number(e.target.value) || 0)}
            step="any"
            className="w-full sm:max-w-xs px-3 py-2 border border-gray-300 rounded-lg text-sm tabular-nums focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
          />
        </section>

        <section>
          <label className="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3 cursor-pointer hover:bg-gray-50">
            <span className="text-sm font-medium text-gray-900">
              Show &quot;Low stock – coming soon&quot; info alert
            </span>
            <input
              type="checkbox"
              checked={preferences.showComingSoon}
              onChange={(e) => setShowComingSoon(e.target.checked)}
              className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C]"
            />
          </label>
        </section>

        <section>
          <button
            type="button"
            onClick={resetToDefaults}
            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            Reset to defaults
          </button>
        </section>
      </div>
    </PageContainer>
  );
}

import { useState, useEffect } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { settingsApi, type UpdateTenantSettingsPayload } from '../api/settings';
import { useTenantSettings } from '../hooks/useTenantSettings';
import { useRole } from '../hooks/useRole';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { ReopenOnboardingButton } from '../components/OnboardingChecklist';
import toast from 'react-hot-toast';

const CURRENCIES = [
  { code: 'PKR', name: 'Pakistani Rupee (PKR)' },
  { code: 'GBP', name: 'British Pound (GBP)' },
  { code: 'USD', name: 'US Dollar (USD)' },
  { code: 'EUR', name: 'Euro (EUR)' },
];

const LOCALES = [
  { code: 'en-PK', name: 'English (Pakistan)' },
  { code: 'ur-PK', name: 'Urdu (Pakistan)' },
  { code: 'en-GB', name: 'English (United Kingdom)' },
  { code: 'en-US', name: 'English (United States)' },
];

const TIMEZONES = [
  { code: 'Asia/Karachi', name: 'Asia/Karachi (Pakistan)' },
  { code: 'Europe/London', name: 'Europe/London (UK)' },
  { code: 'America/New_York', name: 'America/New_York (US Eastern)' },
  { code: 'America/Los_Angeles', name: 'America/Los_Angeles (US Pacific)' },
  { code: 'Europe/Paris', name: 'Europe/Paris (Central Europe)' },
  { code: 'Asia/Dubai', name: 'Asia/Dubai (UAE)' },
];

export default function LocalisationSettingsPage() {
  const { settings, loading, error, refresh } = useTenantSettings();
  const { hasRole } = useRole();
  const queryClient = useQueryClient();
  const [formData, setFormData] = useState<UpdateTenantSettingsPayload>({
    currency_code: 'GBP',
    locale: 'en-GB',
    timezone: 'Europe/London',
  });
  const [saving, setSaving] = useState(false);

  const canEdit = hasRole(['tenant_admin']);

  useEffect(() => {
    if (settings) {
      setFormData({
        currency_code: settings.currency_code,
        locale: settings.locale,
        timezone: settings.timezone,
      });
    }
  }, [settings]);

  const updateMutation = useMutation({
    mutationFn: (payload: UpdateTenantSettingsPayload) => settingsApi.updateTenantSettings(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tenantSettings'] });
      refresh();
      toast.success('Localisation settings updated successfully');
      setSaving(false);
    },
    onError: (error: any) => {
      toast.error(error.message || 'Failed to update settings');
      setSaving(false);
    },
  });

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!canEdit) {
      toast.error('You do not have permission to update settings');
      return;
    }

    setSaving(true);
    try {
      await updateMutation.mutateAsync(formData);
    } catch (error) {
      // Error handled in mutation
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">Error loading settings: {error.message}</p>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Localisation Settings</h1>
        <p className="text-sm text-gray-500 mt-1">
          Configure currency, locale, and timezone for your tenant. These settings affect how money and dates are displayed throughout the application.
        </p>
      </div>

      {!canEdit && (
        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
          <p className="text-yellow-800">
            <strong>Note:</strong> Only tenant administrators can update these settings.
          </p>
        </div>
      )}

      <div className="bg-white rounded-lg shadow p-6">
        <form onSubmit={handleSubmit}>
          <div className="space-y-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Currency
              </label>
              <select
                value={formData.currency_code}
                onChange={(e) => setFormData({ ...formData, currency_code: e.target.value })}
                disabled={!canEdit || saving}
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100 disabled:cursor-not-allowed"
              >
                {CURRENCIES.map((currency) => (
                  <option key={currency.code} value={currency.code}>
                    {currency.name}
                  </option>
                ))}
              </select>
              <p className="text-xs text-gray-500 mt-1">
                This currency will be used for displaying all monetary amounts in the UI.
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Locale
              </label>
              <select
                value={formData.locale}
                onChange={(e) => setFormData({ ...formData, locale: e.target.value })}
                disabled={!canEdit || saving}
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100 disabled:cursor-not-allowed"
              >
                {LOCALES.map((locale) => (
                  <option key={locale.code} value={locale.code}>
                    {locale.name}
                  </option>
                ))}
              </select>
              <p className="text-xs text-gray-500 mt-1">
                Locale affects number and date formatting (e.g., decimal separators, date order).
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Timezone
              </label>
              <select
                value={formData.timezone}
                onChange={(e) => setFormData({ ...formData, timezone: e.target.value })}
                disabled={!canEdit || saving}
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] disabled:bg-gray-100 disabled:cursor-not-allowed"
              >
                {TIMEZONES.map((tz) => (
                  <option key={tz.code} value={tz.code}>
                    {tz.name}
                  </option>
                ))}
              </select>
              <p className="text-xs text-gray-500 mt-1">
                All dates and times will be displayed in this timezone.
              </p>
            </div>
          </div>

          {canEdit && (
            <div className="mt-6 flex justify-end">
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] disabled:bg-gray-400 disabled:cursor-not-allowed"
              >
                {saving ? 'Saving...' : 'Save Settings'}
              </button>
            </div>
          )}
        </form>
      </div>

      <div className="mt-6 bg-[#E6ECEA] border border-[#1F6F5C]/20 rounded-lg p-4">
        <p className="text-sm text-[#2D3A3A]">
          <strong>Note:</strong> Changes to these settings will immediately affect how money and dates are displayed throughout the application. No page reload is required.
        </p>
      </div>

      {canEdit && (
        <div className="mt-6 flex items-center gap-2">
          <span className="text-sm text-gray-600">Onboarding:</span>
          <ReopenOnboardingButton />
        </div>
      )}
    </div>
  );
}

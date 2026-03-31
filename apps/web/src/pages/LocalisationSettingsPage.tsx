import { useState, useEffect, useMemo } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { settingsApi, type UpdateTenantSettingsPayload } from '../api/settings';
import { useTenantSettings } from '../hooks/useTenantSettings';
import { useRole } from '../hooks/useRole';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { ReopenOnboardingButton } from '../components/OnboardingChecklist';
import { SearchableSelect } from '../components/SearchableSelect';
import {
  CURRENCY_OPTIONS,
  LOCALE_OPTIONS,
  TIMEZONE_OPTIONS,
  TENANT_LOCALISATION_DEFAULTS,
  mergeSelectedOption,
} from '../config/localisationOptions';
import toast from 'react-hot-toast';

export default function LocalisationSettingsPage() {
  const { settings, loading, error, refresh } = useTenantSettings();
  const { hasRole } = useRole();
  const queryClient = useQueryClient();
  const [formData, setFormData] = useState<UpdateTenantSettingsPayload>({
    currency_code: TENANT_LOCALISATION_DEFAULTS.currency_code,
    locale: TENANT_LOCALISATION_DEFAULTS.locale,
    timezone: TENANT_LOCALISATION_DEFAULTS.timezone,
  });
  const [saving, setSaving] = useState(false);

  const currencyOptions = useMemo(
    () => mergeSelectedOption(CURRENCY_OPTIONS, formData.currency_code),
    [formData.currency_code],
  );
  const localeOptions = useMemo(
    () => mergeSelectedOption(LOCALE_OPTIONS, formData.locale),
    [formData.locale],
  );
  const timezoneOptions = useMemo(
    () => mergeSelectedOption(TIMEZONE_OPTIONS, formData.timezone),
    [formData.timezone],
  );

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
    onError: (err: unknown) => {
      const message = err instanceof Error ? err.message : 'Failed to update settings';
      toast.error(message);
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
    } catch {
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
          Configure currency, locale, and timezone for your tenant. These settings control how monetary amounts,
          numbers, dates, and times are displayed. They do not change accounting facts or stored ledger data.
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
              <label htmlFor="localisation-currency" className="block text-sm font-medium text-gray-700 mb-2">
                Currency
              </label>
              <SearchableSelect
                id="localisation-currency"
                value={formData.currency_code}
                onChange={(currency_code) => setFormData({ ...formData, currency_code })}
                options={currencyOptions}
                disabled={!canEdit || saving}
              />
              <p className="text-xs text-gray-500 mt-1">
                Used for displaying monetary amounts in the UI and generated documents.
              </p>
            </div>

            <div>
              <label htmlFor="localisation-locale" className="block text-sm font-medium text-gray-700 mb-2">
                Locale
              </label>
              <SearchableSelect
                id="localisation-locale"
                value={formData.locale}
                onChange={(locale) => setFormData({ ...formData, locale })}
                options={localeOptions}
                disabled={!canEdit || saving}
              />
              <p className="text-xs text-gray-500 mt-1">
                Controls date, number, and decimal formatting. Full UI translation only applies when translation
                resources exist for the chosen locale; otherwise interface copy may remain in English while
                formatting still follows this locale.
              </p>
            </div>

            <div>
              <label htmlFor="localisation-timezone" className="block text-sm font-medium text-gray-700 mb-2">
                Timezone
              </label>
              <SearchableSelect
                id="localisation-timezone"
                value={formData.timezone}
                onChange={(timezone) => setFormData({ ...formData, timezone })}
                options={timezoneOptions}
                disabled={!canEdit || saving}
              />
              <p className="text-xs text-gray-500 mt-1">
                Controls how dates and times are displayed across the application.
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
          <strong>Note:</strong> Changes apply to how values are displayed (including money and timestamps). No page
          reload is required. Underlying accounting and posting data are not modified.
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

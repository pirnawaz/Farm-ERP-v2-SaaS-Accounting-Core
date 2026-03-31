import { useMemo } from 'react';
import { useTenantSettings } from './useTenantSettings';
import {
  DEFAULT_LOCALISATION,
  type LocalisationSettings,
  normalizeLocaleTag,
} from '../utils/formatting';

/**
 * Tenant display localisation (locale, timezone, currency) with sensible defaults.
 */
export function useLocalisation(): LocalisationSettings & {
  /** Normalised BCP 47 locale for Intl */
  localeTag: string;
} {
  const { settings } = useTenantSettings();

  return useMemo(() => {
    const locale = settings?.locale?.trim() || DEFAULT_LOCALISATION.locale;
    const timezone = settings?.timezone?.trim() || DEFAULT_LOCALISATION.timezone;
    const currency_code = settings?.currency_code?.trim() || DEFAULT_LOCALISATION.currency_code;
    return {
      locale,
      timezone,
      currency_code,
      localeTag: normalizeLocaleTag(locale),
    };
  }, [settings?.locale, settings?.timezone, settings?.currency_code]);
}

<?php

namespace App\Support;

/**
 * Canonical allow-lists for tenant localisation (currency, locale, timezone).
 * Keep in sync with apps/web/src/config/localisationOptions.ts
 */
final class TenantLocalisation
{
    /** @var list<string> */
    public const CURRENCY_CODES = [
        'PKR', 'USD', 'GBP', 'EUR', 'AED', 'SAR', 'INR', 'CNY', 'CAD', 'AUD', 'NZD',
        'JPY', 'CHF', 'SGD', 'ZAR', 'TRY',
        'BHD', 'QAR', 'KWD', 'OMR', 'BDT', 'LKR', 'NPR', 'MVR', 'MYR', 'THB',
        'IDR', 'PHP', 'VND', 'KRW', 'HKD', 'MXN', 'BRL', 'CLP', 'COP', 'ARS', 'PEN',
        'EGP', 'NGN', 'KES', 'TZS', 'UGX', 'MAD', 'DZD', 'ILS', 'SEK', 'NOK', 'DKK',
        'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'RUB', 'UAH', 'KZT', 'UZS', 'GEL', 'AMD',
        'AZN', 'BYN', 'MDL', 'ALL', 'TND', 'IQD', 'JOD', 'LBP', 'AFN', 'ETB', 'GHS',
        'ZMW', 'MZN', 'MWK', 'XOF', 'XAF', 'FJD', 'PGK', 'SCR', 'MUR', 'BWP', 'NAD',
        'XPF', 'WST', 'TOP', 'VUV',
    ];

    /** @var list<string> */
    public const LOCALE_CODES = [
        'en-PK', 'ur-PK', 'sd-PK', 'en-GB', 'en-US', 'en-AE', 'ar-AE', 'fr-FR', 'de-DE',
        'ar-SA', 'hi-IN', 'bn-BD', 'en-IN', 'si-LK', 'ne-NP', 'dv-MV', 'ms-MY', 'id-ID',
        'th-TH', 'vi-VN', 'ja-JP', 'ko-KR', 'zh-CN', 'zh-HK', 'zh-TW', 'es-ES', 'es-MX',
        'pt-BR', 'it-IT', 'nl-NL', 'sv-SE', 'da-DK', 'fi-FI', 'nb-NO', 'pl-PL', 'cs-CZ',
        'hu-HU', 'ro-RO', 'el-GR', 'he-IL', 'tr-TR', 'fa-IR', 'en-AU', 'en-NZ', 'en-CA',
        'fr-CA', 'sw-KE', 'am-ET', 'fr-SN', 'ar-EG', 'en-ZA', 'pt-PT',
    ];

    /** @var list<string> */
    public const TIMEZONE_IDS = [
        'UTC',
        'Asia/Karachi', 'Asia/Dubai', 'Asia/Riyadh', 'Asia/Kolkata', 'Asia/Dhaka',
        'Asia/Singapore', 'Asia/Kathmandu', 'Asia/Colombo', 'Asia/Kabul', 'Asia/Tashkent',
        'Asia/Almaty', 'Asia/Baku', 'Asia/Bangkok', 'Asia/Jakarta', 'Asia/Manila',
        'Asia/Kuala_Lumpur', 'Asia/Hong_Kong', 'Asia/Shanghai', 'Asia/Taipei', 'Asia/Tokyo',
        'Asia/Seoul', 'Asia/Ulaanbaatar', 'Asia/Yangon', 'Asia/Tehran', 'Asia/Baghdad',
        'Asia/Jerusalem', 'Asia/Amman', 'Asia/Beirut', 'Asia/Aden', 'Asia/Muscat',
        'Asia/Kuwait', 'Asia/Qatar', 'Asia/Bahrain',
        'Europe/London', 'Europe/Dublin', 'Europe/Paris', 'Europe/Berlin', 'Europe/Amsterdam',
        'Europe/Brussels', 'Europe/Madrid', 'Europe/Rome', 'Europe/Lisbon', 'Europe/Vienna',
        'Europe/Warsaw', 'Europe/Prague', 'Europe/Budapest', 'Europe/Athens', 'Europe/Istanbul',
        'Europe/Moscow', 'Europe/Kyiv',
        'Africa/Johannesburg', 'Africa/Cairo', 'Africa/Lagos', 'Africa/Nairobi', 'Africa/Casablanca',
        'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'America/Toronto', 'America/Vancouver', 'America/Mexico_City', 'America/Bogota',
        'America/Lima', 'America/Santiago', 'America/Sao_Paulo', 'America/Buenos_Aires',
        'America/Caracas',
        'Pacific/Auckland', 'Pacific/Honolulu', 'Pacific/Guam', 'Pacific/Port_Moresby',
        'Australia/Sydney',
        'Atlantic/Reykjavik',
    ];

    /** @return list<string> */
    public static function currencyCodes(): array
    {
        return self::CURRENCY_CODES;
    }

    /** @return list<string> */
    public static function localeCodes(): array
    {
        return self::LOCALE_CODES;
    }

    /** @return list<string> */
    public static function timezoneIds(): array
    {
        return self::TIMEZONE_IDS;
    }

    public static function isAllowedCurrency(string $code): bool
    {
        return in_array($code, self::CURRENCY_CODES, true);
    }

    public static function isAllowedLocale(string $locale): bool
    {
        return in_array($locale, self::LOCALE_CODES, true);
    }

    public static function isAllowedTimezone(string $timezoneId): bool
    {
        return in_array($timezoneId, self::TIMEZONE_IDS, true);
    }

    public static function normalizeLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return $locale;
        }

        $parts = explode('-', $locale);
        if ($parts === [] || $parts[0] === '') {
            return $locale;
        }

        $normalized = [strtolower($parts[0])];
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $segment = $parts[$i];
            if ($segment === '') {
                continue;
            }
            $normalized[] = ctype_alpha($segment) ? strtoupper($segment) : $segment;
        }

        return implode('-', $normalized);
    }
}

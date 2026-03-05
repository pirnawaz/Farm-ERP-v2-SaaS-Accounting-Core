<?php

namespace App\Helpers;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Build auth cookie (farm_erp_auth_token) with secure defaults from config.
 */
class AuthCookie
{
    public const NAME = 'farm_erp_auth_token';

    /** Cookie max-age in minutes (from config, default 7 days). */
    public static function getMinutes(): int
    {
        $hours = (int) config('auth.auth_token_ttl_hours', 168);
        return $hours * 60;
    }

    public static function make(string $token, bool $expire = false): Cookie
    {
        $config = config('auth.auth_cookie', []);
        $secure = $config['secure'] ?? (config('app.env') === 'production');
        $sameSite = $config['same_site'] ?? 'lax';
        $domain = $config['domain'] ?? null;

        $minutes = $expire ? -1 : self::getMinutes();
        $value = $expire ? '' : $token;

        return cookie(
            self::NAME,
            $value,
            $minutes,
            '/',
            $domain,
            $secure,
            true,
            false,
            $sameSite
        );
    }
}

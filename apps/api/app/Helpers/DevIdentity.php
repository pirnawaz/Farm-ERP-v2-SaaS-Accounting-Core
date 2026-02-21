<?php

namespace App\Helpers;

class DevIdentity
{
    /**
     * Whether header-based identity (X-User-Id, X-User-Role) is allowed.
     * Allowed when APP_ENV is local/testing, or when DEV_IDENTITY_ENABLED=true.
     * In production with DEV_IDENTITY_ENABLED=false, header-only requests must fail.
     */
    public static function isAllowed(): bool
    {
        if (app()->environment('local', 'testing')) {
            return true;
        }
        return config('auth.dev_identity_enabled', false);
    }
}

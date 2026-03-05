<?php

namespace App\Helpers;

class DevIdentity
{
    /**
     * Whether header-based identity (X-User-Id, X-User-Role) is allowed.
     * In production, always false (dev identity headers are never trusted).
     * In local/testing, always true. Otherwise follows DEV_IDENTITY_ENABLED.
     */
    public static function isAllowed(): bool
    {
        if (app()->environment('production')) {
            return false;
        }
        if (app()->environment('local', 'testing')) {
            return true;
        }
        return config('auth.dev_identity_enabled', false);
    }
}

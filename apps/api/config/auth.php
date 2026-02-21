<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dev identity (header-based auth) allowed
    |--------------------------------------------------------------------------
    | When false (default): X-User-Id and X-User-Role headers are ignored in
    | production-like environments; header-only requests fail with 401/403.
    | When true or when APP_ENV is local/testing: headers are trusted for
    | development and testing (e.g. PHPUnit, Playwright).
    */
    'dev_identity_enabled' => filter_var(env('DEV_IDENTITY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];

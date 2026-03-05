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

    /*
    |--------------------------------------------------------------------------
    | Auth cookie (farm_erp_auth_token) security
    |--------------------------------------------------------------------------
    | Used for tenant and platform login. Not Laravel session; custom token in cookie.
    | For subdomain deployment (e.g. .terrava.app), set SESSION_DOMAIN so the cookie
    | is sent to all subdomains. SANCTUM_STATEFUL_DOMAINS is unused (we use custom cookie).
    */
    'auth_cookie' => [
        'secure' => filter_var(env('SESSION_SECURE_COOKIE'), FILTER_VALIDATE_BOOLEAN) ?: (env('APP_ENV') === 'production'),
        'same_site' => env('SESSION_SAME_SITE', 'lax'), // lax | strict | none (use none only if cross-site subdomain requires it)
        'domain' => env('SESSION_DOMAIN') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth token TTL (hours)
    |--------------------------------------------------------------------------
    | Used for cookie max-age and token exp claim. Default 168 (7 days).
    */
    'auth_token_ttl_hours' => (int) env('AUTH_TOKEN_TTL_HOURS', 168),

    /*
    |--------------------------------------------------------------------------
    | Allow legacy unsigned tokens
    |--------------------------------------------------------------------------
    | When false (recommended): only signed tokens (payload.signature) are accepted.
    | When true: base64(json) tokens without signature are accepted for backward compatibility.
    */
    'allow_legacy_tokens' => filter_var(env('ALLOW_LEGACY_TOKENS', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Minimum password length
    |--------------------------------------------------------------------------
    | Enforced on login registration, invite accept, and change password.
    */
    'password_min_length' => (int) env('PASSWORD_MIN_LENGTH', 10),
];

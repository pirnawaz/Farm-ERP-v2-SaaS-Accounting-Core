<?php

namespace Tests\Traits;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Helper for feature tests that need cookie-based auth.
 * Captures the auth cookie from a login response and forwards it to subsequent requests
 * so browser behaviour is reproducible (the test client does not auto-forward Set-Cookie).
 */
trait MakesAuthenticatedRequests
{
    protected const AUTH_COOKIE_NAME = 'farm_erp_auth_token';

    /**
     * Extract the auth token value from a login response.
     * Prefer the JSON "token" field (same value the app sets in the cookie).
     */
    protected function extractAuthCookieValueFromResponse(TestResponse|Response $response): ?string
    {
        if ($response instanceof TestResponse) {
            $token = $response->json('token');
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        // Fallback: parse Set-Cookie header(s) – may be string or array when multiple cookies
        $header = method_exists($response, 'headers') ? $response->headers->get('Set-Cookie') : null;
        $headers = is_array($header) ? $header : ($header !== null ? [$header] : []);
        foreach ($headers as $h) {
            if (is_string($h) && preg_match('/' . preg_quote(self::AUTH_COOKIE_NAME, '/') . '=([^;]+)/', $h, $m)) {
                return urldecode(trim($m[1]));
            }
        }

        return null;
    }

    /**
     * Chain this before the next request to send the auth cookie from a login response.
     * Example:
     *   $login = $this->postJson('/api/platform/auth/login', [...]);
     *   $this->withAuthCookieFrom($login)->getJson('/api/platform/auth/me');
     */
    protected function withAuthCookieFrom(TestResponse|Response $response): static
    {
        $value = $this->extractAuthCookieValueFromResponse($response);
        if ($value === null) {
            return $this;
        }
        // Send cookie for browser-like simulation; in testing middleware also accepts Bearer token
        return $this->withCookies([self::AUTH_COOKIE_NAME => $value])
            ->withHeader('Authorization', 'Bearer ' . $value);
    }

    /**
     * Create a request with the given auth cookie value (e.g. from makeAuthCookie()).
     */
    protected function withAuthCookie(string $cookieValue): static
    {
        return $this->withCookies([self::AUTH_COOKIE_NAME => $cookieValue])
            ->withHeader('Authorization', 'Bearer ' . $cookieValue);
    }
}

<?php

namespace App\Helpers;

use App\Models\User;

/**
 * Create and parse signed auth tokens (payload includes v=token_version, iat, exp).
 * Used for farm_erp_auth_token cookie. Verification ensures signature, expiry, and
 * (when loading user) token_version and is_enabled.
 */
class AuthToken
{
    public static function create(User $user, ?string $tenantId, ?string $impersonatorUserId = null, ?int $ttlHours = null): string
    {
        $ttlHours = $ttlHours ?? (int) config('auth.auth_token_ttl_hours', 168);
        $now = now();
        $exp = $now->copy()->addHours($ttlHours)->timestamp;

        $payload = [
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role' => $user->role,
            'email' => $user->email,
            'v' => (int) $user->token_version,
            'iat' => $now->timestamp,
            'exp' => $exp,
        ];
        if ($impersonatorUserId !== null) {
            $payload['impersonator_user_id'] = $impersonatorUserId;
        }

        $payloadJson = json_encode($payload);
        $payloadB64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payloadJson));
        $sig = hash_hmac('sha256', $payloadB64, config('app.key'), true);
        $sigB64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($sig));

        return $payloadB64 . '.' . $sigB64;
    }

    /**
     * Parse and verify token. Returns payload array or null if invalid/expired.
     * Does not check user.token_version or user.is_enabled; caller must do that.
     */
    public static function parse(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            if (!config('auth.allow_legacy_tokens', false)) {
                return null;
            }
            return self::parseLegacy($token);
        }

        [$payloadB64, $sigB64] = $parts;
        $sig = base64_decode(str_replace(['-', '_'], ['+', '/'], $sigB64) . str_repeat('=', (4 - strlen($sigB64) % 4) % 4));
        if ($sig === false || strlen($sig) !== 32) {
            return null;
        }
        $expectedSig = hash_hmac('sha256', $payloadB64, config('app.key'), true);
        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }

        $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $payloadB64) . str_repeat('=', (4 - strlen($payloadB64) % 4) % 4));
        if ($payloadJson === false) {
            return null;
        }
        $data = json_decode($payloadJson, true);
        if (!is_array($data)) {
            return null;
        }
        $exp = $data['exp'] ?? null;
        if ($exp === null || !is_numeric($exp) || (int) $exp < time()) {
            return null;
        }
        return $data;
    }

    /**
     * Legacy token format: single base64(json) with expires_at (no signature).
     */
    private static function parseLegacy(string $token): ?array
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }
        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return null;
        }
        $expiresAt = $data['expires_at'] ?? null;
        if ($expiresAt === null || !is_numeric($expiresAt) || (int) $expiresAt < time()) {
            return null;
        }
        // Normalize to new shape for middleware
        $data['v'] = (int) ($data['v'] ?? $data['token_version'] ?? 1);
        $data['exp'] = (int) $expiresAt;
        $data['iat'] = $data['iat'] ?? $data['exp'] - 7 * 24 * 3600;
        return $data;
    }
}

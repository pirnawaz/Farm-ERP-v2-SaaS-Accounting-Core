<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserInvitation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'invited_by_user_id',
        'token_hash',
        'token_plain',
        'expires_at',
    ];

    protected $hidden = ['token_hash', 'token_plain'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Create a new invitation. Returns [plainToken, isNew].
     * If existing invite for same tenant+email and not expired, returns [existing token_plain, false].
     * If existing but expired, deletes it and creates new [plain, true].
     */
    public static function createOrReuseInvitation(string $tenantId, string $email, string $role, string $invitedByUserId, int $ttlHours = 168): array
    {
        $existing = self::where('tenant_id', $tenantId)->where('email', $email)->first();
        if ($existing) {
            if ($existing->expires_at->isFuture() && $existing->token_plain) {
                return [$existing->token_plain, false];
            }
            $existing->delete();
        }

        $plain = Str::random(48);
        $hash = hash('sha256', $plain);
        self::create([
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $role,
            'invited_by_user_id' => $invitedByUserId,
            'token_hash' => $hash,
            'token_plain' => $plain,
            'expires_at' => now()->addHours($ttlHours),
        ]);
        return [$plain, true];
    }

    /** @deprecated Use createOrReuseInvitation. */
    public static function createInvitation(string $tenantId, string $email, string $role, string $invitedByUserId, int $ttlHours = 168): string
    {
        [$plain] = self::createOrReuseInvitation($tenantId, $email, $role, $invitedByUserId, $ttlHours);
        return $plain;
    }

    public static function findExistingNotExpired(string $tenantId, string $email): ?self
    {
        return self::where('tenant_id', $tenantId)
            ->where('email', $email)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Consume a one-time invitation token. Must be called inside a DB transaction.
     * Uses SELECT ... FOR UPDATE to prevent two concurrent accept-invite requests from both succeeding.
     */
    public static function consumeToken(string $plainToken): ?self
    {
        $hash = hash('sha256', $plainToken);
        $record = self::where('token_hash', $hash)
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();
        if ($record) {
            $record->delete();
            return $record;
        }
        return null;
    }
}

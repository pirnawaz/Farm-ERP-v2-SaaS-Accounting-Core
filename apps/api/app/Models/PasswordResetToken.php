<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PasswordResetToken extends Model
{
    use HasUuids;

    protected $table = 'password_reset_tokens';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function createToken(string $userId, int $ttlMinutes = 60): string
    {
        $plain = Str::random(48);
        $hash = hash('sha256', $plain);
        self::create([
            'user_id' => $userId,
            'token_hash' => $hash,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);
        return $plain;
    }

    public static function consumeToken(string $plainToken): ?self
    {
        $hash = hash('sha256', $plainToken);
        $record = self::where('token_hash', $hash)
            ->where('expires_at', '>', now())
            ->first();
        if ($record) {
            $record->delete();
            return $record;
        }
        return null;
    }
}

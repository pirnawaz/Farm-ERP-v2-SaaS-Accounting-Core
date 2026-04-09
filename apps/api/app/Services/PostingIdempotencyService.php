<?php

namespace App\Services;

use App\Models\PostingGroup;
use Illuminate\Validation\ValidationException;

/**
 * Phase 1H.4 — Idempotency standardization for posting flows.
 *
 * Each post uses either a non-empty client idempotency key (unique per tenant) or a deterministic
 * `source_type:source_id` string. {@see findExistingPostingGroup()} matches by key first (with source check),
 * then by (tenant, source_type, source_id). New posting groups always set `idempotency_key` to the effective key.
 *
 * DB: unique (tenant_id, idempotency_key) and unique (tenant_id, source_type, source_id).
 */
final class PostingIdempotencyService
{
    /**
     * Effective key for a posting: trimmed client key if non-empty, else "source_type:source_id".
     */
    public function effectiveKey(?string $clientKey, string $sourceType, string $sourceId): string
    {
        $trimmed = $clientKey !== null ? trim($clientKey) : '';

        return $trimmed !== '' ? $trimmed : $sourceType . ':' . $sourceId;
    }

    /**
     * Find an existing posting group by idempotency key (with source validation) or by (tenant, source_type, source_id).
     *
     * @throws ValidationException When tenant+key exists but points at a different source.
     */
    public function findExistingPostingGroup(
        string $tenantId,
        string $effectiveKey,
        string $sourceType,
        string $sourceId
    ): ?PostingGroup {
        $byKey = PostingGroup::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $effectiveKey)
            ->first();

        if ($byKey !== null) {
            if ($byKey->source_type !== $sourceType || (string) $byKey->source_id !== (string) $sourceId) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['This idempotency key is already used for a different posting.'],
                ]);
            }

            return $byKey;
        }

        return PostingGroup::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * Resolves the effective idempotency key and returns an existing posting group when present.
     *
     * @return array{effective_key: string, posting_group: PostingGroup|null}
     */
    public function resolveOrCreate(
        string $tenantId,
        ?string $clientKey,
        string $sourceType,
        string $sourceId
    ): array {
        $effectiveKey = $this->effectiveKey($clientKey, $sourceType, $sourceId);

        return [
            'effective_key' => $effectiveKey,
            'posting_group' => $this->findExistingPostingGroup($tenantId, $effectiveKey, $sourceType, $sourceId),
        ];
    }
}

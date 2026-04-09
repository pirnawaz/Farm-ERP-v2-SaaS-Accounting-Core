<?php

namespace App\Domains\Governance\SettlementPack;

use App\Models\SettlementPack;
use App\Models\SettlementPackVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finalizes a settlement pack: DRAFT → FINALIZED. No ledger, posting groups, or project mutations.
 * Pack becomes read-only (status-driven); snapshot rows must not be edited after this.
 */
class FinalizeSettlementPackAction
{
    /**
     * @return SettlementPack Fresh model after update (or unchanged if already FINALIZED)
     *
     * @throws ValidationException
     */
    public function execute(SettlementPack $pack, ?string $finalizedByUserId): SettlementPack
    {
        if ($pack->status === SettlementPack::STATUS_FINALIZED) {
            return $pack->fresh();
        }

        if ($pack->status !== SettlementPack::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Settlement pack can only be finalized from DRAFT status.'],
            ]);
        }

        $hasVersion = SettlementPackVersion::query()
            ->where('settlement_pack_id', $pack->id)
            ->where('tenant_id', $pack->tenant_id)
            ->exists();

        if (! $hasVersion) {
            throw ValidationException::withMessages([
                'snapshot' => ['A snapshot version is required before finalization.'],
            ]);
        }

        return DB::transaction(function () use ($pack, $finalizedByUserId) {
            $pack->update([
                'status' => SettlementPack::STATUS_FINALIZED,
                'finalized_at' => now(),
                'finalized_by_user_id' => $finalizedByUserId,
            ]);

            return $pack->fresh();
        });
    }
}

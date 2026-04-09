<?php

namespace App\Domains\Governance\SettlementPack;

use App\Models\SettlementPack;
use App\Models\SettlementPackDocument;
use App\Services\Document\SettlementPackPdfRenderer;
use App\Support\TenantScoped;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Versioned PDF bundle export for Settlement Packs.
 * Builds PDF strictly from the pack snapshot (latest settlement_pack_versions.snapshot_json). FINALIZED packs only.
 */
class SettlementPackExportService
{
    public function __construct(
        private SettlementPackPdfRenderer $pdfRenderer
    ) {}

    /**
     * Generate a new PDF bundle for the pack. Only FINAL packs are allowed.
     *
     * @throws ValidationException if pack is not FINALIZED
     */
    public function generatePdfBundle(string $tenantId, string $packId, ?string $userId): SettlementPackDocument
    {
        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)->findOrFail($packId);

        if ($pack->status !== SettlementPack::STATUS_FINALIZED) {
            throw ValidationException::withMessages([
                'status' => ['PDF export is only allowed for finalized settlement packs.'],
            ]);
        }

        $nextVersion = (int) TenantScoped::for(SettlementPackDocument::query(), $tenantId)
            ->where('settlement_pack_id', $packId)
            ->max('version') + 1;
        if ($nextVersion < 1) {
            $nextVersion = 1;
        }

        $pdfBytes = $this->pdfRenderer->render($pack, $nextVersion, '');
        $sha256Hex = strtolower(bin2hex(hash('sha256', $pdfBytes, true)));
        $snapshotSha256 = $this->snapshotHash($pack->snapshotJson());

        $storageKey = sprintf(
            'settlement_packs/%s/%s/v%d.pdf',
            $tenantId,
            $packId,
            $nextVersion
        );

        Storage::disk('local')->put($storageKey, $pdfBytes);

        $doc = SettlementPackDocument::create([
            'tenant_id' => $tenantId,
            'settlement_pack_id' => $packId,
            'version' => $nextVersion,
            'status' => SettlementPackDocument::STATUS_GENERATED,
            'storage_key' => $storageKey,
            'file_size_bytes' => strlen($pdfBytes),
            'sha256_hex' => $sha256Hex,
            'content_type' => 'application/pdf',
            'generated_at' => now(),
            'generated_by_user_id' => $userId,
            'meta_json' => [
                'snapshot_sha256' => $snapshotSha256,
            ],
        ]);

        return $doc;
    }

    /**
     * @return array<int, array{version: int, generated_at: string, sha256_hex: string, file_size_bytes: int|null}>
     */
    public function listDocuments(string $tenantId, string $packId): array
    {
        $docs = TenantScoped::for(SettlementPackDocument::query(), $tenantId)
            ->where('settlement_pack_id', $packId)
            ->orderBy('version', 'desc')
            ->get();

        return $docs->map(fn (SettlementPackDocument $d) => [
            'version' => $d->version,
            'generated_at' => $d->generated_at?->toIso8601String(),
            'sha256_hex' => $d->sha256_hex,
            'file_size_bytes' => $d->file_size_bytes,
        ])->values()->all();
    }

    public function getDocument(string $tenantId, string $packId, int $version): SettlementPackDocument
    {
        return TenantScoped::for(SettlementPackDocument::query(), $tenantId)
            ->where('settlement_pack_id', $packId)
            ->where('version', $version)
            ->firstOrFail();
    }

    private function snapshotHash(array $summaryJson): string
    {
        $canonical = json_encode($summaryJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return strtolower(bin2hex(hash('sha256', $canonical, true)));
    }
}

<?php

namespace App\Services;

use App\Models\Harvest;
use App\Models\HarvestShareLine;
use Illuminate\Validation\ValidationException;

/**
 * Draft-only preview of harvest share quantities and WIP-layer values (Phase 3A.2).
 *
 * Delegates bucket math to {@see HarvestShareBucketService} so preview and posting stay aligned.
 */
class HarvestSharePreviewService
{
    public function __construct(
        private HarvestShareBucketService $bucketService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Harvest $harvest, ?string $postingDate = null): array
    {
        if (! $harvest->isDraft()) {
            throw ValidationException::withMessages([
                'harvest' => ['Share preview is only available for DRAFT harvests.'],
            ]);
        }

        $result = $this->bucketService->compute($harvest, $postingDate);

        $buckets = $result['buckets'];
        $warnings = $result['warnings'];
        if ($harvest->shareLines->isEmpty()) {
            $warnings = array_merge($warnings, ['No share lines defined; assuming 100% owner retained per harvest line.']);
        }

        $ownerQty = 0.0;
        $ownerValue = 0.0;
        foreach ($buckets as $b) {
            if (($b['recipient_role'] ?? '') === HarvestShareLine::RECIPIENT_OWNER) {
                $ownerQty += (float) $b['computed_qty'];
                $ownerValue += (float) $b['provisional_value'];
            }
        }
        $hasImplicitOwner = $buckets->contains(fn ($b) => ! empty($b['implicit_owner']));

        $sumQty = $buckets->sum(fn ($b) => (float) $b['computed_qty']);
        $sumVal = $buckets->sum(fn ($b) => (float) $b['provisional_value']);
        $totalHarvestQty = (float) $result['lines']->sum(fn ($l) => (float) $l->quantity);
        $totalWipCost = $result['total_wip_cost'];

        return [
            'harvest_id' => $harvest->id,
            'posting_date_used' => $result['posting_date_used'],
            'total_wip_cost' => round($totalWipCost, HarvestShareBucketService::VALUE_DECIMALS),
            'warnings' => $warnings,
            'errors' => [],
            'harvest_lines' => $result['harvest_line_payload'],
            'share_buckets' => $buckets->values()->all(),
            'owner_retained' => [
                'quantity' => round($ownerQty, HarvestShareBucketService::QTY_DECIMALS),
                'provisional_value' => round($ownerValue, HarvestShareBucketService::VALUE_DECIMALS),
                'includes_implicit_owner' => $hasImplicitOwner,
            ],
            'totals' => [
                'harvest_quantity' => round($totalHarvestQty, HarvestShareBucketService::QTY_DECIMALS),
                'sum_bucket_quantity' => round($sumQty, HarvestShareBucketService::QTY_DECIMALS),
                'sum_bucket_value' => round($sumVal, HarvestShareBucketService::VALUE_DECIMALS),
                'allocated_wip' => round($totalWipCost, HarvestShareBucketService::VALUE_DECIMALS),
            ],
        ];
    }
}

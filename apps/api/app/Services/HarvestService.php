<?php

namespace App\Services;

use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\HarvestShareLine;
use App\Models\FieldJob;
use App\Models\LabWorkLog;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\PostingGroup;
use App\Services\LedgerWriteGuard;
use App\Services\OperationalPostingGuard;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\InvStockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class HarvestService
{
    public function __construct(
        private SystemAccountService $accountService,
        private InventoryStockService $stockService,
        private ReversalService $reversalService,
        private OperationalPostingGuard $guard,
        private PostingIdempotencyService $postingIdempotency,
        private HarvestShareBucketService $shareBucketService,
        private DuplicateWorkflowGuard $duplicateWorkflowGuard,
    ) {}

    /**
     * Create a DRAFT harvest.
     *
     * @throws \Exception
     */
    public function create(array $data): Harvest
    {
        return DB::transaction(function () use ($data) {
            $tenantId = $data['tenant_id'];
            $cropCycleId = $data['crop_cycle_id'];
            $projectId = $data['project_id'];

            $this->guard->ensureCropCycleOpen($cropCycleId, $tenantId);

            $cropCycle = CropCycle::where('id', $cropCycleId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            $project = Project::where('id', $projectId)
                ->where('tenant_id', $tenantId)
                ->where('crop_cycle_id', $cropCycleId)
                ->with('landAllocation')
                ->firstOrFail();

            $landParcelId = $project->landAllocation?->land_parcel_id ?? null;

            $harvest = Harvest::create([
                'tenant_id' => $tenantId,
                'harvest_no' => $data['harvest_no'] ?? null,
                'crop_cycle_id' => $cropCycleId,
                'project_id' => $projectId,
                'production_unit_id' => $data['production_unit_id'] ?? null,
                'land_parcel_id' => $landParcelId,
                'harvest_date' => $data['harvest_date'],
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? null,
            ]);

            return $harvest;
        });
    }

    /**
     * Update a DRAFT harvest.
     *
     * @throws \Exception
     */
    public function update(Harvest $harvest, array $data): Harvest
    {
        return DB::transaction(function () use ($harvest, $data) {
            if (!$harvest->isDraft()) {
                throw new \Exception('Only DRAFT harvests can be updated.');
            }

            $this->guard->ensureCropCycleOpenForProject($harvest->project_id, $harvest->tenant_id);

            $cropCycle = $harvest->cropCycle;
            $update = [
                'harvest_no' => $data['harvest_no'] ?? $harvest->harvest_no,
                'harvest_date' => $data['harvest_date'] ?? $harvest->harvest_date,
                'notes' => $data['notes'] ?? $harvest->notes,
            ];
            if (array_key_exists('production_unit_id', $data)) {
                $update['production_unit_id'] = $data['production_unit_id'] ?? null;
            }

            if (array_key_exists('project_id', $data)) {
                $project = Project::where('id', $data['project_id'])
                    ->where('tenant_id', $harvest->tenant_id)
                    ->where('crop_cycle_id', $harvest->crop_cycle_id)
                    ->with('landAllocation')
                    ->firstOrFail();
                $update['project_id'] = $project->id;
                $update['land_parcel_id'] = $project->landAllocation?->land_parcel_id ?? null;
            }

            $harvest->update($update);

            return $harvest->fresh();
        });
    }

    /**
     * Add a line to a DRAFT harvest.
     *
     * @throws \Exception
     */
    public function addLine(Harvest $harvest, array $lineData): HarvestLine
    {
        return DB::transaction(function () use ($harvest, $lineData) {
            if (!$harvest->isDraft()) {
                throw new \Exception('Lines can only be added to DRAFT harvests.');
            }

            $quantity = (float) ($lineData['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new \Exception('Quantity must be greater than zero.');
            }

            // Validate inventory item and store belong to tenant
            \App\Models\InvItem::where('id', $lineData['inventory_item_id'])
                ->where('tenant_id', $harvest->tenant_id)
                ->firstOrFail();

            \App\Models\InvStore::where('id', $lineData['store_id'])
                ->where('tenant_id', $harvest->tenant_id)
                ->firstOrFail();

            $line = HarvestLine::create([
                'tenant_id' => $harvest->tenant_id,
                'harvest_id' => $harvest->id,
                'inventory_item_id' => $lineData['inventory_item_id'],
                'store_id' => $lineData['store_id'],
                'quantity' => $quantity,
                'uom' => $lineData['uom'] ?? null,
                'notes' => $lineData['notes'] ?? null,
            ]);

            return $line;
        });
    }

    /**
     * Update a harvest line (only if harvest is DRAFT).
     *
     * @throws \Exception
     */
    public function updateLine(HarvestLine $line, array $lineData): HarvestLine
    {
        return DB::transaction(function () use ($line, $lineData) {
            if (!$line->harvest->isDraft()) {
                throw new \Exception('Lines can only be updated when harvest is DRAFT.');
            }

            if (isset($lineData['quantity'])) {
                $quantity = (float) $lineData['quantity'];
                if ($quantity <= 0) {
                    throw new \Exception('Quantity must be greater than zero.');
                }
                $lineData['quantity'] = $quantity;
            }

            if (isset($lineData['inventory_item_id'])) {
                \App\Models\InvItem::where('id', $lineData['inventory_item_id'])
                    ->where('tenant_id', $line->tenant_id)
                    ->firstOrFail();
            }

            if (isset($lineData['store_id'])) {
                \App\Models\InvStore::where('id', $lineData['store_id'])
                    ->where('tenant_id', $line->tenant_id)
                    ->firstOrFail();
            }

            $line->update($lineData);

            return $line->fresh();
        });
    }

    /**
     * Delete a harvest line (only if harvest is DRAFT).
     *
     * @throws \Exception
     */
    public function deleteLine(HarvestLine $line): void
    {
        if (!$line->harvest->isDraft()) {
            throw new \Exception('Lines can only be deleted when harvest is DRAFT.');
        }

        $line->delete();
    }

    /**
     * Add a share line to a DRAFT harvest (CRUD only; posting in Phase 3C).
     */
    public function addShareLine(Harvest $harvest, array $data): Harvest
    {
        return DB::transaction(function () use ($harvest, $data) {
            if (! $harvest->isDraft()) {
                throw ValidationException::withMessages([
                    'harvest' => ['Share lines can only be modified when the harvest is DRAFT.'],
                ]);
            }

            $this->assertShareLineForeignKeys($harvest, $data);
            $this->assertRemainderUniqueness($harvest, $data, null);

            $normalized = $this->normalizeShareLinePayload($data);
            $sortOrder = $normalized['sort_order'] ?? null;
            if ($sortOrder === null) {
                $max = HarvestShareLine::where('harvest_id', $harvest->id)->max('sort_order');
                $normalized['sort_order'] = (int) ($max ?? 0) + 1;
            }

            HarvestShareLine::create(array_merge($normalized, [
                'tenant_id' => $harvest->tenant_id,
                'harvest_id' => $harvest->id,
            ]));

            return $this->freshHarvestWithShareLines($harvest->id, $harvest->tenant_id);
        });
    }

    /**
     * Update a share line (DRAFT harvest only).
     */
    public function updateShareLine(HarvestShareLine $shareLine, array $data): Harvest
    {
        return DB::transaction(function () use ($shareLine, $data) {
            $harvest = $shareLine->harvest;
            if (! $harvest->isDraft()) {
                throw ValidationException::withMessages([
                    'harvest' => ['Share lines can only be modified when the harvest is DRAFT.'],
                ]);
            }

            $this->assertShareLineForeignKeys($harvest, $data);
            $this->assertRemainderUniqueness($harvest, $data, $shareLine->id);

            $normalized = $this->normalizeShareLinePayload($data);
            unset($normalized['tenant_id'], $normalized['harvest_id']);

            $shareLine->update($normalized);

            return $this->freshHarvestWithShareLines($harvest->id, $harvest->tenant_id);
        });
    }

    /**
     * Delete a share line (DRAFT harvest only).
     */
    public function deleteShareLine(HarvestShareLine $shareLine): void
    {
        if (! $shareLine->harvest->isDraft()) {
            throw ValidationException::withMessages([
                'harvest' => ['Share lines can only be modified when the harvest is DRAFT.'],
            ]);
        }

        $shareLine->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalizeShareLinePayload(array $data): array
    {
        $basis = $data['share_basis'];
        $remainder = (bool) ($data['remainder_bucket'] ?? false);

        $shareValue = $data['share_value'] ?? null;
        $ratioN = $data['ratio_numerator'] ?? null;
        $ratioD = $data['ratio_denominator'] ?? null;

        if ($basis === HarvestShareLine::BASIS_REMAINDER) {
            $shareValue = null;
            $ratioN = null;
            $ratioD = null;
        } elseif ($basis === HarvestShareLine::BASIS_RATIO) {
            $shareValue = null;
        } else {
            $ratioN = null;
            $ratioD = null;
        }

        if ($basis !== HarvestShareLine::BASIS_REMAINDER) {
            $remainder = false;
        }

        return [
            'harvest_line_id' => $data['harvest_line_id'] ?? null,
            'recipient_role' => $data['recipient_role'],
            'settlement_mode' => $data['settlement_mode'],
            'share_basis' => $basis,
            'share_value' => $shareValue,
            'ratio_numerator' => $ratioN,
            'ratio_denominator' => $ratioD,
            'remainder_bucket' => $remainder,
            'beneficiary_party_id' => $data['beneficiary_party_id'] ?? null,
            'machine_id' => $data['machine_id'] ?? null,
            'worker_id' => $data['worker_id'] ?? null,
            'source_field_job_id' => $data['source_field_job_id'] ?? null,
            'source_lab_work_log_id' => $data['source_lab_work_log_id'] ?? null,
            'source_machinery_charge_id' => $data['source_machinery_charge_id'] ?? null,
            'source_settlement_id' => $data['source_settlement_id'] ?? null,
            'inventory_item_id' => $data['inventory_item_id'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'sort_order' => array_key_exists('sort_order', $data) && $data['sort_order'] !== null
                ? (int) $data['sort_order']
                : null,
            'rule_snapshot' => $data['rule_snapshot'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * Tenant-scoped FK checks and harvest linkage (posting preview may add cross-document warnings).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertShareLineForeignKeys(Harvest $harvest, array $data): void
    {
        $tenantId = $harvest->tenant_id;

        if (! empty($data['harvest_line_id'])) {
            HarvestLine::where('id', $data['harvest_line_id'])
                ->where('harvest_id', $harvest->id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        if (! empty($data['beneficiary_party_id'])) {
            \App\Models\Party::where('id', $data['beneficiary_party_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        if (! empty($data['machine_id'])) {
            \App\Models\Machine::where('id', $data['machine_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        if (! empty($data['worker_id'])) {
            \App\Models\LabWorker::where('id', $data['worker_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        if (! empty($data['source_field_job_id'])) {
            $fj = FieldJob::where('id', $data['source_field_job_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
            // Posting preview: warn if field job project_id differs from harvest project (deferred).
            if ($harvest->project_id && $fj->project_id && $fj->project_id !== $harvest->project_id) {
                // Intentionally not enforced here — Phase 3C posting preview.
            }
        }

        if (! empty($data['source_lab_work_log_id'])) {
            LabWorkLog::where('id', $data['source_lab_work_log_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
            // Posting preview: optional cross-check vs harvest project / worker (deferred).
        }

        if (! empty($data['source_machinery_charge_id'])) {
            \App\Models\MachineryCharge::where('id', $data['source_machinery_charge_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        if (! empty($data['source_settlement_id'])) {
            \App\Models\Settlement::where('id', $data['source_settlement_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        if (! empty($data['inventory_item_id'])) {
            \App\Models\InvItem::where('id', $data['inventory_item_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        if (! empty($data['store_id'])) {
            \App\Models\InvStore::where('id', $data['store_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }
    }

    /**
     * At most one remainder per (harvest, harvest_line_id) scope; DB enforces when harvest_line_id is set.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertRemainderUniqueness(Harvest $harvest, array $data, ?string $excludeShareLineId): void
    {
        $remainder = (bool) ($data['remainder_bucket'] ?? false);
        if (! $remainder) {
            return;
        }

        $q = HarvestShareLine::query()
            ->where('harvest_id', $harvest->id)
            ->where('tenant_id', $harvest->tenant_id)
            ->where('remainder_bucket', true);

        if ($excludeShareLineId !== null) {
            $q->where('id', '!=', $excludeShareLineId);
        }

        $lineId = $data['harvest_line_id'] ?? null;
        if ($lineId) {
            $q->where('harvest_line_id', $lineId);
        } else {
            $q->whereNull('harvest_line_id');
        }

        if ($q->exists()) {
            throw ValidationException::withMessages([
                'remainder_bucket' => ['Only one remainder bucket is allowed for this harvest scope (per harvest line, or one harvest-level remainder when no line is set).'],
            ]);
        }
    }

    private function freshHarvestWithShareLines(string $harvestId, string $tenantId): Harvest
    {
        return Harvest::where('id', $harvestId)
            ->where('tenant_id', $tenantId)
            ->with(Harvest::detailWithRelations())
            ->firstOrFail();
    }

    /**
     * Post a harvest. Creates posting group, allocation rows, ledger entries, and inventory movements.
     *
     * @throws \Exception
     */
    public function post(Harvest $harvest, array $payload): Harvest
    {
        return LedgerWriteGuard::scoped(static::class, function () use ($harvest, $payload) {
            return DB::transaction(function () use ($harvest, $payload) {
            $clientKey = isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null;
            $resolved = $this->postingIdempotency->resolveOrCreate($harvest->tenant_id, $clientKey, 'HARVEST', $harvest->id);
            if ($resolved['posting_group'] !== null) {
                $existingPostingGroup = $resolved['posting_group'];
                $harvest->refresh();
                if ($harvest->posting_group_id === $existingPostingGroup->id) {
                    return $harvest->fresh(Harvest::detailWithRelations());
                }
                $harvest->update([
                    'status' => 'POSTED',
                    'posting_date' => $payload['posting_date'] ?? $harvest->posting_date,
                    'posted_at' => $harvest->posted_at ?? now(),
                    'posting_group_id' => $existingPostingGroup->id,
                ]);

                return $harvest->fresh(Harvest::detailWithRelations());
            }
            $idempotencyKey = $resolved['effective_key'];

            // Validate harvest status
            if (!$harvest->isDraft()) {
                throw new \Exception('Only DRAFT harvests can be posted.');
            }

            // Load relationships (share lines required for share-aware posting)
            $harvest->load(['lines.item', 'lines.store', 'cropCycle', 'shareLines']);

            if (!$harvest->project_id) {
                throw new \Exception('Cannot post harvest: project is required. Update the harvest with a project.');
            }

            // Validate has lines
            if ($harvest->lines->isEmpty()) {
                throw new \Exception('Harvest must have at least one line to post.');
            }

            // Validate all quantities > 0
            foreach ($harvest->lines as $line) {
                if ((float) $line->quantity <= 0) {
                    throw new \Exception("Harvest line quantity must be greater than zero.");
                }
            }

            // Validate crop cycle OPEN
            $this->guard->ensureCropCycleOpenForProject($harvest->project_id, $harvest->tenant_id);

            $cropCycle = $harvest->cropCycle;

            // Validate posting_date
            $postingDate = $payload['posting_date'] ?? null;
            if (!$postingDate) {
                throw new \Exception('posting_date is required.');
            }

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');

            // Validate posting_date within crop cycle range
            if ($cropCycle->start_date && $postingDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Posting date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $postingDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Posting date is after crop cycle end date.');
            }

            // Use harvest's project for allocation rows
            $project = Project::where('id', $harvest->project_id)
                ->where('tenant_id', $harvest->tenant_id)
                ->firstOrFail();

            $this->duplicateWorkflowGuard->assertHarvestPostAllowed($harvest);

            // Create posting group
            $postingGroup = PostingGroup::create([
                'tenant_id' => $harvest->tenant_id,
                'crop_cycle_id' => $harvest->crop_cycle_id,
                'source_type' => 'HARVEST',
                'source_id' => $harvest->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $idempotencyKey,
            ]);

            $bucketResult = $this->shareBucketService->compute($harvest, $postingDateObj);
            $totalWipCost = $bucketResult['total_wip_cost'];
            $buckets = $bucketResult['buckets'];
            $lines = $bucketResult['lines'];
            $shareLinesById = $harvest->shareLines->keyBy('id');

            $cropWipAccount = $this->accountService->getByCode($harvest->tenant_id, 'CROP_WIP');
            $inventoryAccount = $this->accountService->getByCode($harvest->tenant_id, 'INVENTORY_PRODUCE');

            $lineIndexById = [];
            foreach ($lines as $idx => $hl) {
                $lineIndexById[$hl->id] = $idx;
            }

            foreach ($buckets as $bucket) {
                $this->postHarvestShareBucket(
                    $harvest,
                    $postingGroup,
                    $project,
                    $postingDateObj,
                    $bucket,
                    $lines,
                    $shareLinesById,
                    $lineIndexById,
                    $totalWipCost,
                    $inventoryAccount
                );
            }

            if ($totalWipCost > 0.001) {
                LedgerEntry::create([
                    'tenant_id' => $harvest->tenant_id,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $cropWipAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => (string) round($totalWipCost, 2),
                    'currency_code' => 'GBP',
                ]);
            }

            foreach ($buckets as $bucket) {
                $this->postInKindSettlementIfApplicable(
                    $harvest,
                    $postingGroup,
                    $project,
                    $bucket,
                    $shareLinesById
                );
            }

            $this->persistPostedShareSnapshots($buckets, $shareLinesById, $postingGroup->id);

            $harvest->update([
                'status' => 'POSTED',
                'posting_date' => $postingDateObj,
                'posted_at' => now(),
                'posting_group_id' => $postingGroup->id,
            ]);

            return $harvest->fresh(Harvest::detailWithRelations());
            });
        });
    }

    /**
     * @param  Collection<string, HarvestShareLine>  $shareLinesById
     * @param  array<string, int>  $lineIndexById
     */
    private function postHarvestShareBucket(
        Harvest $harvest,
        PostingGroup $postingGroup,
        Project $project,
        string $postingDateObj,
        array $bucket,
        Collection $lines,
        Collection $shareLinesById,
        array $lineIndexById,
        float $totalWipCost,
        \App\Models\Account $inventoryAccount
    ): void {
        $shareLine = ! empty($bucket['share_line_id'])
            ? $shareLinesById->get($bucket['share_line_id'])
            : null;

        $ctx = $this->resolveMovementContext($bucket, $lines, $shareLine);
        /** @var HarvestLine $harvestLine */
        $harvestLine = $ctx['harvest_line'];
        $storeId = $ctx['store_id'];
        $itemId = $ctx['item_id'];

        $qty = (float) $bucket['computed_qty'];
        $allocatedCost = (float) $bucket['provisional_value'];
        $unitCost = $qty > 0 ? $allocatedCost / $qty : 0.0;

        $partyId = $shareLine?->beneficiary_party_id ?? $project->party_id;

        $lineIndex = isset($bucket['harvest_line_id'])
            ? ($lineIndexById[$bucket['harvest_line_id']] ?? 0)
            : 0;

        $ruleSnapshot = [
            'type' => 'HARVEST',
            'harvest_line_id' => $bucket['harvest_line_id'] ?? $harvestLine->id,
            'harvest_id' => $harvest->id,
            'total_wip_transferred' => $totalWipCost,
            'line_quantity' => $qty,
            'line_index' => $lineIndex,
            'allocation' => 'share_bucket',
            'recipient_role' => $bucket['recipient_role'],
            'share_line_id' => $bucket['share_line_id'],
            'implicit_owner' => (bool) ($bucket['implicit_owner'] ?? false),
            'valuation_basis' => 'WIP_LAYER',
        ];
        if ($shareLine?->worker_id) {
            $ruleSnapshot['worker_id'] = $shareLine->worker_id;
        }

        AllocationRow::create([
            'tenant_id' => $harvest->tenant_id,
            'posting_group_id' => $postingGroup->id,
            'project_id' => $project->id,
            'party_id' => $partyId,
            'allocation_type' => 'HARVEST_PRODUCTION',
            'allocation_scope' => 'SHARED',
            'amount' => (string) round($allocatedCost, 2),
            'quantity' => (string) round($qty, 3),
            'unit' => $harvestLine->uom,
            'machine_id' => $shareLine?->machine_id,
            'rule_snapshot' => $ruleSnapshot,
        ]);

        if ($allocatedCost > 0.001) {
            LedgerEntry::create([
                'tenant_id' => $harvest->tenant_id,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $inventoryAccount->id,
                'debit_amount' => (string) round($allocatedCost, 2),
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);
        }

        $this->stockService->applyMovement(
            $harvest->tenant_id,
            $postingGroup->id,
            $storeId,
            $itemId,
            'HARVEST',
            (string) $qty,
            (string) round($allocatedCost, 2),
            (string) round($unitCost, 6),
            $postingDateObj,
            'harvest',
            $harvest->id
        );
    }

    /**
     * @param  Collection<string, HarvestShareLine>  $shareLinesById
     */
    private function resolveMovementContext(array $bucket, Collection $lines, ?HarvestShareLine $shareLine): array
    {
        $firstLine = $lines->firstOrFail();
        $harvestLine = $firstLine;
        if (! empty($bucket['harvest_line_id'])) {
            $found = $lines->firstWhere('id', $bucket['harvest_line_id']);
            if ($found) {
                $harvestLine = $found;
            }
        }

        $itemId = $shareLine?->inventory_item_id ?? $harvestLine->inventory_item_id;
        $storeId = $shareLine?->store_id ?? $harvestLine->store_id;

        return [
            'harvest_line' => $harvestLine,
            'item_id' => $itemId,
            'store_id' => $storeId,
        ];
    }

    /**
     * @param  Collection<string, HarvestShareLine>  $shareLinesById
     */
    private function postInKindSettlementIfApplicable(
        Harvest $harvest,
        PostingGroup $postingGroup,
        Project $project,
        array $bucket,
        Collection $shareLinesById
    ): void {
        $role = $bucket['recipient_role'] ?? '';
        if ($role === HarvestShareLine::RECIPIENT_OWNER) {
            return;
        }
        if (($bucket['settlement_mode'] ?? null) !== HarvestShareLine::SETTLEMENT_IN_KIND) {
            return;
        }
        $v = (float) $bucket['provisional_value'];
        if ($v <= 0.001) {
            return;
        }

        $shareLine = ! empty($bucket['share_line_id'])
            ? $shareLinesById->get($bucket['share_line_id'])
            : null;

        $partyId = $shareLine?->beneficiary_party_id ?? $project->party_id;

        $allocationType = match ($role) {
            HarvestShareLine::RECIPIENT_MACHINE => 'HARVEST_IN_KIND_MACHINE',
            HarvestShareLine::RECIPIENT_LABOUR => 'HARVEST_IN_KIND_LABOUR',
            HarvestShareLine::RECIPIENT_LANDLORD => 'HARVEST_IN_KIND_LANDLORD',
            HarvestShareLine::RECIPIENT_CONTRACTOR => 'HARVEST_IN_KIND_CONTRACTOR',
            default => null,
        };
        if ($allocationType === null) {
            return;
        }

        $snap = [
            'harvest_id' => $harvest->id,
            'share_line_id' => $bucket['share_line_id'],
            'recipient_role' => $role,
            'source_field_job_id' => $shareLine?->source_field_job_id,
            'source_lab_work_log_id' => $shareLine?->source_lab_work_log_id,
            'worker_id' => $shareLine?->worker_id,
        ];

        AllocationRow::create([
            'tenant_id' => $harvest->tenant_id,
            'posting_group_id' => $postingGroup->id,
            'project_id' => $project->id,
            'party_id' => $partyId,
            'allocation_type' => $allocationType,
            'allocation_scope' => 'SHARED',
            'amount' => (string) round($v, 2),
            'machine_id' => $shareLine?->machine_id,
            'rule_snapshot' => $snap,
        ]);

        $amount = (string) round($v, 2);
        $tid = $harvest->tenant_id;
        $pgId = $postingGroup->id;

        match ($role) {
            HarvestShareLine::RECIPIENT_MACHINE => $this->ledgerDrIncomeCrExpense(
                $tid,
                $pgId,
                'MACHINERY_SERVICE_INCOME',
                'EXP_SHARED',
                $amount
            ),
            HarvestShareLine::RECIPIENT_LABOUR => $this->ledgerDrLiabilityCrExpense(
                $tid,
                $pgId,
                'WAGES_PAYABLE',
                'LABOUR_EXPENSE',
                $amount
            ),
            HarvestShareLine::RECIPIENT_LANDLORD => $this->ledgerDrLiabilityCrExpense(
                $tid,
                $pgId,
                'PAYABLE_LANDLORD',
                'EXP_LANDLORD_ONLY',
                $amount
            ),
            HarvestShareLine::RECIPIENT_CONTRACTOR => $this->ledgerDrLiabilityCrExpense(
                $tid,
                $pgId,
                'AP',
                'EXP_SHARED',
                $amount
            ),
            default => null,
        };
    }

    private function ledgerDrIncomeCrExpense(string $tenantId, string $postingGroupId, string $incomeCode, string $expenseCode, string $amount): void
    {
        $income = $this->accountService->getByCode($tenantId, $incomeCode);
        $exp = $this->accountService->getByCode($tenantId, $expenseCode);
        LedgerEntry::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $postingGroupId,
            'account_id' => $income->id,
            'debit_amount' => $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $postingGroupId,
            'account_id' => $exp->id,
            'debit_amount' => 0,
            'credit_amount' => $amount,
            'currency_code' => 'GBP',
        ]);
    }

    private function ledgerDrLiabilityCrExpense(string $tenantId, string $postingGroupId, string $liabilityCode, string $expenseCode, string $amount): void
    {
        $liab = $this->accountService->getByCode($tenantId, $liabilityCode);
        $exp = $this->accountService->getByCode($tenantId, $expenseCode);
        LedgerEntry::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $postingGroupId,
            'account_id' => $liab->id,
            'debit_amount' => $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $postingGroupId,
            'account_id' => $exp->id,
            'debit_amount' => 0,
            'credit_amount' => $amount,
            'currency_code' => 'GBP',
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $buckets
     * @param  Collection<string, HarvestShareLine>  $shareLinesById
     */
    private function persistPostedShareSnapshots(Collection $buckets, Collection $shareLinesById, string $postingGroupId): void
    {
        foreach ($buckets as $bucket) {
            if (empty($bucket['share_line_id'])) {
                continue;
            }
            $sl = $shareLinesById->get($bucket['share_line_id']);
            if (! $sl) {
                continue;
            }
            $prev = is_array($sl->rule_snapshot) ? $sl->rule_snapshot : [];
            $sl->update([
                'computed_qty' => $bucket['computed_qty'],
                'computed_unit_cost_snapshot' => $bucket['provisional_unit_cost'],
                'computed_value_snapshot' => $bucket['provisional_value'],
                'rule_snapshot' => array_merge($prev, [
                    'posted_snapshot_at' => now()->toIso8601String(),
                    'valuation_basis' => 'WIP_LAYER',
                    'harvest_line_id' => $bucket['harvest_line_id'],
                    'recipient_role' => $bucket['recipient_role'],
                    'harvest_posting_group_id' => $postingGroupId,
                ]),
            ]);
        }
    }

    /**
     * Reverse a posted harvest.
     *
     * @throws \Exception
     */
    public function reverse(Harvest $harvest, array $payload): Harvest
    {
        return LedgerWriteGuard::scoped(static::class, function () use ($harvest, $payload) {
            return DB::transaction(function () use ($harvest, $payload) {
            // Validate harvest status
            if (!$harvest->isPosted()) {
                throw new \Exception('Only POSTED harvests can be reversed.');
            }

            // Validate reversal_date
            $reversalDate = $payload['reversal_date'] ?? null;
            if (!$reversalDate) {
                throw new \Exception('reversal_date is required.');
            }

            $reversalDateObj = Carbon::parse($reversalDate)->format('Y-m-d');

            $this->guard->ensureCropCycleOpenForProject($harvest->project_id, $harvest->tenant_id);

            $cropCycle = $harvest->cropCycle;

            // Validate reversal_date within crop cycle range
            if ($cropCycle->start_date && $reversalDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Reversal date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $reversalDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Reversal date is after crop cycle end date.');
            }

            $reason = $payload['reason'] ?? '';

            // Create reversal posting group
            $reversalPostingGroup = $this->reversalService->reversePostingGroup(
                $harvest->posting_group_id,
                $harvest->tenant_id,
                $reversalDate,
                $reason
            );

            // Check if stock movements already reversed (idempotency)
            $existing = InvStockMovement::where('tenant_id', $harvest->tenant_id)
                ->where('posting_group_id', $reversalPostingGroup->id)
                ->exists();

            if ($existing) {
                $harvest->update([
                    'status' => 'REVERSED',
                    'reversed_at' => now(),
                    'reversal_posting_group_id' => $reversalPostingGroup->id,
                ]);
                return $harvest->fresh(Harvest::detailWithRelations());
            }

            // Find original stock movements and reverse them
            $originalMovements = InvStockMovement::where('tenant_id', $harvest->tenant_id)
                ->where('posting_group_id', $harvest->posting_group_id)
                ->get();

            foreach ($originalMovements as $original) {
                $this->stockService->applyMovement(
                    $harvest->tenant_id,
                    $reversalPostingGroup->id,
                    $original->store_id,
                    $original->item_id,
                    $original->movement_type,
                    (string) (-(float) $original->qty_delta),
                    (string) (-(float) $original->value_delta),
                    (string) $original->unit_cost_snapshot,
                    $reversalDateObj,
                    $original->source_type,
                    $original->source_id
                );
            }

            // Update harvest
            $harvest->update([
                'status' => 'REVERSED',
                'reversed_at' => now(),
                'reversal_posting_group_id' => $reversalPostingGroup->id,
            ]);

            return $harvest->fresh(Harvest::detailWithRelations());
            });
        });
    }
}

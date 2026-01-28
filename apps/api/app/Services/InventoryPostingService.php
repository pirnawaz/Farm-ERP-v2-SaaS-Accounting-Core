<?php

namespace App\Services;

use App\Models\InvAdjustment;
use App\Models\InvAdjustmentLine;
use App\Models\InvGrn;
use App\Models\InvIssue;
use App\Models\InvItem;
use App\Models\InvStockMovement;
use App\Models\InvStore;
use App\Models\InvTransfer;
use App\Models\InvTransferLine;
use App\Models\LedgerEntry;
use App\Models\AllocationRow;
use App\Models\PostingGroup;
use App\Models\CropCycle;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InventoryPostingService
{
    public function __construct(
        private SystemAccountService $accountService,
        private ReversalService $reversalService,
        private InventoryStockService $stockService
    ) {}

    /**
     * Post a GRN. Idempotent via idempotency_key.
     *
     * @throws \Exception
     */
    public function postGRN(string $grnId, string $tenantId, string $postingDate, string $idempotencyKey): PostingGroup
    {
        return DB::transaction(function () use ($grnId, $tenantId, $postingDate, $idempotencyKey) {
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['ledgerEntries.account']);
            }

            $grn = InvGrn::where('id', $grnId)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();
            $grn->load(['lines.item', 'store']);

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');

            InvStore::where('id', $grn->store_id)->where('tenant_id', $tenantId)->firstOrFail();
            foreach ($grn->lines as $line) {
                InvItem::where('id', $line->item_id)->where('tenant_id', $tenantId)->firstOrFail();
            }

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => null,
                'source_type' => 'INVENTORY_GRN',
                'source_id' => $grn->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $idempotencyKey,
            ]);

            $totalValue = '0';
            foreach ($grn->lines as $line) {
                $lineTotal = (string) ((float) $line->qty * (float) $line->unit_cost);
                $totalValue = (string) ((float) $totalValue + (float) $lineTotal);
                $this->stockService->applyMovement(
                    $tenantId,
                    $postingGroup->id,
                    $grn->store_id,
                    $line->item_id,
                    'GRN',
                    (string) $line->qty,
                    $lineTotal,
                    (string) $line->unit_cost,
                    $postingDateObj,
                    'inv_grn',
                    $grn->id
                );
            }

            $inventoryAccount = $this->accountService->getByCode($tenantId, 'INVENTORY_INPUTS');
            $creditAccount = $grn->supplier_party_id
                ? $this->accountService->getByCode($tenantId, 'AP')
                : $this->accountService->getByCode($tenantId, 'CASH');

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $inventoryAccount->id,
                'debit_amount' => $totalValue,
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $creditAccount->id,
                'debit_amount' => 0,
                'credit_amount' => $totalValue,
                'currency_code' => 'GBP',
            ]);

            $grn->update(['status' => 'POSTED', 'posting_group_id' => $postingGroup->id, 'posting_date' => $postingDateObj]);

            return $postingGroup->fresh(['ledgerEntries.account']);
        });
    }

    /**
     * Post an Issue. Idempotent via idempotency_key.
     *
     * @throws \Exception
     */
    public function postIssue(string $issueId, string $tenantId, string $postingDate, string $idempotencyKey): PostingGroup
    {
        return DB::transaction(function () use ($issueId, $tenantId, $postingDate, $idempotencyKey) {
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['ledgerEntries.account', 'allocationRows']);
            }

            $issue = InvIssue::where('id', $issueId)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();
            $issue->load(['lines.item', 'store', 'project', 'cropCycle']);

            if (!$issue->crop_cycle_id || !$issue->project_id) {
                throw new \Exception('Crop cycle and project are required for posting an issue.');
            }

            $cropCycle = CropCycle::where('id', $issue->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
            if ($cropCycle->status !== 'OPEN') {
                throw new \Exception('Cannot post issue: crop cycle is closed.');
            }

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
            if ($cropCycle->start_date && $postingDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Posting date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $postingDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Posting date is after crop cycle end date.');
            }

            $project = Project::where('id', $issue->project_id)->where('tenant_id', $tenantId)->firstOrFail();

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $issue->crop_cycle_id,
                'source_type' => 'INVENTORY_ISSUE',
                'source_id' => $issue->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $idempotencyKey,
            ]);

            $totalValue = 0.0;
            foreach ($issue->lines as $line) {
                $balance = $this->stockService->getOrCreateBalance($tenantId, $issue->store_id, $line->item_id);
                $qtyOnHand = (float) $balance->qty_on_hand;
                $qty = (float) $line->qty;
                if ($qtyOnHand < $qty) {
                    throw new \Exception("Insufficient stock for item {$line->item->name}: on hand {$qtyOnHand}, required {$qty}.");
                }
                $wac = (string) $balance->wac_cost;
                $lineTotal = (string) ($qty * (float) $wac);
                $totalValue += (float) $lineTotal;

                $this->stockService->applyMovement(
                    $tenantId,
                    $postingGroup->id,
                    $issue->store_id,
                    $line->item_id,
                    'ISSUE',
                    (string) (-$qty),
                    (string) (-(float) $lineTotal),
                    $wac,
                    $postingDateObj,
                    'inv_issue',
                    $issue->id
                );

                $line->update(['unit_cost_snapshot' => $wac, 'line_total' => $lineTotal]);
            }

            $inputsExpenseAccount = $this->accountService->getByCode($tenantId, 'INPUTS_EXPENSE');
            $inventoryAccount = $this->accountService->getByCode($tenantId, 'INVENTORY_INPUTS');

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $inputsExpenseAccount->id,
                'debit_amount' => (string) $totalValue,
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $inventoryAccount->id,
                'debit_amount' => 0,
                'credit_amount' => (string) $totalValue,
                'currency_code' => 'GBP',
            ]);

            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $issue->project_id,
                'party_id' => $project->party_id,
                'allocation_type' => 'POOL_SHARE',
                'amount' => (string) $totalValue,
                'machine_id' => $issue->machine_id,
                'rule_snapshot' => ['source' => 'inv_issue'],
            ]);

            $issue->update(['status' => 'POSTED', 'posting_group_id' => $postingGroup->id, 'posting_date' => $postingDateObj]);

            return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
        });
    }

    /**
     * Reverse a posted GRN. Creates reversing posting group and negating stock movements.
     *
     * @throws \Exception
     */
    public function reverseGRN(string $grnId, string $tenantId, string $postingDate, string $reason): PostingGroup
    {
        return DB::transaction(function () use ($grnId, $tenantId, $postingDate, $reason) {
            $grn = InvGrn::where('id', $grnId)->where('tenant_id', $tenantId)->firstOrFail();
            if (!$grn->isPosted()) {
                throw new \Exception('Only posted GRNs can be reversed.');
            }
            if ($grn->isReversed()) {
                throw new \Exception('GRN is already reversed.');
            }

            $reversalPG = $this->reversalService->reversePostingGroup($grn->posting_group_id, $tenantId, $postingDate, $reason);

            $existing = InvStockMovement::where('tenant_id', $tenantId)->where('posting_group_id', $reversalPG->id)->exists();
            if ($existing) {
                $grn->update(['status' => 'REVERSED']);
                return $reversalPG->load(['ledgerEntries.account']);
            }

            $originals = InvStockMovement::where('tenant_id', $tenantId)
                ->where('posting_group_id', $grn->posting_group_id)
                ->get();

            $postingDateStr = Carbon::parse($postingDate)->format('Y-m-d');
            foreach ($originals as $o) {
                $this->stockService->applyMovement(
                    $tenantId,
                    $reversalPG->id,
                    $o->store_id,
                    $o->item_id,
                    $o->movement_type,
                    (string) (-(float) $o->qty_delta),
                    (string) (-(float) $o->value_delta),
                    (string) $o->unit_cost_snapshot,
                    $postingDateStr,
                    $o->source_type,
                    $o->source_id
                );
            }

            $grn->update(['status' => 'REVERSED']);

            return $reversalPG->fresh(['ledgerEntries.account']);
        });
    }

    /**
     * Reverse a posted Issue.
     *
     * @throws \Exception
     */
    public function reverseIssue(string $issueId, string $tenantId, string $postingDate, string $reason): PostingGroup
    {
        return DB::transaction(function () use ($issueId, $tenantId, $postingDate, $reason) {
            $issue = InvIssue::where('id', $issueId)->where('tenant_id', $tenantId)->firstOrFail();
            if (!$issue->isPosted()) {
                throw new \Exception('Only posted Issues can be reversed.');
            }
            if ($issue->isReversed()) {
                throw new \Exception('Issue is already reversed.');
            }

            $reversalPG = $this->reversalService->reversePostingGroup($issue->posting_group_id, $tenantId, $postingDate, $reason);

            $existing = InvStockMovement::where('tenant_id', $tenantId)->where('posting_group_id', $reversalPG->id)->exists();
            if ($existing) {
                $issue->update(['status' => 'REVERSED']);
                return $reversalPG->load(['ledgerEntries.account', 'allocationRows']);
            }

            $originals = InvStockMovement::where('tenant_id', $tenantId)
                ->where('posting_group_id', $issue->posting_group_id)
                ->get();

            $postingDateStr = Carbon::parse($postingDate)->format('Y-m-d');
            foreach ($originals as $o) {
                $this->stockService->applyMovement(
                    $tenantId,
                    $reversalPG->id,
                    $o->store_id,
                    $o->item_id,
                    $o->movement_type,
                    (string) (-(float) $o->qty_delta),
                    (string) (-(float) $o->value_delta),
                    (string) $o->unit_cost_snapshot,
                    $postingDateStr,
                    $o->source_type,
                    $o->source_id
                );
            }

            $issue->update(['status' => 'REVERSED']);

            return $reversalPG->fresh(['ledgerEntries.account', 'allocationRows']);
        });
    }

    /**
     * Post a Transfer. Idempotent via idempotency_key. No GL entries (movements only).
     *
     * @throws \Exception
     */
    public function postTransfer(string $transferId, string $tenantId, string $postingDate, string $idempotencyKey): PostingGroup
    {
        return DB::transaction(function () use ($transferId, $tenantId, $postingDate, $idempotencyKey) {
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['ledgerEntries.account']);
            }

            $transfer = InvTransfer::where('id', $transferId)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();
            $transfer->load(['lines.item', 'fromStore', 'toStore']);

            if ($transfer->from_store_id === $transfer->to_store_id) {
                throw new \Exception('From store and to store must be different.');
            }

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');

            InvStore::where('id', $transfer->from_store_id)->where('tenant_id', $tenantId)->firstOrFail();
            InvStore::where('id', $transfer->to_store_id)->where('tenant_id', $tenantId)->firstOrFail();

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => null,
                'source_type' => 'INVENTORY_TRANSFER',
                'source_id' => $transfer->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($transfer->lines as $line) {
                InvItem::where('id', $line->item_id)->where('tenant_id', $tenantId)->firstOrFail();

                $balance = $this->stockService->getOrCreateBalance($tenantId, $transfer->from_store_id, $line->item_id);
                $qtyOnHand = (float) $balance->qty_on_hand;
                $qty = (float) $line->qty;
                if ($qtyOnHand < $qty) {
                    throw new \Exception("Insufficient stock in source store for item {$line->item->name}: on hand {$qtyOnHand}, required {$qty}.");
                }

                $wac = (string) $balance->wac_cost;
                $value = (string) ($qty * (float) $wac);

                $this->stockService->applyMovement(
                    $tenantId,
                    $postingGroup->id,
                    $transfer->from_store_id,
                    $line->item_id,
                    'TRANSFER_OUT',
                    (string) (-$qty),
                    (string) (-(float) $value),
                    $wac,
                    $postingDateObj,
                    'inv_transfer',
                    $transfer->id
                );

                $this->stockService->applyMovement(
                    $tenantId,
                    $postingGroup->id,
                    $transfer->to_store_id,
                    $line->item_id,
                    'TRANSFER_IN',
                    (string) $qty,
                    $value,
                    $wac,
                    $postingDateObj,
                    'inv_transfer',
                    $transfer->id
                );

                $line->update(['unit_cost_snapshot' => $wac, 'line_total' => $value]);
            }

            $transfer->update(['status' => 'POSTED', 'posting_group_id' => $postingGroup->id, 'posting_date' => $postingDateObj]);

            return $postingGroup->fresh(['ledgerEntries.account']);
        });
    }

    /**
     * Reverse a posted Transfer.
     *
     * @throws \Exception
     */
    public function reverseTransfer(string $transferId, string $tenantId, string $postingDate, string $reason): PostingGroup
    {
        return DB::transaction(function () use ($transferId, $tenantId, $postingDate, $reason) {
            $transfer = InvTransfer::where('id', $transferId)->where('tenant_id', $tenantId)->firstOrFail();
            if (!$transfer->isPosted()) {
                throw new \Exception('Only posted transfers can be reversed.');
            }
            if ($transfer->isReversed()) {
                throw new \Exception('Transfer is already reversed.');
            }

            $reversalPG = $this->reversalService->reversePostingGroup($transfer->posting_group_id, $tenantId, $postingDate, $reason);

            $existing = InvStockMovement::where('tenant_id', $tenantId)->where('posting_group_id', $reversalPG->id)->exists();
            if ($existing) {
                $transfer->update(['status' => 'REVERSED']);
                return $reversalPG->load(['ledgerEntries.account']);
            }

            $originals = InvStockMovement::where('tenant_id', $tenantId)
                ->where('posting_group_id', $transfer->posting_group_id)
                ->get();

            $postingDateStr = Carbon::parse($postingDate)->format('Y-m-d');
            foreach ($originals as $o) {
                $this->stockService->applyMovement(
                    $tenantId,
                    $reversalPG->id,
                    $o->store_id,
                    $o->item_id,
                    $o->movement_type,
                    (string) (-(float) $o->qty_delta),
                    (string) (-(float) $o->value_delta),
                    (string) $o->unit_cost_snapshot,
                    $postingDateStr,
                    $o->source_type,
                    $o->source_id
                );
            }

            $transfer->update(['status' => 'REVERSED']);

            return $reversalPG->fresh(['ledgerEntries.account']);
        });
    }

    /**
     * Post an Adjustment. Idempotent via idempotency_key. GL: Dr/Cr STOCK_VARIANCE and INVENTORY_INPUTS.
     *
     * @throws \Exception
     */
    public function postAdjustment(string $adjustmentId, string $tenantId, string $postingDate, string $idempotencyKey): PostingGroup
    {
        return DB::transaction(function () use ($adjustmentId, $tenantId, $postingDate, $idempotencyKey) {
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['ledgerEntries.account']);
            }

            $adj = InvAdjustment::where('id', $adjustmentId)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();
            $adj->load(['lines.item', 'store']);

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');

            InvStore::where('id', $adj->store_id)->where('tenant_id', $tenantId)->firstOrFail();

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => null,
                'source_type' => 'INVENTORY_ADJUSTMENT',
                'source_id' => $adj->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $idempotencyKey,
            ]);

            $inventoryAccount = $this->accountService->getByCode($tenantId, 'INVENTORY_INPUTS');
            $varianceAccount = $this->accountService->getByCode($tenantId, 'STOCK_VARIANCE');

            foreach ($adj->lines as $line) {
                InvItem::where('id', $line->item_id)->where('tenant_id', $tenantId)->firstOrFail();

                $qtyDelta = (float) $line->qty_delta;
                if ($qtyDelta == 0) {
                    throw new \Exception("Adjustment line qty_delta cannot be zero.");
                }

                $balance = $this->stockService->getOrCreateBalance($tenantId, $adj->store_id, $line->item_id);
                $wac = (float) $balance->wac_cost;
                if ($qtyDelta < 0) {
                    $qtyOnHand = (float) $balance->qty_on_hand;
                    if ($qtyOnHand < -$qtyDelta) {
                        throw new \Exception("Insufficient stock for item {$line->item->name}: on hand {$qtyOnHand}, adjustment " . $line->qty_delta . ".");
                    }
                }
                $unitCost = $wac;
                $valueDelta = $qtyDelta * $unitCost;
                $lineTotal = (string) abs($valueDelta);

                $this->stockService->applyMovement(
                    $tenantId,
                    $postingGroup->id,
                    $adj->store_id,
                    $line->item_id,
                    'ADJUST',
                    (string) $qtyDelta,
                    (string) $valueDelta,
                    (string) $unitCost,
                    $postingDateObj,
                    'inv_adjustment',
                    $adj->id
                );

                $line->update(['unit_cost_snapshot' => (string) $unitCost, 'line_total' => (string) abs($valueDelta)]);

                if (abs($valueDelta) >= 0.001) {
                    if ($qtyDelta < 0) {
                        LedgerEntry::create([
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'account_id' => $varianceAccount->id,
                            'debit_amount' => (string) abs($valueDelta),
                            'credit_amount' => 0,
                            'currency_code' => 'GBP',
                        ]);
                        LedgerEntry::create([
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'account_id' => $inventoryAccount->id,
                            'debit_amount' => 0,
                            'credit_amount' => (string) abs($valueDelta),
                            'currency_code' => 'GBP',
                        ]);
                    } else {
                        LedgerEntry::create([
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'account_id' => $inventoryAccount->id,
                            'debit_amount' => (string) abs($valueDelta),
                            'credit_amount' => 0,
                            'currency_code' => 'GBP',
                        ]);
                        LedgerEntry::create([
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'account_id' => $varianceAccount->id,
                            'debit_amount' => 0,
                            'credit_amount' => (string) abs($valueDelta),
                            'currency_code' => 'GBP',
                        ]);
                    }
                }
            }

            $adj->update(['status' => 'POSTED', 'posting_group_id' => $postingGroup->id, 'posting_date' => $postingDateObj]);

            return $postingGroup->fresh(['ledgerEntries.account']);
        });
    }

    /**
     * Reverse a posted Adjustment.
     *
     * @throws \Exception
     */
    public function reverseAdjustment(string $adjustmentId, string $tenantId, string $postingDate, string $reason): PostingGroup
    {
        return DB::transaction(function () use ($adjustmentId, $tenantId, $postingDate, $reason) {
            $adj = InvAdjustment::where('id', $adjustmentId)->where('tenant_id', $tenantId)->firstOrFail();
            if (!$adj->isPosted()) {
                throw new \Exception('Only posted adjustments can be reversed.');
            }
            if ($adj->isReversed()) {
                throw new \Exception('Adjustment is already reversed.');
            }

            $reversalPG = $this->reversalService->reversePostingGroup($adj->posting_group_id, $tenantId, $postingDate, $reason);

            $existing = InvStockMovement::where('tenant_id', $tenantId)->where('posting_group_id', $reversalPG->id)->exists();
            if ($existing) {
                $adj->update(['status' => 'REVERSED']);
                return $reversalPG->load(['ledgerEntries.account']);
            }

            $originals = InvStockMovement::where('tenant_id', $tenantId)
                ->where('posting_group_id', $adj->posting_group_id)
                ->get();

            $postingDateStr = Carbon::parse($postingDate)->format('Y-m-d');
            foreach ($originals as $o) {
                $this->stockService->applyMovement(
                    $tenantId,
                    $reversalPG->id,
                    $o->store_id,
                    $o->item_id,
                    $o->movement_type,
                    (string) (-(float) $o->qty_delta),
                    (string) (-(float) $o->value_delta),
                    (string) $o->unit_cost_snapshot,
                    $postingDateStr,
                    $o->source_type,
                    $o->source_id
                );
            }

            $adj->update(['status' => 'REVERSED']);

            return $reversalPG->fresh(['ledgerEntries.account']);
        });
    }
}

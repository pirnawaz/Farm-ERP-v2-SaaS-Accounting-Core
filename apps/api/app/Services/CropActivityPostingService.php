<?php

namespace App\Services;

use App\Models\CropActivity;
use App\Models\CropActivityInput;
use App\Models\CropActivityLabour;
use App\Models\InvStockMovement;
use App\Models\LabWorkerBalance;
use App\Models\LedgerEntry;
use App\Models\AllocationRow;
use App\Models\PostingGroup;
use App\Models\CropCycle;
use App\Models\Project;
use App\Services\OperationalPostingGuard;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CropActivityPostingService
{
    public function __construct(
        private SystemAccountService $accountService,
        private ReversalService $reversalService,
        private InventoryStockService $stockService,
        private OperationalPostingGuard $guard
    ) {}

    /**
     * Post an activity. Idempotent via idempotency_key or (source_type, source_id).
     * Atomically: inventory movements, labour balances, GL, allocations, then mark POSTED.
     *
     * @throws \Exception
     */
    public function postActivity(string $activityId, string $tenantId, string $postingDate, ?string $idempotencyKey = null): PostingGroup
    {
        $key = $idempotencyKey ?? "crop_activity:{$activityId}:post";

        return DB::transaction(function () use ($activityId, $tenantId, $postingDate, $key) {
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing->load(['ledgerEntries.account', 'allocationRows']);
            }

            $existingBySource = PostingGroup::where('tenant_id', $tenantId)
                ->where('source_type', 'CROP_ACTIVITY')
                ->where('source_id', $activityId)
                ->first();
            if ($existingBySource) {
                return $existingBySource->load(['ledgerEntries.account', 'allocationRows']);
            }

            $activity = CropActivity::where('id', $activityId)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();
            $activity->load(['inputs.item', 'inputs.store', 'labour.worker', 'project', 'cropCycle']);

            if (!$activity->crop_cycle_id || !$activity->project_id) {
                throw new \Exception('Crop cycle and project are required for posting an activity.');
            }

            $this->guard->ensureCropCycleOpenForProject($activity->project_id, $tenantId);

            $cropCycle = CropCycle::where('id', $activity->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
            if ($cropCycle->start_date && $postingDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Posting date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $postingDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Posting date is after crop cycle end date.');
            }

            $project = Project::where('id', $activity->project_id)->where('tenant_id', $tenantId)->firstOrFail();

            $totalInputs = 0.0;
            foreach ($activity->inputs as $line) {
                $balance = $this->stockService->getOrCreateBalance($tenantId, $line->store_id, $line->item_id);
                $qtyOnHand = (float) $balance->qty_on_hand;
                $qty = (float) $line->qty;
                if ($qtyOnHand < $qty) {
                    throw new \Exception("Insufficient stock for item {$line->item->name}: on hand {$qtyOnHand}, required {$qty}.");
                }
                $wac = (string) $balance->wac_cost;
                $lineTotal = (string) ($qty * (float) $wac);
                $totalInputs += (float) $lineTotal;
            }

            $totalLabour = 0.0;
            foreach ($activity->labour as $line) {
                $amt = (float) $line->units * (float) $line->rate;
                $totalLabour += $amt;
            }

            if ($totalInputs < 0.001 && $totalLabour < 0.001) {
                throw new \Exception('Activity must have at least one input or one labour line with positive amount to post.');
            }

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $activity->crop_cycle_id,
                'source_type' => 'CROP_ACTIVITY',
                'source_id' => $activity->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $key,
            ]);

            foreach ($activity->inputs as $line) {
                $balance = $this->stockService->getOrCreateBalance($tenantId, $line->store_id, $line->item_id);
                $wac = (string) $balance->wac_cost;
                $qty = (float) $line->qty;
                $lineTotal = (string) ($qty * (float) $wac);

                $this->stockService->applyMovement(
                    $tenantId,
                    $postingGroup->id,
                    $line->store_id,
                    $line->item_id,
                    'ISSUE',
                    (string) (-$qty),
                    (string) (-(float) $lineTotal),
                    $wac,
                    $postingDateObj,
                    'crop_activity',
                    $activity->id
                );

                $line->update(['unit_cost_snapshot' => $wac, 'line_total' => $lineTotal]);
            }

            foreach ($activity->labour as $line) {
                $amt = (float) $line->units * (float) $line->rate;
                $balance = LabWorkerBalance::getOrCreate($tenantId, $line->worker_id);
                $balance->increment('payable_balance', $amt);
                $line->update(['amount' => (string) round($amt, 2)]);
            }

            $inputsExpenseAccount = $this->accountService->getByCode($tenantId, 'INPUTS_EXPENSE');
            $inventoryAccount = $this->accountService->getByCode($tenantId, 'INVENTORY_INPUTS');
            $labourExpenseAccount = $this->accountService->getByCode($tenantId, 'LABOUR_EXPENSE');
            $wagesPayableAccount = $this->accountService->getByCode($tenantId, 'WAGES_PAYABLE');

            if ($totalInputs >= 0.001) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $inputsExpenseAccount->id,
                    'debit_amount' => (string) round($totalInputs, 2),
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $inventoryAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => (string) round($totalInputs, 2),
                    'currency_code' => 'GBP',
                ]);
                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $activity->project_id,
                    'party_id' => $project->party_id,
                    'allocation_type' => 'POOL_SHARE',
                    'amount' => (string) round($totalInputs, 2),
                    'rule_snapshot' => ['source' => 'crop_activity', 'activity_id' => $activity->id, 'cost_type' => 'inputs'],
                ]);
            }

            if ($totalLabour >= 0.001) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $labourExpenseAccount->id,
                    'debit_amount' => (string) round($totalLabour, 2),
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $wagesPayableAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => (string) round($totalLabour, 2),
                    'currency_code' => 'GBP',
                ]);
                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $activity->project_id,
                    'party_id' => $project->party_id,
                    'allocation_type' => 'POOL_SHARE',
                    'amount' => (string) round($totalLabour, 2),
                    'rule_snapshot' => ['source' => 'crop_activity', 'activity_id' => $activity->id, 'cost_type' => 'labour'],
                ]);
            }

            $activity->update([
                'status' => 'POSTED',
                'posting_group_id' => $postingGroup->id,
                'posting_date' => $postingDateObj,
                'posted_at' => now(),
            ]);

            return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
        });
    }

    /**
     * Reverse a posted activity. Creates reversing PG (GL), negating stock movements, and decrements worker balances.
     *
     * @throws \Exception
     */
    public function reverseActivity(string $activityId, string $tenantId, string $postingDate, string $reason = '', ?string $idempotencyKey = null): PostingGroup
    {
        return DB::transaction(function () use ($activityId, $tenantId, $postingDate, $reason) {
            $activity = CropActivity::where('id', $activityId)->where('tenant_id', $tenantId)->firstOrFail();
            $activity->load(['inputs', 'labour']);

            if (!$activity->isPosted()) {
                throw new \Exception('Only posted activities can be reversed.');
            }
            if ($activity->isReversed()) {
                throw new \Exception('Activity is already reversed.');
            }

            $reversalPG = $this->reversalService->reversePostingGroup(
                $activity->posting_group_id,
                $tenantId,
                $postingDate,
                $reason
            );

            $existing = InvStockMovement::where('tenant_id', $tenantId)->where('posting_group_id', $reversalPG->id)->exists();
            if ($existing) {
                $activity->update(['status' => 'REVERSED', 'reversed_at' => now()]);
                return $reversalPG->load(['ledgerEntries.account', 'allocationRows']);
            }

            $originals = InvStockMovement::where('tenant_id', $tenantId)
                ->where('posting_group_id', $activity->posting_group_id)
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
                    'crop_activity',
                    $activity->id
                );
            }

            foreach ($activity->labour as $line) {
                $amt = (float) ($line->amount ?? 0);
                if ($amt >= 0.001) {
                    $balance = LabWorkerBalance::where('tenant_id', $tenantId)->where('worker_id', $line->worker_id)->first();
                    if ($balance) {
                        $balance->decrement('payable_balance', $amt);
                    }
                }
            }

            $activity->update(['status' => 'REVERSED', 'reversed_at' => now()]);

            return $reversalPG->fresh(['ledgerEntries.account', 'allocationRows']);
        });
    }
}

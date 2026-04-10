<?php

namespace App\Services;

use App\Exceptions\Machinery\MissingRateCardException;
use App\Models\CropCycle;
use App\Models\FieldJob;
use App\Models\FieldJobMachine;
use App\Models\Machine;
use App\Models\InvStockMovement;
use App\Models\LabWorkerBalance;
use App\Models\LedgerEntry;
use App\Models\AllocationRow;
use App\Models\MachineRateCard;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Services\Machinery\MachineryRateResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FieldJobPostingService
{
    private const SOURCE_TYPE = 'FIELD_JOB';

    private const MACHINERY_COST_THRESHOLD = 0.001;

    public function __construct(
        private SystemAccountService $accountService,
        private ReversalService $reversalService,
        private InventoryStockService $stockService,
        private OperationalPostingGuard $guard,
        private MachineryRateResolver $machineryRateResolver,
        private DuplicateWorkflowGuard $duplicateWorkflowGuard,
    ) {}

    /**
     * Post a field job. One PostingGroup (FIELD_JOB). Idempotent via idempotency_key or (source_type, source_id).
     *
     * @throws \Exception
     */
    public function postFieldJob(string $fieldJobId, string $tenantId, string $postingDate, ?string $idempotencyKey = null): PostingGroup
    {
        $key = $idempotencyKey ?? "field_job:{$fieldJobId}:post";

        return LedgerWriteGuard::scoped(static::class, function () use ($fieldJobId, $tenantId, $postingDate, $key) {
            return DB::transaction(function () use ($fieldJobId, $tenantId, $postingDate, $key) {
                $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
                if ($existing) {
                    return $existing->load(['ledgerEntries.account', 'allocationRows']);
                }

                $existingBySource = PostingGroup::where('tenant_id', $tenantId)
                    ->where('source_type', self::SOURCE_TYPE)
                    ->where('source_id', $fieldJobId)
                    ->first();
                if ($existingBySource) {
                    return $existingBySource->load(['ledgerEntries.account', 'allocationRows']);
                }

                $fieldJob = FieldJob::where('id', $fieldJobId)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();
                $fieldJob->load([
                    'inputs.item',
                    'inputs.store',
                    'labour.worker',
                    'machines.machine',
                    'project',
                    'cropCycle',
                ]);

                if (! $fieldJob->crop_cycle_id || ! $fieldJob->project_id) {
                    throw new \Exception('Crop cycle and project are required for posting a field job.');
                }

                $this->guard->ensureCropCycleOpenForProject($fieldJob->project_id, $tenantId);

                $cropCycle = CropCycle::where('id', $fieldJob->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
                $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
                $this->guard->assertPostingDateWithinCropCycleBounds($cropCycle, $postingDateObj);

                $project = Project::where('id', $fieldJob->project_id)->where('tenant_id', $tenantId)->firstOrFail();
                if (! $project->party_id) {
                    throw new \Exception('Project must have a party_id for allocation rows.');
                }

                $this->duplicateWorkflowGuard->assertFieldJobPostAllowed($fieldJob);

                $totalInputs = 0.0;
                foreach ($fieldJob->inputs as $line) {
                    $balance = $this->stockService->getOrCreateBalance($tenantId, $line->store_id, $line->item_id);
                    $qtyOnHand = (float) $balance->qty_on_hand;
                    $qty = (float) $line->qty;
                    if ($qtyOnHand < $qty) {
                        throw new \Exception("Insufficient stock for item {$line->item->name}: on hand {$qtyOnHand}, required {$qty}.");
                    }
                    $wac = (string) $balance->wac_cost;
                    $lineTotal = (float) ($qty * (float) $wac);
                    $totalInputs += $lineTotal;
                }

                $totalLabour = 0.0;
                foreach ($fieldJob->labour as $line) {
                    $totalLabour += (float) $line->units * (float) $line->rate;
                }

                $totalMachineUsage = 0.0;
                foreach ($fieldJob->machines as $m) {
                    $totalMachineUsage += (float) $m->usage_qty;
                }

                if ($totalInputs < 0.001 && $totalLabour < 0.001 && $totalMachineUsage < 0.001) {
                    throw new \Exception('Field job must have at least one input line, one labour line with positive amount, or one machine line with positive usage to post.');
                }

                $activityTypeId = $fieldJob->crop_activity_type_id;
                $machineCostPlans = [];
                $totalMachineryFinancial = 0.0;
                foreach ($fieldJob->machines as $mline) {
                    if ((float) $mline->usage_qty < self::MACHINERY_COST_THRESHOLD) {
                        continue;
                    }
                    $plan = $this->computeMachineLineFinancial($tenantId, $mline, $postingDateObj, $activityTypeId);
                    $machineCostPlans[$mline->id] = $plan;
                    if ($plan['lineAmount'] >= self::MACHINERY_COST_THRESHOLD) {
                        $totalMachineryFinancial += $plan['lineAmount'];
                    }
                }

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $fieldJob->crop_cycle_id,
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => $fieldJob->id,
                    'posting_date' => $postingDateObj,
                    'idempotency_key' => $key,
                ]);

                foreach ($fieldJob->inputs as $line) {
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
                        'field_job',
                        $fieldJob->id
                    );

                    $line->update(['unit_cost_snapshot' => $wac, 'line_total' => $lineTotal]);
                }

                foreach ($fieldJob->labour as $line) {
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
                        'project_id' => $fieldJob->project_id,
                        'party_id' => $project->party_id,
                        'allocation_type' => 'POOL_SHARE',
                        'allocation_scope' => 'SHARED',
                        'amount' => (string) round($totalInputs, 2),
                        'rule_snapshot' => ['source' => 'field_job', 'field_job_id' => $fieldJob->id, 'cost_type' => 'inputs'],
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
                        'project_id' => $fieldJob->project_id,
                        'party_id' => $project->party_id,
                        'allocation_type' => 'POOL_SHARE',
                        'allocation_scope' => 'SHARED',
                        'amount' => (string) round($totalLabour, 2),
                        'rule_snapshot' => ['source' => 'field_job', 'field_job_id' => $fieldJob->id, 'cost_type' => 'labour'],
                    ]);
                }

                if ($totalMachineryFinancial >= self::MACHINERY_COST_THRESHOLD) {
                    $machineryExpenseAccount = $this->accountService->getByCode($tenantId, 'EXP_SHARED');
                    $machineryIncomeAccount = $this->accountService->getByCode($tenantId, 'MACHINERY_SERVICE_INCOME');
                    $mf = round($totalMachineryFinancial, 2);
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $postingGroup->id,
                        'account_id' => $machineryExpenseAccount->id,
                        'debit_amount' => (string) $mf,
                        'credit_amount' => '0.00',
                        'currency_code' => 'GBP',
                    ]);
                    LedgerEntry::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $postingGroup->id,
                        'account_id' => $machineryIncomeAccount->id,
                        'debit_amount' => '0.00',
                        'credit_amount' => (string) $mf,
                        'currency_code' => 'GBP',
                    ]);
                }

                foreach ($fieldJob->machines as $mline) {
                    if ((float) $mline->usage_qty < self::MACHINERY_COST_THRESHOLD) {
                        continue;
                    }
                    $plan = $machineCostPlans[$mline->id] ?? null;
                    if ($plan === null) {
                        continue;
                    }
                    $machine = $plan['machine'];
                    $unit = $mline->meter_unit_snapshot ?: $machine->meter_unit;
                    if ($unit === null || $unit === '') {
                        throw new \Exception("Machine {$machine->name} has no meter unit; set meter_unit_snapshot or configure meter_unit on the machine.");
                    }

                    $mline->update([
                        'meter_unit_snapshot' => $unit,
                        'pricing_basis' => $plan['pricingBasis'],
                        'rate_snapshot' => $plan['rateSnapshot'],
                        'rate_card_id' => $plan['rateCardId'],
                        'amount' => (string) round($plan['lineAmount'], 2),
                    ]);

                    AllocationRow::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $postingGroup->id,
                        'project_id' => $fieldJob->project_id,
                        'party_id' => $project->party_id,
                        'allocation_type' => 'MACHINERY_USAGE',
                        'allocation_scope' => 'SHARED',
                        'amount' => null,
                        'quantity' => (string) $mline->usage_qty,
                        'unit' => $unit,
                        'machine_id' => $mline->machine_id,
                        'rule_snapshot' => [
                            'source' => 'field_job',
                            'field_job_id' => $fieldJob->id,
                            'field_job_machine_id' => $mline->id,
                            'machine_id' => $mline->machine_id,
                        ],
                    ]);

                    if ($plan['lineAmount'] >= self::MACHINERY_COST_THRESHOLD) {
                        $finUnit = $this->machineryFinancialUnit($plan['rateCard'], $machine);
                        AllocationRow::create([
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'project_id' => $fieldJob->project_id,
                            'party_id' => $project->party_id,
                            'allocation_type' => 'MACHINERY_SERVICE',
                            'allocation_scope' => 'SHARED',
                            'amount' => (string) round($plan['lineAmount'], 2),
                            'quantity' => (string) $plan['usageQty'],
                            'unit' => $finUnit,
                            'machine_id' => $mline->machine_id,
                            'rule_snapshot' => [
                                'source' => 'field_job',
                                'field_job_id' => $fieldJob->id,
                                'field_job_machine_id' => $mline->id,
                                'machine_id' => $mline->machine_id,
                                'pricing_basis' => $plan['pricingBasis'],
                                'rate_card_id' => $plan['rateCardId'],
                                'rate_snapshot' => $plan['rateSnapshot'],
                                'posting_date' => $postingDateObj,
                            ],
                        ]);
                    }
                }

                $fieldJob->update([
                    'status' => 'POSTED',
                    'posting_group_id' => $postingGroup->id,
                    'posting_date' => $postingDateObj,
                    'posted_at' => now(),
                ]);

                return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }

    /**
     * Reverse a posted field job: reversing posting group, stock, labour balances, document status.
     *
     * @throws \Exception
     */
    public function reverseFieldJob(string $fieldJobId, string $tenantId, string $postingDate, string $reason = ''): PostingGroup
    {
        return LedgerWriteGuard::scoped(static::class, function () use ($fieldJobId, $tenantId, $postingDate, $reason) {
            return DB::transaction(function () use ($fieldJobId, $tenantId, $postingDate, $reason) {
                $fieldJob = FieldJob::where('id', $fieldJobId)->where('tenant_id', $tenantId)->firstOrFail();
                $fieldJob->load(['inputs', 'labour', 'machines']);

                if (! $fieldJob->isPosted()) {
                    throw new \Exception('Only posted field jobs can be reversed.');
                }
                if ($fieldJob->isReversed()) {
                    throw new \Exception('Field job is already reversed.');
                }

                $reversalPG = $this->reversalService->reversePostingGroup(
                    $fieldJob->posting_group_id,
                    $tenantId,
                    $postingDate,
                    $reason
                );

                $existing = InvStockMovement::where('tenant_id', $tenantId)->where('posting_group_id', $reversalPG->id)->exists();
                if ($existing) {
                    $fieldJob->update([
                        'status' => 'REVERSED',
                        'reversed_at' => now(),
                        'reversal_posting_group_id' => $reversalPG->id,
                    ]);

                    return $reversalPG->load(['ledgerEntries.account', 'allocationRows']);
                }

                $originals = InvStockMovement::where('tenant_id', $tenantId)
                    ->where('posting_group_id', $fieldJob->posting_group_id)
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
                        'field_job',
                        $fieldJob->id
                    );
                }

                foreach ($fieldJob->labour as $line) {
                    $amt = (float) ($line->amount ?? 0);
                    if ($amt >= 0.001) {
                        $balance = LabWorkerBalance::where('tenant_id', $tenantId)->where('worker_id', $line->worker_id)->first();
                        if ($balance) {
                            $balance->decrement('payable_balance', $amt);
                        }
                    }
                }

                $fieldJob->update([
                    'status' => 'REVERSED',
                    'reversed_at' => now(),
                    'reversal_posting_group_id' => $reversalPG->id,
                ]);

                return $reversalPG->fresh(['ledgerEntries.account', 'allocationRows']);
            });
        });
    }

    /**
     * @return array{lineAmount: float, pricingBasis: string, rateSnapshot: ?string, rateCardId: ?string, rateCard: ?MachineRateCard, machine: Machine, usageQty: float}
     */
    private function computeMachineLineFinancial(
        string $tenantId,
        FieldJobMachine $mline,
        string $postingDateYmd,
        ?string $activityTypeId
    ): array {
        $machine = $mline->machine;
        if (! $machine) {
            throw new \Exception('Machine line is missing machine reference.');
        }
        $usageQty = (float) $mline->usage_qty;
        $rateCard = $this->machineryRateResolver->resolveRateCardForMachine(
            $tenantId,
            $machine,
            $postingDateYmd,
            $activityTypeId
        );

        $lineAmount = null;
        $pricingBasis = null;
        $rateSnapshot = null;
        $rateCardId = null;

        if ($rateCard !== null && $rateCard->base_rate !== null) {
            $lineAmount = round($usageQty * (float) $rateCard->base_rate, 2);
            $pricingBasis = FieldJobMachine::PRICING_BASIS_RATE_CARD;
            $rateSnapshot = (string) $rateCard->base_rate;
            $rateCardId = $rateCard->id;
        } elseif ($mline->amount !== null && (float) $mline->amount >= self::MACHINERY_COST_THRESHOLD) {
            $lineAmount = round((float) $mline->amount, 2);
            $pricingBasis = FieldJobMachine::PRICING_BASIS_MANUAL;
            $rateSnapshot = $mline->rate_snapshot !== null ? (string) $mline->rate_snapshot : null;
            $rateCardId = $mline->rate_card_id;
        } else {
            throw new MissingRateCardException(
                'No applicable machine rate card for this field job machine line, and no manual amount was provided. '.
                'Configure a rate card for the machine or enter an amount on the line.'
            );
        }

        return [
            'lineAmount' => (float) $lineAmount,
            'pricingBasis' => $pricingBasis,
            'rateSnapshot' => $rateSnapshot,
            'rateCardId' => $rateCardId,
            'rateCard' => $rateCard,
            'machine' => $machine,
            'usageQty' => $usageQty,
        ];
    }

    private function machineryFinancialUnit(?MachineRateCard $rateCard, Machine $machine): string
    {
        if ($rateCard && $rateCard->rate_unit) {
            return (string) $rateCard->rate_unit;
        }

        return $this->machineryRateResolver->mapMeterUnitToChargeUnit((string) $machine->meter_unit);
    }
}

<?php

namespace App\Services;

use App\Models\FieldJob;
use App\Models\InvStockBalance;
use App\Services\Machinery\MachineryRateResolver;

/**
 * Read-only draft cost preview for Field Jobs.
 *
 * - No writes
 * - No posting groups / ledger mutations
 * - Never fabricates posted values; unknowns are returned as null with reasons
 */
class FieldJobDraftCostPreviewService
{
    private function hasNumericValue(mixed $v): bool
    {
        if ($v === null) return false;
        if (is_string($v)) {
            return trim($v) !== '';
        }

        return is_numeric($v);
    }

    private function parseFloatOrNull(mixed $v): ?float
    {
        if (! $this->hasNumericValue($v)) return null;
        if (! is_numeric($v)) return null;

        return (float) $v;
    }

    public function __construct(
        private MachineryRateResolver $machineryRateResolver,
    ) {}

    public function preview(FieldJob $job): array
    {
        $tenantId = $job->tenant_id;
        $asOf = $job->job_date?->format('Y-m-d') ?? null;
        $activityTypeId = $job->crop_activity_type_id;

        $warnings = [];

        // Inputs
        $inputsSubtotal = 0.0;
        $inputsKnownSubtotal = 0.0;
        $inputsUnknownCount = 0;
        $inputsAllKnown = true;
        $inputLines = [];
        foreach ($job->inputs ?? [] as $line) {
            $qtyF = $this->parseFloatOrNull($line->qty);
            if ($qtyF === null) {
                $inputsAllKnown = false;
                $inputsUnknownCount++;
                $inputLines[] = [
                    'field_job_input_id' => $line->id,
                    'store_id' => $line->store_id,
                    'item_id' => $line->item_id,
                    'qty' => (string) $line->qty,
                    'unit_cost_estimate' => null,
                    'line_total_estimate' => null,
                    'valuation' => 'VALUED_ON_POSTING',
                    'warnings' => ['MISSING_QTY'],
                ];
                continue;
            }
            $qty = $qtyF;

            $balance = InvStockBalance::query()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $line->store_id)
                ->where('item_id', $line->item_id)
                ->first();

            if (! $balance) {
                $inputsAllKnown = false;
                $inputsUnknownCount++;
                $inputLines[] = [
                    'field_job_input_id' => $line->id,
                    'store_id' => $line->store_id,
                    'item_id' => $line->item_id,
                    'qty' => (string) $line->qty,
                    'unit_cost_estimate' => null,
                    'line_total_estimate' => null,
                    'valuation' => 'VALUED_ON_POSTING',
                    'warnings' => ['MISSING_STOCK_BALANCE'],
                ];
                continue;
            }

            $qtyOnHand = (float) $balance->qty_on_hand;
            $wac = (float) $balance->wac_cost;
            $lineTotal = round($qty * $wac, 2);
            $inputsSubtotal += $lineTotal;
            $inputsKnownSubtotal += $lineTotal;

            $lineWarnings = [];
            if ($qtyOnHand + 1e-9 < $qty) {
                $lineWarnings[] = 'INSUFFICIENT_STOCK_ON_HAND';
                $warnings[] = [
                    'type' => 'INSUFFICIENT_STOCK_ON_HAND',
                    'field_job_input_id' => $line->id,
                    'store_id' => $line->store_id,
                    'item_id' => $line->item_id,
                    'qty_on_hand' => (string) $balance->qty_on_hand,
                    'qty_required' => (string) $line->qty,
                ];
            }

            $inputLines[] = [
                'field_job_input_id' => $line->id,
                'store_id' => $line->store_id,
                'item_id' => $line->item_id,
                'qty' => (string) $line->qty,
                'unit_cost_estimate' => (string) $balance->wac_cost,
                'line_total_estimate' => (string) number_format($lineTotal, 2, '.', ''),
                'valuation' => 'WAC_ESTIMATE',
                'warnings' => $lineWarnings,
            ];
        }

        // Labour
        $labourSubtotal = 0.0;
        $labourKnownSubtotal = 0.0;
        $labourUnknownCount = 0;
        $labourAllKnown = true;
        $labourLines = [];
        foreach ($job->labour ?? [] as $line) {
            // Explicit amount is authoritative even if zero.
            $explicitAmountF = $this->parseFloatOrNull($line->amount);

            $amount = null;
            $pricingBasis = null;
            $lineWarnings = [];

            if ($explicitAmountF !== null) {
                $amount = round($explicitAmountF, 2);
                $pricingBasis = 'EXPLICIT_AMOUNT';
            } else {
                $unitsF = $this->parseFloatOrNull($line->units);
                $rateF = $this->parseFloatOrNull($line->rate);
                if ($unitsF !== null && $rateF !== null) {
                    $amount = round($unitsF * $rateF, 2);
                    $pricingBasis = 'UNITS_X_RATE';
                } else {
                    $labourAllKnown = false;
                    $labourUnknownCount++;
                    $lineWarnings[] = 'MISSING_UNITS_OR_RATE';
                }
            }

            if ($amount !== null) {
                $labourSubtotal += $amount;
                $labourKnownSubtotal += $amount;
            }

            $labourLines[] = [
                'field_job_labour_id' => $line->id,
                'worker_id' => $line->worker_id,
                'units' => (string) $line->units,
                'rate' => (string) $line->rate,
                'amount_estimate' => $amount !== null ? (string) number_format($amount, 2, '.', '') : null,
                'pricing_basis' => $pricingBasis ?? 'VALUED_ON_POSTING',
                'warnings' => $lineWarnings,
            ];
        }

        // Machinery
        $machinerySubtotal = 0.0;
        $machineryKnownSubtotal = 0.0;
        $machineryUnknownCount = 0;
        $machineryAllKnown = true;
        $machineLines = [];
        foreach ($job->machines ?? [] as $mline) {
            $usageQtyF = $this->parseFloatOrNull($mline->usage_qty);
            $usageQty = $usageQtyF ?? 0.0;

            // Explicit amount is authoritative even if zero.
            $explicitAmountF = $this->parseFloatOrNull($mline->amount);

            if ($explicitAmountF !== null) {
                $amt = round($explicitAmountF, 2);
                $machinerySubtotal += $amt;
                $machineryKnownSubtotal += $amt;
                $machineLines[] = [
                    'field_job_machine_id' => $mline->id,
                    'machine_id' => $mline->machine_id,
                    'usage_qty' => (string) $mline->usage_qty,
                    'rate_estimate' => $mline->rate_snapshot !== null ? (string) $mline->rate_snapshot : null,
                    'amount_estimate' => (string) number_format($amt, 2, '.', ''),
                    'pricing_basis' => 'MANUAL_AMOUNT',
                    'rate_card_id' => $mline->rate_card_id,
                    'warnings' => [],
                ];
                continue;
            }

            // If a rate snapshot exists and usage qty exists, that is a safe estimate.
            $rateSnapshotF = $this->parseFloatOrNull($mline->rate_snapshot);
            if ($rateSnapshotF !== null && $usageQtyF !== null) {
                $amt = round($usageQtyF * $rateSnapshotF, 2);
                $machinerySubtotal += $amt;
                $machineryKnownSubtotal += $amt;
                $machineLines[] = [
                    'field_job_machine_id' => $mline->id,
                    'machine_id' => $mline->machine_id,
                    'usage_qty' => (string) $mline->usage_qty,
                    'rate_estimate' => (string) $mline->rate_snapshot,
                    'amount_estimate' => (string) number_format($amt, 2, '.', ''),
                    'pricing_basis' => 'RATE_SNAPSHOT_ESTIMATE',
                    'rate_card_id' => $mline->rate_card_id,
                    'warnings' => [],
                ];
                continue;
            }

            // Estimate from rate card (when possible). If not possible, keep unknown.
            try {
                $machine = $mline->machine;
                if (! $machine || ! $asOf || $usageQtyF === null) {
                    $machineryAllKnown = false;
                    $machineryUnknownCount++;
                    $machineLines[] = [
                        'field_job_machine_id' => $mline->id,
                        'machine_id' => $mline->machine_id,
                        'usage_qty' => (string) $mline->usage_qty,
                        'rate_estimate' => null,
                        'amount_estimate' => null,
                        'pricing_basis' => 'VALUED_ON_POSTING',
                        'rate_card_id' => null,
                        'warnings' => array_values(array_filter([
                            ! $machine ? 'MISSING_MACHINE_REFERENCE' : null,
                            $usageQtyF === null ? 'MISSING_USAGE_QTY' : null,
                        ])),
                    ];
                    continue;
                }

                $rateCard = $this->machineryRateResolver->resolveRateCardForMachine(
                    $tenantId,
                    $machine,
                    $asOf,
                    $activityTypeId
                );

                if (! $rateCard || $rateCard->base_rate === null) {
                    $machineryAllKnown = false;
                    $machineryUnknownCount++;
                    $machineLines[] = [
                        'field_job_machine_id' => $mline->id,
                        'machine_id' => $mline->machine_id,
                        'usage_qty' => (string) $mline->usage_qty,
                        'rate_estimate' => null,
                        'amount_estimate' => null,
                        'pricing_basis' => 'VALUED_ON_POSTING',
                        'rate_card_id' => $rateCard?->id,
                        'warnings' => ['MISSING_RATE_CARD'],
                    ];
                    continue;
                }

                $rate = (float) $rateCard->base_rate;
                $amt = round($usageQty * $rate, 2);
                $machinerySubtotal += $amt;
                $machineryKnownSubtotal += $amt;

                $machineLines[] = [
                    'field_job_machine_id' => $mline->id,
                    'machine_id' => $mline->machine_id,
                    'usage_qty' => (string) $mline->usage_qty,
                    'rate_estimate' => (string) $rateCard->base_rate,
                    'amount_estimate' => (string) number_format($amt, 2, '.', ''),
                    'pricing_basis' => 'RATE_CARD_ESTIMATE',
                    'rate_card_id' => $rateCard->id,
                    'warnings' => [],
                ];
            } catch (\Throwable $e) {
                $machineryAllKnown = false;
                $machineryUnknownCount++;
                $machineLines[] = [
                    'field_job_machine_id' => $mline->id,
                    'machine_id' => $mline->machine_id,
                    'usage_qty' => (string) $mline->usage_qty,
                    'rate_estimate' => null,
                    'amount_estimate' => null,
                    'pricing_basis' => 'VALUED_ON_POSTING',
                    'rate_card_id' => null,
                    'warnings' => ['RATE_LOOKUP_FAILED'],
                ];
            }
        }

        $summaryAllKnown = $inputsAllKnown && $labourAllKnown && $machineryAllKnown;
        $grandTotal = $summaryAllKnown
            ? round($inputsSubtotal + $labourSubtotal + $machinerySubtotal, 2)
            : null;

        $knownTotal = round($inputsKnownSubtotal + $labourKnownSubtotal + $machineryKnownSubtotal, 2);
        $unknownCount = $inputsUnknownCount + $labourUnknownCount + $machineryUnknownCount;

        return [
            'field_job_id' => $job->id,
            'status' => $job->status,
            'as_of_date' => $asOf,
            'inputs' => [
                'lines' => $inputLines,
                'subtotal_estimate' => $inputsAllKnown ? (string) number_format(round($inputsSubtotal, 2), 2, '.', '') : null,
                'known_subtotal_estimate' => (string) number_format(round($inputsKnownSubtotal, 2), 2, '.', ''),
                'unknown_lines_count' => $inputsUnknownCount,
                'all_known' => $inputsAllKnown,
            ],
            'labour' => [
                'lines' => $labourLines,
                'subtotal_estimate' => $labourAllKnown ? (string) number_format(round($labourSubtotal, 2), 2, '.', '') : null,
                'known_subtotal_estimate' => (string) number_format(round($labourKnownSubtotal, 2), 2, '.', ''),
                'unknown_lines_count' => $labourUnknownCount,
                'all_known' => $labourAllKnown,
            ],
            'machinery' => [
                'lines' => $machineLines,
                'subtotal_estimate' => $machineryAllKnown ? (string) number_format(round($machinerySubtotal, 2), 2, '.', '') : null,
                'known_subtotal_estimate' => (string) number_format(round($machineryKnownSubtotal, 2), 2, '.', ''),
                'unknown_lines_count' => $machineryUnknownCount,
                'all_known' => $machineryAllKnown,
            ],
            'summary' => [
                'grand_total_estimate' => $grandTotal !== null ? (string) number_format($grandTotal, 2, '.', '') : null,
                'known_total_estimate' => (string) number_format($knownTotal, 2, '.', ''),
                'unknown_lines_count' => $unknownCount,
                'all_known' => $summaryAllKnown,
            ],
            'warnings' => $warnings,
        ];
    }
}


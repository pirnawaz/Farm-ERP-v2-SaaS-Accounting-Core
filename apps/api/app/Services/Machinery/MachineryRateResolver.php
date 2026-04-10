<?php

namespace App\Services\Machinery;

use App\Exceptions\Machinery\UnsupportedMeterUnitException;
use App\Models\Machine;
use App\Models\MachineRateCard;

/**
 * Shared rate-card resolution for machinery billing and Field Job machine lines.
 * Mirrors priority rules previously on {@see MachineryChargeService::resolveRateCard()}.
 */
class MachineryRateResolver
{
    /**
     * Map machine physical meter to rate-card rate_unit enum.
     *
     * @throws UnsupportedMeterUnitException
     */
    public function mapMeterUnitToChargeUnit(string $meterUnit): string
    {
        $u = strtoupper(trim($meterUnit));

        return match ($u) {
            'HOURS', 'HR', 'HOUR' => MachineRateCard::RATE_UNIT_HOUR,
            'KM' => MachineRateCard::RATE_UNIT_KM,
            default => throw new UnsupportedMeterUnitException($meterUnit),
        };
    }

    /**
     * Resolve applicable rate card for a machine at posting date.
     * Priority: machine+activity_type → machine+null → machine_type+activity_type → machine_type+null.
     *
     * @param  string|null  $activityTypeId  Crop activity type id (crop_activity_types.id), when applicable
     */
    public function resolveRateCardForMachine(
        string $tenantId,
        Machine $machine,
        string $postingDate,
        ?string $activityTypeId = null
    ): ?MachineRateCard {
        $unit = $this->mapMeterUnitToChargeUnit((string) $machine->meter_unit);

        $dateRange = function ($q) use ($postingDate) {
            $q->where('effective_from', '<=', $postingDate)
                ->where(function ($q2) use ($postingDate) {
                    $q2->whereNull('effective_to')->orWhere('effective_to', '>=', $postingDate);
                });
        };

        if ($activityTypeId) {
            $rateCard = MachineRateCard::where('tenant_id', $tenantId)
                ->where('applies_to_mode', MachineRateCard::APPLIES_TO_MACHINE)
                ->where('machine_id', $machine->id)
                ->where('rate_unit', $unit)
                ->where('activity_type_id', $activityTypeId)
                ->where('is_active', true)
                ->where($dateRange)
                ->orderBy('effective_from', 'desc')
                ->first();
            if ($rateCard) {
                return $rateCard;
            }
        }

        $rateCard = MachineRateCard::where('tenant_id', $tenantId)
            ->where('applies_to_mode', MachineRateCard::APPLIES_TO_MACHINE)
            ->where('machine_id', $machine->id)
            ->where('rate_unit', $unit)
            ->whereNull('activity_type_id')
            ->where('is_active', true)
            ->where($dateRange)
            ->orderBy('effective_from', 'desc')
            ->first();
        if ($rateCard) {
            return $rateCard;
        }

        if ($activityTypeId) {
            $rateCard = MachineRateCard::where('tenant_id', $tenantId)
                ->where('applies_to_mode', MachineRateCard::APPLIES_TO_MACHINE_TYPE)
                ->where('machine_type', $machine->machine_type)
                ->where('rate_unit', $unit)
                ->where('activity_type_id', $activityTypeId)
                ->where('is_active', true)
                ->where($dateRange)
                ->orderBy('effective_from', 'desc')
                ->first();
            if ($rateCard) {
                return $rateCard;
            }
        }

        return MachineRateCard::where('tenant_id', $tenantId)
            ->where('applies_to_mode', MachineRateCard::APPLIES_TO_MACHINE_TYPE)
            ->where('machine_type', $machine->machine_type)
            ->where('rate_unit', $unit)
            ->whereNull('activity_type_id')
            ->where('is_active', true)
            ->where($dateRange)
            ->orderBy('effective_from', 'desc')
            ->first();
    }
}

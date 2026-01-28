<?php

namespace App\Services\Machinery;

use App\Models\MachineryCharge;
use App\Models\MachineryChargeLine;
use App\Models\MachineWorkLog;
use App\Models\MachineRateCard;
use App\Models\Machine;
use App\Models\Project;
use App\Models\Party;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MachineryChargeService
{
    private const PREFIX = 'MCH-';
    private const PAD_LENGTH = 6;

    /**
     * Generate draft charge(s) for a project from posted work logs.
     * If poolScope is not provided and work logs have mixed scopes, creates one charge per scope.
     * 
     * @return MachineryCharge|array Returns single charge or array of two charges (SHARED and HARI_ONLY)
     * @throws \Exception
     */
    public function generateDraftChargeForProject(
        string $tenantId,
        string $projectId,
        string $landlordPartyId,
        string $fromDate,
        string $toDate,
        ?string $poolScope = null,
        ?string $chargeDate = null
    ): MachineryCharge|array {
        // Validate project and landlord party
        $project = Project::where('id', $projectId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
        
        Party::where('id', $landlordPartyId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Query work logs
        $workLogsQuery = MachineWorkLog::where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('status', MachineWorkLog::STATUS_POSTED)
            ->whereBetween('posting_date', [$fromDate, $toDate])
            ->whereNull('machinery_charge_id')
            ->with(['machine']);

        if ($poolScope) {
            $workLogsQuery->where('pool_scope', $poolScope);
        }

        $workLogs = $workLogsQuery->get();

        if ($workLogs->isEmpty()) {
            throw new \Exception('No uncharged posted work logs found for the specified criteria.');
        }

        // Check for mixed pool scopes if poolScope not provided
        $scopes = $workLogs->pluck('pool_scope')->unique()->values();
        if (!$poolScope && $scopes->count() > 1) {
            // Create one charge per scope
            // Each createChargeForScope is already in its own transaction
            $charges = [];
            foreach ($scopes as $scope) {
                $scopeWorkLogs = $workLogs->filter(fn($wl) => $wl->pool_scope === $scope);
                $charges[] = $this->createChargeForScope(
                    $tenantId,
                    $project,
                    $landlordPartyId,
                    $fromDate,
                    $toDate,
                    $scope,
                    $chargeDate,
                    $scopeWorkLogs
                );
            }
            return $charges;
        }

        // Single charge
        $finalPoolScope = $poolScope ?? $scopes->first();
        return $this->createChargeForScope(
            $tenantId,
            $project,
            $landlordPartyId,
            $fromDate,
            $toDate,
            $finalPoolScope,
            $chargeDate,
            $workLogs
        );
    }

    /**
     * Create a charge for a specific pool scope and set of work logs.
     */
    private function createChargeForScope(
        string $tenantId,
        Project $project,
        string $landlordPartyId,
        string $fromDate,
        string $toDate,
        string $poolScope,
        ?string $chargeDate,
        $workLogs
    ): MachineryCharge {
        $chargeDate = $chargeDate ?? Carbon::now()->format('Y-m-d');
        $chargeNo = $this->generateChargeNo($tenantId);

        return DB::transaction(function () use (
            $tenantId,
            $project,
            $landlordPartyId,
            $chargeDate,
            $chargeNo,
            $poolScope,
            $workLogs
        ) {
            $lines = [];
            $missingRateCards = [];

            // Resolve rate card for each work log
            foreach ($workLogs as $workLog) {
                $rateCard = $this->resolveRateCard($tenantId, $workLog, $workLog->posting_date);
                
                if (!$rateCard) {
                    $missingRateCards[] = $workLog->work_log_no . ' (machine: ' . $workLog->machine->code . ')';
                    continue;
                }

                // Map machine.meter_unit to charge line unit
                $unit = $this->mapMeterUnitToChargeUnit($workLog->machine->meter_unit);
                
                // Calculate rate (for now use base_rate; COST_PLUS pricing not yet implemented)
                $rate = (float) $rateCard->base_rate;
                // TODO: Implement COST_PLUS pricing model calculation when cost tracking is available
                
                $usageQty = (float) $workLog->usage_qty;
                $amount = round($usageQty * $rate, 2);

                $lines[] = [
                    'workLog' => $workLog,
                    'usage_qty' => $usageQty,
                    'unit' => $unit,
                    'rate' => $rate,
                    'amount' => $amount,
                    'rate_card_id' => $rateCard->id,
                ];
            }

            if (!empty($missingRateCards)) {
                throw new \Exception(
                    'Rate cards not found for work logs: ' . implode(', ', $missingRateCards) . 
                    '. Please create rate cards for these machines/machine types.'
                );
            }

            if (empty($lines)) {
                throw new \Exception('No valid work logs to charge (all missing rate cards).');
            }

            // Calculate total amount
            $totalAmount = array_sum(array_column($lines, 'amount'));

            // Create charge
            $charge = MachineryCharge::create([
                'tenant_id' => $tenantId,
                'charge_no' => $chargeNo,
                'status' => MachineryCharge::STATUS_DRAFT,
                'landlord_party_id' => $landlordPartyId,
                'project_id' => $project->id,
                'crop_cycle_id' => $project->crop_cycle_id,
                'pool_scope' => $poolScope,
                'charge_date' => $chargeDate,
                'total_amount' => $totalAmount,
            ]);

            // Create lines and link work logs
            foreach ($lines as $lineData) {
                MachineryChargeLine::create([
                    'tenant_id' => $tenantId,
                    'machinery_charge_id' => $charge->id,
                    'machine_work_log_id' => $lineData['workLog']->id,
                    'usage_qty' => (string) $lineData['usage_qty'],
                    'unit' => $lineData['unit'],
                    'rate' => (string) $lineData['rate'],
                    'amount' => (string) $lineData['amount'],
                    'rate_card_id' => $lineData['rate_card_id'],
                ]);

                // Reserve work log (prevent double charging)
                $lineData['workLog']->update(['machinery_charge_id' => $charge->id]);
            }

            return $charge->fresh(['lines.workLog.machine', 'lines.rateCard', 'project', 'cropCycle', 'landlordParty']);
        });
    }

    /**
     * Resolve rate card for a work log based on posting date.
     * Priority: MACHINE-specific, then MACHINE_TYPE.
     */
    private function resolveRateCard(string $tenantId, MachineWorkLog $workLog, string $postingDate): ?MachineRateCard
    {
        $machine = $workLog->machine;
        $unit = $this->mapMeterUnitToChargeUnit($machine->meter_unit);

        // Try MACHINE-specific rate card first
        $rateCard = MachineRateCard::where('tenant_id', $tenantId)
            ->where('applies_to_mode', MachineRateCard::APPLIES_TO_MACHINE)
            ->where('machine_id', $machine->id)
            ->where('rate_unit', $unit)
            ->where('is_active', true)
            ->where('effective_from', '<=', $postingDate)
            ->where(function ($q) use ($postingDate) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $postingDate);
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        if ($rateCard) {
            return $rateCard;
        }

        // Try MACHINE_TYPE rate card
        $rateCard = MachineRateCard::where('tenant_id', $tenantId)
            ->where('applies_to_mode', MachineRateCard::APPLIES_TO_MACHINE_TYPE)
            ->where('machine_type', $machine->machine_type)
            ->where('rate_unit', $unit)
            ->where('is_active', true)
            ->where('effective_from', '<=', $postingDate)
            ->where(function ($q) use ($postingDate) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $postingDate);
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        return $rateCard;
    }

    /**
     * Map machine meter_unit to charge line unit.
     */
    private function mapMeterUnitToChargeUnit(string $meterUnit): string
    {
        return match ($meterUnit) {
            'HOURS' => MachineRateCard::RATE_UNIT_HOUR,
            'KM' => MachineRateCard::RATE_UNIT_KM,
            default => throw new \Exception("Unsupported meter unit: {$meterUnit}"),
        };
    }

    /**
     * Generate unique charge number for tenant.
     */
    private function generateChargeNo(string $tenantId): string
    {
        $last = MachineryCharge::where('tenant_id', $tenantId)
            ->where('charge_no', 'like', self::PREFIX . '%')
            ->orderByRaw('LENGTH(charge_no) DESC, charge_no DESC')
            ->first();

        $next = 1;
        if ($last && preg_match('/^' . preg_quote(self::PREFIX, '/') . '(\d+)$/', $last->charge_no, $m)) {
            $next = (int) $m[1] + 1;
        }

        return self::PREFIX . str_pad((string) $next, self::PAD_LENGTH, '0', STR_PAD_LEFT);
    }
}

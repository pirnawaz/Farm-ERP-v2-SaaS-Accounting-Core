<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountingPeriodService
{
    /**
     * Get the period that contains the given date, if any.
     */
    public function getPeriodForDate(string $tenantId, string $date): ?AccountingPeriod
    {
        $d = Carbon::parse($date)->format('Y-m-d');
        return AccountingPeriod::where('tenant_id', $tenantId)
            ->where('period_start', '<=', $d)
            ->where('period_end', '>=', $d)
            ->first();
    }

    /**
     * Get period for date; if none exists, auto-create an OPEN monthly period (Option B) and return it.
     */
    public function getOrCreatePeriodForDate(string $tenantId, string $date): AccountingPeriod
    {
        $period = $this->getPeriodForDate($tenantId, $date);
        if ($period) {
            return $period;
        }
        $dt = Carbon::parse($date);
        return $this->createMonthlyPeriod($tenantId, (int) $dt->format('Y'), (int) $dt->format('m'), null);
    }

    /**
     * Create a single month period (OPEN) and log CREATED event.
     */
    public function createMonthlyPeriod(string $tenantId, int $year, int $month, ?string $actorId): AccountingPeriod
    {
        $start = Carbon::createFromDate($year, $month, 1)->format('Y-m-d');
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->format('Y-m-d');
        $name = sprintf('%04d-%02d', $year, $month);
        return $this->createPeriod($tenantId, $start, $end, $name, $actorId);
    }

    /**
     * Create period (OPEN). Validates no overlap. Logs CREATED event.
     */
    public function createPeriod(
        string $tenantId,
        string $periodStart,
        string $periodEnd,
        ?string $name = null,
        ?string $createdBy = null
    ): AccountingPeriod {
        $start = Carbon::parse($periodStart)->format('Y-m-d');
        $end = Carbon::parse($periodEnd)->format('Y-m-d');
        if ($start > $end) {
            throw new InvalidArgumentException('period_start must be before or equal to period_end.', 422);
        }
        $name = $name ?? $start . ' to ' . $end;

        $overlap = AccountingPeriod::where('tenant_id', $tenantId)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('period_start', [$start, $end])
                    ->orWhereBetween('period_end', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->where('period_start', '<=', $start)->where('period_end', '>=', $end);
                    });
            })
            ->exists();

        if ($overlap) {
            abort(409, 'An accounting period already exists that overlaps with the given date range.');
        }

        return DB::transaction(function () use ($tenantId, $start, $end, $name, $createdBy) {
            $period = AccountingPeriod::create([
                'tenant_id' => $tenantId,
                'period_start' => $start,
                'period_end' => $end,
                'name' => $name,
                'status' => AccountingPeriod::STATUS_OPEN,
                'created_by' => $createdBy,
            ]);
            AccountingPeriodEvent::create([
                'tenant_id' => $tenantId,
                'accounting_period_id' => $period->id,
                'event_type' => AccountingPeriodEvent::EVENT_CREATED,
                'notes' => 'Period created',
                'actor_id' => $createdBy,
                'created_at' => now(),
            ]);
            return $period;
        });
    }

    /**
     * List periods for tenant, optionally filtered by date range.
     *
     * @param string $tenantId
     * @param string|null $from Y-m-d
     * @param string|null $to Y-m-d
     * @return \Illuminate\Database\Eloquent\Collection<int, AccountingPeriod>
     */
    public function listPeriods(string $tenantId, ?string $from = null, ?string $to = null)
    {
        $query = AccountingPeriod::where('tenant_id', $tenantId)->orderBy('period_start', 'desc');
        if ($from !== null) {
            $query->where('period_end', '>=', Carbon::parse($from)->format('Y-m-d'));
        }
        if ($to !== null) {
            $query->where('period_start', '<=', Carbon::parse($to)->format('Y-m-d'));
        }
        return $query->get();
    }

    /**
     * Close period: set status CLOSED, closed_at/by, write CLOSED event.
     */
    public function closePeriod(string $periodId, string $tenantId, ?string $notes, ?string $closedBy): AccountingPeriod
    {
        $period = AccountingPeriod::where('id', $periodId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($period->status === AccountingPeriod::STATUS_CLOSED) {
            abort(409, 'Period is already closed.');
        }

        return DB::transaction(function () use ($period, $tenantId, $notes, $closedBy) {
            $period->update([
                'status' => AccountingPeriod::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $closedBy,
            ]);
            AccountingPeriodEvent::create([
                'tenant_id' => $tenantId,
                'accounting_period_id' => $period->id,
                'event_type' => AccountingPeriodEvent::EVENT_CLOSED,
                'notes' => $notes,
                'actor_id' => $closedBy,
                'created_at' => now(),
            ]);
            return $period->fresh();
        });
    }

    /**
     * Reopen period: set status OPEN, reopened_at/by, clear closed_* (optional), write REOPENED event.
     */
    public function reopenPeriod(string $periodId, string $tenantId, ?string $notes, ?string $reopenedBy): AccountingPeriod
    {
        $period = AccountingPeriod::where('id', $periodId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($period->status === AccountingPeriod::STATUS_OPEN) {
            abort(409, 'Period is already open.');
        }

        return DB::transaction(function () use ($period, $tenantId, $notes, $reopenedBy) {
            $period->update([
                'status' => AccountingPeriod::STATUS_OPEN,
                'reopened_at' => now(),
                'reopened_by' => $reopenedBy,
            ]);
            AccountingPeriodEvent::create([
                'tenant_id' => $tenantId,
                'accounting_period_id' => $period->id,
                'event_type' => AccountingPeriodEvent::EVENT_REOPENED,
                'notes' => $notes,
                'actor_id' => $reopenedBy,
                'created_at' => now(),
            ]);
            return $period->fresh();
        });
    }

    /**
     * List events for a period (audit log).
     */
    public function listEvents(string $periodId, string $tenantId)
    {
        return AccountingPeriodEvent::where('accounting_period_id', $periodId)
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function isDateLocked(string $tenantId, string $date): bool
    {
        $period = $this->getPeriodForDate($tenantId, $date);
        return $period !== null && $period->status === AccountingPeriod::STATUS_CLOSED;
    }
}

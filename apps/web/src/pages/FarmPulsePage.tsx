import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import type { AccountBalanceRow } from '@farm-erp/shared';
import { useCropCycles } from '../hooks/useCropCycles';
import { useProductionUnits } from '../hooks/useProductionUnits';
import { useProjects } from '../hooks/useProjects';
import { useCropProfitability } from '../hooks/useReports';
import { usePayablesOutstanding } from '../hooks/useLabour';
import { useOperationalTransactions } from '../hooks/useOperationalTransactions';
import { useAlerts } from '../hooks/useAlerts';
import { useModules } from '../contexts/ModulesContext';
import { useRole } from '../hooks/useRole';
import { PageHeader } from '../components/PageHeader';
import { QuickActions } from '../components/QuickActions';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { useFormatting } from '../hooks/useFormatting';
import { getActiveCropCycleId } from '../utils/formDefaults';
import { computeNetPositionSigned } from '../utils/netPosition';
import { getMoneyColorClass } from '../utils/moneyStyles';
import { term } from '../config/terminology';

/** Normalize a date string to Y-m-d for API (from/to). */
function toYmd(dateStr: string): string {
  if (!dateStr) return dateStr;
  if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return dateStr;
  const d = new Date(dateStr);
  if (Number.isNaN(d.getTime())) return dateStr;
  return d.toISOString().slice(0, 10);
}

function HeroCard({
  title,
  value,
  valueColorClass,
  sub,
  link,
  howCalculated,
}: {
  title: string;
  value: string;
  /** Tailwind text color for the value line; empty string uses parent default gray. */
  valueColorClass?: string;
  sub?: string;
  link?: string;
  howCalculated?: string;
}) {
  const content = (
    <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
      <p className="text-sm font-medium text-gray-500">{title}</p>
      <p className="mt-1 text-xl font-semibold text-gray-900 tabular-nums">
        <span className={valueColorClass}>{value}</span>
      </p>
      {sub && <p className="mt-0.5 text-xs text-gray-400">{sub}</p>}
      {howCalculated && <p className="mt-0.5 text-xs text-gray-400">{howCalculated}</p>}
    </div>
  );
  if (link) return <Link to={link}>{content}</Link>;
  return content;
}

export default function FarmPulsePage() {
  const { formatMoney, formatDateRange } = useFormatting();
  const { isModuleEnabled } = useModules();
  const { hasRole } = useRole();
  const asOf = useMemo(() => new Date().toISOString().split('T')[0], []);
  const today = asOf;

  const isAdmin = hasRole('tenant_admin');
  const { data: dailyReview } = useQuery({
    queryKey: ['internal', 'daily-admin-review'],
    queryFn: () => apiClient.getDailyAdminReview(),
    staleTime: 60 * 1000,
    enabled: isAdmin,
  });

  const { data: cropCycles } = useCropCycles();
  const { data: productionUnits } = useProductionUnits();
  const [viewBy, setViewBy] = useState<'crop_cycle' | 'production_unit'>('crop_cycle');
  const [selectedProductionUnitId, setSelectedProductionUnitId] = useState<string>('');

  const activeCycleId = useMemo(() => getActiveCropCycleId(cropCycles), [cropCycles]);
  const activeCycle = useMemo(
    () => cropCycles?.find((c) => c.id === activeCycleId),
    [cropCycles, activeCycleId]
  );
  const selectedUnit = useMemo(
    () => productionUnits?.find((u) => u.id === selectedProductionUnitId),
    [productionUnits, selectedProductionUnitId]
  );

  const cycleStart = useMemo(() => {
    const raw =
      viewBy === 'production_unit' && selectedUnit
        ? selectedUnit.start_date
        : activeCycle?.start_date ?? today;
    return toYmd(raw ?? today);
  }, [viewBy, selectedUnit, activeCycle, today]);
  const cycleEnd = useMemo(() => {
    const todayYmd = toYmd(today);
    if (viewBy === 'production_unit' && selectedUnit) {
      const end = selectedUnit.end_date ? toYmd(selectedUnit.end_date) : null;
      return end && end < todayYmd ? end : todayYmd;
    }
    const end = activeCycle?.end_date ? toYmd(activeCycle.end_date) : null;
    return end && end < todayYmd ? end : todayYmd;
  }, [viewBy, selectedUnit, activeCycle, today]);

  const { data: accountBalances, isLoading: balancesLoading } = useQuery({
    queryKey: ['reports', 'account-balances', { as_of: asOf }],
    queryFn: () => apiClient.getAccountBalances({ as_of: asOf }),
    staleTime: 60 * 1000,
    gcTime: 5 * 60 * 1000,
  });

  const cash = useMemo(() => {
    if (!accountBalances) return null;
    const row = accountBalances.find((r: AccountBalanceRow) => r.account_code === 'CASH');
    return row ? parseFloat(row.balance) : 0;
  }, [accountBalances]);
  const bank = useMemo(() => {
    if (!accountBalances) return null;
    const row = accountBalances.find((r: AccountBalanceRow) => r.account_code === 'BANK');
    return row ? parseFloat(row.balance) : 0;
  }, [accountBalances]);
  const receivables = useMemo(() => {
    if (!accountBalances) return null;
    const row = accountBalances.find((r: AccountBalanceRow) => r.account_code === 'AR');
    return row ? parseFloat(row.balance) : 0;
  }, [accountBalances]);
  const payables = useMemo(() => {
    if (!accountBalances) return null;
    const row = accountBalances.find((r: AccountBalanceRow) => r.account_code === 'AP');
    return row ? parseFloat(row.balance) : 0;
  }, [accountBalances]);

  const { data: payablesRows = [] } = usePayablesOutstanding();
  const labourOwed = useMemo(
    () =>
      payablesRows.reduce((sum, r) => sum + (parseFloat(r.payable_balance) || 0), 0),
    [payablesRows]
  );

  const netPosition = useMemo(
    () =>
      computeNetPositionSigned(
        cash ?? 0,
        bank ?? 0,
        receivables ?? 0,
        payables ?? 0,
        labourOwed
      ),
    [cash, bank, receivables, payables, labourOwed]
  );

  const { data: profitability, isLoading: profitabilityLoading } = useCropProfitability(
    {
      from: cycleStart,
      to: cycleEnd,
      ...(viewBy === 'production_unit' && selectedProductionUnitId
        ? { production_unit_id: selectedProductionUnitId }
        : {}),
    },
    {
      enabled:
        !!cycleStart &&
        !!cycleEnd &&
        isModuleEnabled('reports') &&
        (viewBy !== 'production_unit' || !!selectedProductionUnitId),
    }
  );

  const { data: projectPLRows = [], isLoading: projectPLLoading } = useQuery({
    queryKey: ['reports', 'project-pl', { from: cycleStart, to: cycleEnd }],
    queryFn: () => apiClient.getProjectPL({ from: cycleStart, to: cycleEnd }),
    enabled: !!cycleStart && !!cycleEnd && isModuleEnabled('reports'),
    staleTime: 2 * 60 * 1000,
  });

  const { data: projectsForCycle } = useProjects(activeCycleId ?? undefined);
  const projectNameById = useMemo(() => {
    const m: Record<string, string> = {};
    (projectsForCycle ?? []).forEach((p) => {
      m[p.id] = p.name;
    });
    return m;
  }, [projectsForCycle]);

  const projectIdsInCycle = useMemo(
    () => new Set((projectsForCycle ?? []).map((p) => p.id)),
    [projectsForCycle]
  );
  const fieldRows = useMemo(() => {
    return [...projectPLRows]
      .filter((row) => !activeCycleId || projectIdsInCycle.has(row.project_id))
      .map((row) => ({
        project_id: row.project_id,
        name: projectNameById[row.project_id] || row.project_id,
        cost: parseFloat(row.expenses),
        revenue: parseFloat(row.income),
        margin: parseFloat(row.net_profit),
      }))
      .sort((a, b) => b.cost - a.cost)
      .slice(0, 10);
  }, [projectPLRows, projectNameById, activeCycleId, projectIdsInCycle]);

  const { data: draftTransactions = [] } = useOperationalTransactions({ status: 'DRAFT' });
  const pendingCount = draftTransactions.length;
  const showReviewQueue = (hasRole('tenant_admin') || hasRole('accountant')) && pendingCount >= 0;


  const { alerts, isLoading: alertsLoading } = useAlerts();
  const topAlerts = alerts.slice(0, 3);

  const showCashSection = isModuleEnabled('reports');
  const showSeason =
    isModuleEnabled('reports') &&
    (viewBy === 'crop_cycle' ? activeCycle : viewBy === 'production_unit' && selectedUnit);
  const showFieldStatus =
    isModuleEnabled('reports') &&
    isModuleEnabled('projects_crop_cycles') &&
    (viewBy === 'crop_cycle' || !selectedProductionUnitId);

  const hasActiveSeason = isModuleEnabled('projects_crop_cycles') && (viewBy === 'crop_cycle' ? activeCycle : viewBy === 'production_unit' && selectedUnit);

  return (
    <div className="max-w-2xl mx-auto pb-24 sm:pb-6">
      <PageHeader
        title="Farm Pulse"
        backTo="/app/dashboard"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Farm Pulse' },
        ]}
      />

      <div className="space-y-6">
        {/* 1) Today on the farm — drafts + review queue */}
        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            {term('todayOnFarm')}
          </h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Link
              to="/app/transactions"
              className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:border-[#1F6F5C]/30 block"
            >
              <p className="text-sm font-medium text-gray-500">Drafts awaiting approval</p>
              <p className="mt-1 text-xl font-semibold text-gray-900 tabular-nums">{pendingCount}</p>
              <span className="text-sm text-[#1F6F5C] font-medium">Open drafts →</span>
            </Link>
            {showReviewQueue && (
              <Link
                to="/app/review-queue"
                className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:border-[#1F6F5C]/30 block"
              >
                <p className="text-sm font-medium text-gray-500">{term('reviewQueue')}</p>
                <p className="mt-1 text-xl font-semibold text-gray-900 tabular-nums">
                  {pendingCount} draft{pendingCount !== 1 ? 's' : ''}
                </p>
                <span className="text-sm text-[#1F6F5C] font-medium">Open queue →</span>
              </Link>
            )}
          </div>
        </section>

        {/* View by: Crop Cycle | Production Unit */}
        {isModuleEnabled('projects_crop_cycles') && (
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-sm font-medium text-gray-700">View by:</span>
            <div className="flex rounded-lg border border-gray-200 p-0.5 bg-gray-50">
              <button
                type="button"
                onClick={() => setViewBy('crop_cycle')}
                className={`px-3 py-1.5 text-sm rounded-md ${viewBy === 'crop_cycle' ? 'bg-white shadow text-[#1F6F5C] font-medium' : 'text-gray-600 hover:text-gray-900'}`}
              >
                Crop Cycle
              </button>
              <button
                type="button"
                onClick={() => setViewBy('production_unit')}
                className={`px-3 py-1.5 text-sm rounded-md ${viewBy === 'production_unit' ? 'bg-white shadow text-[#1F6F5C] font-medium' : 'text-gray-600 hover:text-gray-900'}`}
              >
                Production Unit
              </button>
            </div>
            {viewBy === 'production_unit' && (() => {
              const units = productionUnits ?? [];
              const orchardUnits = units.filter((u) => u.category === 'ORCHARD');
              const livestockUnits = units.filter((u) => u.category === 'LIVESTOCK');
              const otherUnits = units.filter((u) => u.category !== 'ORCHARD' && u.category !== 'LIVESTOCK');
              return (
                <select
                  value={selectedProductionUnitId}
                  onChange={(e) => setSelectedProductionUnitId(e.target.value)}
                  className="px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                >
                  <option value="">Select unit</option>
                  {orchardUnits.length > 0 && (
                    <optgroup label="Orchards">
                      {orchardUnits.map((u) => (
                        <option key={u.id} value={u.id}>
                          {u.name}
                        </option>
                      ))}
                    </optgroup>
                  )}
                  {livestockUnits.length > 0 && (
                    <optgroup label="Livestock">
                      {livestockUnits.map((u) => (
                        <option key={u.id} value={u.id}>
                          {u.name}
                        </option>
                      ))}
                    </optgroup>
                  )}
                  {otherUnits.length > 0 && (
                    <optgroup label={orchardUnits.length > 0 || livestockUnits.length > 0 ? 'Other units' : 'Production units'}>
                      {otherUnits.map((u) => (
                        <option key={u.id} value={u.id}>
                          {u.name}
                        </option>
                      ))}
                    </optgroup>
                  )}
                </select>
              );
            })()}
          </div>
        )}

        {/* 2) Season snapshot — or no active season CTA */}
        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            {term('seasonSnapshot')}
          </h2>
          {!hasActiveSeason ? (
            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
              <p className="font-medium text-gray-700">{term('noActiveSeason')}</p>
              <Link
                to="/app/crop-cycles"
                className="mt-3 inline-flex items-center px-3 py-1.5 rounded-lg bg-[#1F6F5C] text-white text-sm font-medium hover:bg-[#1a5a4a]"
              >
                {term('createCropCycleCta')}
              </Link>
            </div>
          ) : !showSeason ? (
            <p className="text-sm text-gray-500">Select a production unit above, or switch to Crop Cycle view.</p>
          ) : profitabilityLoading ? (
            <div className="flex justify-center py-6">
              <LoadingSpinner />
            </div>
          ) : profitability?.totals ? (
            <Link
              to={`/app/reports/crop-profitability?from=${cycleStart}&to=${cycleEnd}`}
              className="block rounded-xl border border-gray-200 bg-white p-4 shadow-sm space-y-2 hover:border-[#1F6F5C]/30"
            >
              <p className="font-medium text-gray-900">
                {activeCycle?.name} ({formatDateRange(activeCycle?.start_date, cycleEnd)})
              </p>
              <div className="grid grid-cols-3 gap-3 text-sm">
                <div>
                  <p className="text-gray-500">Cost so far</p>
                  <p className="font-semibold tabular-nums">{formatMoney(parseFloat(profitability.totals.cost))}</p>
                </div>
                <div>
                  <p className="text-gray-500">Revenue so far</p>
                  <p className="font-semibold tabular-nums">{formatMoney(parseFloat(profitability.totals.revenue))}</p>
                </div>
                <div>
                  <p className="text-gray-500">Margin so far</p>
                  <p className="font-semibold tabular-nums">{formatMoney(parseFloat(profitability.totals.margin))}</p>
                </div>
              </div>
              <p className="text-xs text-gray-400">From posted season activity</p>
              <span className="text-sm text-[#1F6F5C] hover:underline">View crop profitability →</span>
            </Link>
          ) : (
            <div className="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-500">
              No profitability data for this period.
            </div>
          )}
        </section>

        {/* 3) Field Status */}
        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            Field Status
          </h2>
          {!showFieldStatus ? (
            <p className="text-sm text-gray-500">
              {viewBy === 'production_unit' && selectedProductionUnitId
                ? 'Unit reporting: Field status by unit coming soon. Tag activities and harvests with a production unit to use Unit Snapshot above.'
                : 'Reports and crop cycles required.'}
            </p>
          ) : projectPLLoading ? (
            <div className="flex justify-center py-6">
              <LoadingSpinner />
            </div>
          ) : fieldRows.length === 0 ? (
            <div className="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-500">
              No {term('fieldCycle').toLowerCase()} data for this period.
            </div>
          ) : (
            <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-3 py-2 text-left font-medium text-gray-600">{term('fieldCycle')}</th>
                      <th className="px-3 py-2 text-right font-medium text-gray-600">Cost so far</th>
                      <th className="px-3 py-2 text-right font-medium text-gray-600">Revenue so far</th>
                      <th className="px-3 py-2 text-right font-medium text-gray-600">Margin</th>
                    </tr>
                  </thead>
                  <tbody>
                    {fieldRows.map((row) => (
                      <tr key={row.project_id} className="border-t border-gray-100">
                        <td className="px-3 py-2">
                          <Link
                            to={`/app/projects/${row.project_id}`}
                            className="text-[#1F6F5C] hover:underline font-medium"
                          >
                            {row.name}
                          </Link>
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatMoney(row.cost)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatMoney(row.revenue)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatMoney(row.margin)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="px-3 py-2 bg-gray-50 border-t text-center">
                <Link
                  to="/app/reports/project-pl"
                  className="text-sm text-[#1F6F5C] hover:underline"
                >
                  View {term('fieldCycle')} P&amp;L →
                </Link>
              </div>
            </div>
          )}
        </section>

        {/* 4) Money snapshot (secondary) */}
        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            {term('moneySnapshot')}
          </h2>
          {!showCashSection ? (
            <p className="text-sm text-gray-500">Reports module is required for cash summary.</p>
          ) : balancesLoading ? (
            <div className="flex justify-center py-6">
              <LoadingSpinner />
            </div>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
              <HeroCard
                title="Cash in hand"
                value={cash != null ? formatMoney(cash) : '—'}
                sub={cash == null ? 'Coming soon' : undefined}
                link="/app/farm-pulse/cash"
                howCalculated={cash != null ? 'From account balances (as of today)' : undefined}
              />
              <HeroCard
                title="Bank balance"
                value={bank != null ? formatMoney(bank) : '—'}
                sub={bank == null ? 'Coming soon' : undefined}
                link="/app/farm-pulse/bank"
                howCalculated={bank != null ? 'From account balances (as of today)' : undefined}
              />
              <HeroCard
                title="Buyers owe me"
                value={receivables != null ? formatMoney(receivables) : '—'}
                link="/app/reports/ar-ageing"
                howCalculated={receivables != null ? 'From account balances (as of today)' : undefined}
              />
              <HeroCard
                title={payables != null ? 'I owe suppliers' : 'Bills to pay'}
                value={payables != null ? formatMoney(payables) : formatMoney(0)}
                valueColorClass={getMoneyColorClass(payables ?? 0)}
                sub={payables == null ? 'Coming soon' : undefined}
                link="/app/farm-pulse/payables"
                howCalculated={payables != null ? 'From account balances (as of today)' : undefined}
              />
              {isModuleEnabled('labour') && (
                <HeroCard
                  title="Labour owed"
                  value={formatMoney(labourOwed)}
                  valueColorClass={getMoneyColorClass(labourOwed)}
                  link="/app/farm-pulse/labour-owed"
                  howCalculated="From outstanding wages"
                />
              )}
              <div className="rounded-xl border-2 border-[#1F6F5C]/20 bg-[#1F6F5C]/5 p-4">
                <p className="text-sm font-medium text-gray-600">Net position</p>
                <p className="mt-1 text-xl font-semibold text-gray-900 tabular-nums">
                  <span className={getMoneyColorClass(netPosition)}>{formatMoney(netPosition)}</span>
                </p>
                <p className="mt-0.5 text-xs text-gray-500">
                  Cash + bank + receivables + payables + labour (each balance signed; liabilities negative)
                </p>
              </div>
            </div>
          )}
        </section>

        {/* Alerts */}
        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            Alerts
          </h2>
          {alertsLoading ? (
            <div className="flex justify-center py-4">
              <LoadingSpinner />
            </div>
          ) : topAlerts.length > 0 ? (
            <div className="space-y-3">
              {topAlerts.map((alert) => (
                <Link
                  key={alert.id}
                  to={alert.ctaHref}
                  className={`block rounded-lg border p-3 text-sm ${
                    alert.severity === 'critical'
                      ? 'border-red-200 bg-red-50 text-red-900'
                      : alert.severity === 'warning'
                        ? 'border-amber-200 bg-amber-50 text-amber-900'
                        : 'border-blue-200 bg-blue-50 text-blue-900'
                  }`}
                >
                  <span className="font-medium">{alert.title}</span>
                  {alert.count != null && (
                    <span className="tabular-nums ml-1">({alert.count})</span>
                  )}
                </Link>
              ))}
              <Link
                to="/app/alerts"
                className="block rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm text-[#1F6F5C] font-medium hover:bg-gray-50"
              >
                View all alerts
              </Link>
            </div>
          ) : (
            <p className="text-sm text-gray-500">No alerts right now.</p>
          )}
        </section>

        {/* Daily Admin Review (admin only; deletes not logged, skipped) */}
        {isAdmin && (
          <section>
            <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
              Daily Admin Review
            </h2>
            <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
              {dailyReview ? (
                <div className="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <p className="text-gray-500">Records created today</p>
                    <p className="font-semibold tabular-nums text-gray-900">
                      {dailyReview.records_created_today}
                    </p>
                  </div>
                  <div>
                    <p className="text-gray-500">Records edited today</p>
                    <p className="font-semibold tabular-nums text-gray-900">
                      {dailyReview.records_edited_today}
                    </p>
                  </div>
                </div>
              ) : (
                <div className="flex justify-center py-2">
                  <LoadingSpinner />
                </div>
              )}
            </div>
          </section>
        )}

        {/* Quick actions */}
        <section>
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
            {term('quickActions')}
          </h2>
          <QuickActions />
        </section>
      </div>
    </div>
  );
}

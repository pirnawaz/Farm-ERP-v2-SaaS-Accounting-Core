import { useMemo, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import {
  useStockOnHand,
  useStockMovements,
  useInventoryItems,
  useInventoryStores,
} from '../../hooks/useInventory';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import { formatItemDisplayName } from '../../utils/formatItemDisplay';
import type { InvStockMovement } from '../../types';

function humanizeMovementType(raw: string): string {
  if (!raw) return 'Movement';
  const s = raw.replace(/_/g, ' ').toLowerCase();
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export default function InventoryDashboardPage() {
  const { formatMoney, formatDate } = useFormatting();

  const movementFrom = useMemo(() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d.toISOString().slice(0, 10);
  }, []);

  const { data: stock, isLoading: stockLoading } = useStockOnHand({});
  const { data: movements, isLoading: movementsLoading } = useStockMovements({ from: movementFrom });
  const { data: items } = useInventoryItems(true);
  const { data: stores } = useInventoryStores();

  const itemCount = items?.length ?? 0;
  const storeCount = stores?.length ?? 0;

  const totalValue = useMemo(() => {
    if (!stock?.length) return 0;
    return stock.reduce((sum, row) => sum + parseFloat(String(row.value_on_hand ?? 0)), 0);
  }, [stock]);

  /** Balance rows from the API (store × item lines). */
  const stockLines = useMemo(() => {
    if (!stock?.length) return 0;
    return stock.filter((r) => parseFloat(String(r.qty_on_hand ?? 0)) !== 0).length;
  }, [stock]);

  const movementsLast30 = movements?.length ?? 0;

  const recentPreview = useMemo(() => {
    const list = movements ?? [];
    const sorted = [...list].sort((a, b) => String(b.occurred_at).localeCompare(String(a.occurred_at)));
    return sorted.slice(0, 5);
  }, [movements]);

  const sparseSetup = itemCount === 0 && storeCount === 0;
  const hasSetupButNoStock =
    itemCount > 0 && storeCount > 0 && stockLines === 0 && movementsLast30 === 0 && !stockLoading;

  return (
    <div className="space-y-10 max-w-6xl">
      {/* SECTION 1 — HEADER */}
      <header>
        <h1 className="text-2xl font-bold text-gray-900">Inventory Overview</h1>
        <p className="mt-1 text-base text-gray-700 max-w-2xl">Track your stock, movements, and storage locations.</p>
      </header>

      {/* SECTION 5 — GETTING STARTED (sparse) */}
      {sparseSetup && (
        <section
          className="rounded-xl border border-amber-200/80 bg-amber-50/90 px-5 py-4 text-amber-950 shadow-sm"
          aria-labelledby="inv-onboarding-title"
        >
          <h2 id="inv-onboarding-title" className="text-sm font-semibold text-amber-950">
            Get started with inventory
          </h2>
          <p className="mt-1 text-sm text-amber-900/90">
            Add items, add stores, then record goods received. That order gets you ready to track stock.
          </p>
          <ol className="mt-3 list-decimal list-inside space-y-1.5 text-sm text-amber-950">
            <li>Add items — what you buy or use on the farm.</li>
            <li>Add stores — where you keep stock (sheds, silos, bins).</li>
            <li>Record goods received — when deliveries arrive.</li>
          </ol>
          <div className="mt-4 flex flex-wrap gap-2">
            <Link
              to="/app/inventory/items"
              className="inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-[#1a5a4a]"
            >
              Add items
            </Link>
            <Link
              to="/app/inventory/stores"
              className="inline-flex items-center justify-center rounded-lg border border-amber-300/80 bg-white px-4 py-2.5 text-sm font-medium text-amber-950 hover:bg-amber-100/50"
            >
              Add stores
            </Link>
            <Link
              to="/app/inventory/grns/new"
              className="inline-flex items-center justify-center rounded-lg border border-amber-300/80 bg-white px-4 py-2.5 text-sm font-medium text-amber-950 hover:bg-amber-100/50"
            >
              New goods received
            </Link>
          </div>
        </section>
      )}

      {!sparseSetup && hasSetupButNoStock && (
        <p className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
          You have items and stores but no stock balances yet.{' '}
          <Link to="/app/inventory/grns/new" className="font-medium text-[#1F6F5C] hover:underline">
            Record goods received
          </Link>{' '}
          to bring stock in, or open{' '}
          <Link to="/app/inventory/stock-on-hand" className="font-medium text-[#1F6F5C] hover:underline">
            Current Stock
          </Link>{' '}
          when you are ready.
        </p>
      )}

      {/* SECTION 2 — KPI SUMMARY */}
      <section aria-labelledby="inv-kpi-heading">
        <h2 id="inv-kpi-heading" className="sr-only">
          Summary
        </h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <KpiCard
            label="Total stock value"
            loading={stockLoading}
            value={stockLoading ? null : formatMoney(totalValue)}
            hint="From current balances"
          />
          <KpiCard
            label="Stock lines"
            loading={stockLoading}
            value={stockLoading ? null : String(stockLines)}
            hint="Non-zero balances"
            foot={
              <Link to="/app/inventory/stock-on-hand" className="text-[#1F6F5C] hover:underline font-medium">
                View current stock
              </Link>
            }
          />
          <KpiCard
            label="Stores"
            loading={false}
            value={String(storeCount)}
            hint="Storage locations"
            foot={
              <Link to="/app/inventory/stores" className="text-[#1F6F5C] hover:underline font-medium">
                View stores
              </Link>
            }
          />
          <KpiCard
            label="Movements (30 days)"
            loading={movementsLoading}
            value={movementsLoading ? null : String(movementsLast30)}
            hint="Stock movements recorded in the last 30 days"
            foot={
              <Link to="/app/inventory/stock-movements" className="text-[#1F6F5C] hover:underline font-medium">
                View stock history
              </Link>
            }
          />
        </div>
      </section>

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-5 lg:gap-10">
        {/* SECTION 3 — RECENT STOCK ACTIVITY */}
        <section className="lg:col-span-3" aria-labelledby="inv-recent-heading">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3">
              <h2 id="inv-recent-heading" className="text-lg font-semibold text-gray-900">
                Recent stock activity
              </h2>
              <Link
                to="/app/inventory/stock-movements"
                className="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-[#1F6F5C] hover:bg-gray-50"
              >
                View Stock History
              </Link>
            </div>

            {movementsLoading ? (
              <div className="flex justify-center py-10">
                <LoadingSpinner />
              </div>
            ) : recentPreview.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-600">
                No movements in the last 30 days. Record goods received or stock used to see activity here.
              </p>
            ) : (
              <ul className="divide-y divide-gray-100">
                {recentPreview.map((m: InvStockMovement) => (
                  <li key={m.id} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-medium text-gray-900 truncate">
                        {formatItemDisplayName(m.item)}
                      </p>
                      <p className="text-xs text-gray-500 mt-0.5">
                        {humanizeMovementType(m.movement_type)}
                        {m.store?.name ? ` · ${m.store.name}` : ''}
                      </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-4 text-sm tabular-nums">
                      <span className={parseFloat(String(m.qty_delta)) < 0 ? 'text-red-700' : 'text-gray-800'}>
                        {m.qty_delta}
                      </span>
                      <span className="text-gray-500 w-[9rem] text-right">{formatDate(m.occurred_at)}</span>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </section>

        {/* SECTION 4 — QUICK ACTIONS */}
        <section className="lg:col-span-2" aria-labelledby="inv-actions-heading">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 id="inv-actions-heading" className="text-lg font-semibold text-gray-900">
              Quick actions
            </h2>
            <p className="mt-1 text-xs text-gray-500">Shortcuts — full lists stay in the sidebar.</p>

            <div className="mt-4 space-y-2">
              <p className="text-xs font-medium uppercase tracking-wide text-gray-400">Common tasks</p>
              <div className="flex flex-col gap-2">
                <ActionLink to="/app/inventory/grns" variant="primary">
                  Goods Received
                </ActionLink>
                <ActionLink to="/app/inventory/issues" variant="primary">
                  Stock Used
                </ActionLink>
                <ActionLink to="/app/inventory/transfers" variant="primary">
                  Transfer Stock
                </ActionLink>
                <ActionLink to="/app/inventory/adjustments" variant="primary">
                  Adjust Stock
                </ActionLink>
              </div>
            </div>

            <div className="mt-6 space-y-2">
              <p className="text-xs font-medium uppercase tracking-wide text-gray-400">Lists</p>
              <div className="flex flex-wrap gap-2">
                <ActionLink to="/app/inventory/items" variant="secondary">
                  View Items
                </ActionLink>
                <ActionLink to="/app/inventory/stores" variant="secondary">
                  View Stores
                </ActionLink>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}

function KpiCard({
  label,
  value,
  hint,
  loading,
  foot,
}: {
  label: string;
  value: string | null;
  hint: string;
  loading: boolean;
  foot?: ReactNode;
}) {
  return (
    <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
      <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
      {loading ? (
        <div className="mt-3 flex h-9 items-center">
          <LoadingSpinner />
        </div>
      ) : (
        <p className="mt-2 text-2xl font-semibold tabular-nums text-gray-900">{value}</p>
      )}
      <p className="mt-1 text-xs text-gray-500">{hint}</p>
      {foot && <div className="mt-3 text-sm">{foot}</div>}
    </div>
  );
}

function ActionLink({
  to,
  variant,
  children,
}: {
  to: string;
  variant: 'primary' | 'secondary';
  children: ReactNode;
}) {
  const base = 'inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium transition-colors text-center';
  const styles =
    variant === 'primary'
      ? 'bg-[#1F6F5C] text-white shadow-sm hover:bg-[#1a5a4a]'
      : 'border border-gray-300 bg-white text-gray-800 hover:bg-gray-50';
  return (
    <Link to={to} className={`${base} ${styles}`}>
      {children}
    </Link>
  );
}

import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useStockMovements, useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { term } from '../../config/terminology';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { formatItemDisplayName } from '../../utils/formatItemDisplay';
import type { InvStockMovement } from '../../types';

type MovementKind = 'grn' | 'issue' | 'transfer' | 'adjustment' | 'unknown';

function movementKindFrom(row: Pick<InvStockMovement, 'source_type' | 'movement_type'>): MovementKind {
  const s = String(row.source_type || '').toUpperCase();
  if (s.includes('GRN')) return 'grn';
  if (s.includes('ISSUE')) return 'issue';
  if (s.includes('TRANSFER')) return 'transfer';
  if (s.includes('ADJUST')) return 'adjustment';
  const m = String(row.movement_type || '').toUpperCase();
  if (m.includes('GRN')) return 'grn';
  if (m.includes('ISSUE')) return 'issue';
  if (m.includes('TRANSFER')) return 'transfer';
  if (m.includes('ADJUST')) return 'adjustment';
  return 'unknown';
}

function movementLabel(row: Pick<InvStockMovement, 'source_type' | 'movement_type'>): string {
  const kind = movementKindFrom(row);
  if (kind === 'grn') return term('grn');
  if (kind === 'issue') return term('issue');
  if (kind === 'transfer') return term('transfer');
  if (kind === 'adjustment') return term('adjustment');
  const raw = String(row.movement_type || row.source_type || 'Movement');
  return raw.replace(/_/g, ' ');
}

function sourceLink(row: Pick<InvStockMovement, 'source_type' | 'source_id' | 'movement_type'>): { to: string; label: string } | null {
  const kind = movementKindFrom(row);
  const id = row.source_id;
  if (!id) return null;
  const short = String(id).slice(0, 8);
  if (kind === 'grn') return { to: `/app/inventory/grns/${id}`, label: `Goods Received · ${short}` };
  if (kind === 'issue') return { to: `/app/inventory/issues/${id}`, label: `Stock Used · ${short}` };
  if (kind === 'transfer') return { to: `/app/inventory/transfers/${id}`, label: `Transfer Stock · ${short}` };
  if (kind === 'adjustment') return { to: `/app/inventory/adjustments/${id}`, label: `Adjust Stock · ${short}` };
  return null;
}

function splitInOut(qtyDelta: string): { inQty: string; outQty: string } {
  const q = parseFloat(String(qtyDelta));
  if (!Number.isFinite(q) || q === 0) return { inQty: '', outQty: '' };
  if (q > 0) return { inQty: String(qtyDelta), outQty: '' };
  return { inQty: '', outQty: String(Math.abs(q)) };
}

type LedgerRow = InvStockMovement & {
  inQty: string;
  outQty: string;
  runningQty?: string;
};

export default function StockMovementsPage() {
  const [storeId, setStoreId] = useState('');
  const [itemId, setItemId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const { data: movements, isLoading } = useStockMovements({
    store_id: storeId || undefined,
    item_id: itemId || undefined,
    from: from || undefined,
    to: to || undefined,
  });
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { formatDate } = useFormatting();

  const runningMode = Boolean(storeId && itemId && from && to);

  const tableRows = useMemo((): LedgerRow[] => {
    const list = (movements ?? []) as InvStockMovement[];
    const sorted = [...list].sort((a, b) =>
      runningMode
        ? String(a.occurred_at).localeCompare(String(b.occurred_at)) // oldest → newest (required for running mode)
        : String(b.occurred_at).localeCompare(String(a.occurred_at)), // newest → oldest
    );

    if (!runningMode) {
      return sorted.map((r) => {
        const { inQty, outQty } = splitInOut(String(r.qty_delta));
        return { ...r, inQty, outQty };
      });
    }

    // Safe-mode definition (explicit on-page): opening balance starts at 0 for the selected range.
    // This represents the running net change within the selected period, not an authoritative stock position.
    let running = 0;
    return sorted.map((r) => {
      const q = parseFloat(String(r.qty_delta));
      running += Number.isFinite(q) ? q : 0;
      const { inQty, outQty } = splitInOut(String(r.qty_delta));
      return { ...r, inQty, outQty, runningQty: String(running) };
    });
  }, [movements, runningMode]);

  const hasFilters = !!(storeId || itemId || from || to);

  const summaryLine = useMemo(() => {
    const n = tableRows.length;
    const label = n === 1 ? 'movement' : 'movements';
    const base = hasFilters ? `${n} ${label} (filtered)` : `${n} ${label}`;
    if (n === 0) return base;
    const bits: string[] = [];
    if (from && to) bits.push(`${from} → ${to}`);
    else if (from) bits.push(`from ${from}`);
    else if (to) bits.push(`to ${to}`);
    if (bits.length) return `${base} · ${bits.join(' · ')}`;
    return base;
  }, [tableRows.length, hasFilters, from, to]);

  const clearFilters = () => {
    setStoreId('');
    setItemId('');
    setFrom('');
    setTo('');
  };

  const cols: Column<LedgerRow>[] = [
    {
      header: 'Date',
      accessor: (r) => (
        <span className="tabular-nums text-gray-900">{formatDate(r.occurred_at, { variant: 'medium' })}</span>
      ),
    },
    {
      header: 'Document / reference',
      accessor: (r) => {
        const l = sourceLink(r);
        if (!l) {
          const short = r.source_id ? String(r.source_id).slice(0, 8) : '';
          const label = short ? `${String(r.source_type || 'Source')} · ${short}` : String(r.source_type || 'Source');
          return <span title={r.source_id || undefined}>{label}</span>;
        }
        return (
          <Link to={l.to} className="text-[#1F6F5C] font-medium hover:underline" title={r.source_id}>
            {l.label}
          </Link>
        );
      },
    },
    {
      header: 'Movement type',
      accessor: (r) => <span className="text-gray-800">{movementLabel(r)}</span>,
    },
    { header: 'Item', accessor: (r) => formatItemDisplayName(r.item) },
    {
      header: 'Store / location',
      accessor: (r) => r.store?.name || r.store_id,
    },
    {
      header: 'IN',
      accessor: (r) => (r.inQty ? <span className="tabular-nums text-emerald-700 font-medium">{r.inQty}</span> : ''),
      numeric: true,
    },
    {
      header: 'OUT',
      accessor: (r) => (r.outQty ? <span className="tabular-nums text-red-700 font-medium">{r.outQty}</span> : ''),
      numeric: true,
    },
    ...(runningMode
      ? [
          {
            header: 'Cumulative net change',
            accessor: (r: LedgerRow) => <span className="tabular-nums font-medium text-gray-900">{r.runningQty ?? ''}</span>,
            numeric: true,
          } as Column<LedgerRow>,
        ]
      : []),
  ];

  return (
    <div className="space-y-6 max-w-7xl">
      <PageHeader
        title={term('stockMovements')}
        tooltip="Track stock movements over time across items and storage locations."
        description="Track stock movements over time across items and storage locations."
        backTo="/app/inventory"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Inventory Overview', to: '/app/inventory' },
          { label: term('stockMovements') },
        ]}
      />

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={clearFilters}
            disabled={!hasFilters}
            className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
          >
            Clear filters
          </button>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label htmlFor="sm-store" className="block text-xs font-medium text-gray-600 mb-1">
              Store
            </label>
            <select
              id="sm-store"
              value={storeId}
              onChange={(e) => setStoreId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All stores</option>
              {stores?.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="sm-item" className="block text-xs font-medium text-gray-600 mb-1">
              Item
            </label>
            <select
              id="sm-item"
              value={itemId}
              onChange={(e) => setItemId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All items</option>
              {items?.map((i) => (
                <option key={i.id} value={i.id}>
                  {i.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="sm-from" className="block text-xs font-medium text-gray-600 mb-1">
              From
            </label>
            <input
              id="sm-from"
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
          <div>
            <label htmlFor="sm-to" className="block text-xs font-medium text-gray-600 mb-1">
              To
            </label>
            <input
              id="sm-to"
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
        </div>
        {!runningMode ? (
          <p className="mt-3 text-xs text-gray-500">
            Cumulative net change appears when you select one store, one item, and both From and To dates.
          </p>
        ) : null}
      </section>

      {runningMode ? (
        <div className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
          <div className="font-medium text-gray-900">Cumulative net change mode</div>
          <div className="mt-1 text-xs text-gray-600">
            Starts from <span className="font-medium">0</span> at the selected From date ({from}). It shows{' '}
            <span className="font-medium">cumulative stock movement</span> within the selected date window and does not replace the authoritative current stock position in{' '}
            <Link to="/app/inventory/stock-on-hand" className="font-medium text-[#1F6F5C] hover:underline">
              Current Stock
            </Link>
            .
          </div>
        </div>
      ) : null}

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : tableRows.length === 0 && !hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No stock movements yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Record goods received, stock used, transfers, or adjustments to see history here.
          </p>
        </div>
      ) : tableRows.length === 0 && hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No movements match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try widening the date range or clearing filters.</p>
          <button
            type="button"
            onClick={clearFilters}
            className="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
          >
            Clear filters
          </button>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
          <DataTable data={tableRows} columns={cols} emptyMessage="" />
        </div>
      )}
    </div>
  );
}

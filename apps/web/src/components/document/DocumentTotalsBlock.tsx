import { ReactNode } from 'react';

/** Single prominent total line (invoice-style). */
export function DocumentTotalsBlock({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="print-totals">
      <div className="flex justify-end">
        <div className="w-64">
          <div className="flex justify-between mb-2">
            <span className="font-semibold">{label}</span>
            <span className="font-semibold tabular-nums">{value}</span>
          </div>
        </div>
      </div>
    </div>
  );
}

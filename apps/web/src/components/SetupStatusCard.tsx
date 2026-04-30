import type { ReactNode } from 'react';
import { SetupStatusBadge } from './SetupStatusBadge';

type Row = {
  label: string;
  value?: ReactNode;
  present?: boolean;
  presentLabel?: string;
  missingLabel?: string;
};

export function SetupStatusCard(props: {
  title?: string;
  subtitle?: ReactNode;
  actions?: ReactNode;
  rows: Row[];
  className?: string;
}) {
  const { title = 'Setup status', subtitle, actions, rows, className } = props;
  return (
    <section className={`bg-white rounded-lg shadow p-6 ${className ?? ''}`} aria-label={title}>
      <div className="flex flex-wrap items-start justify-between gap-2 mb-4">
        <div>
          <h2 className="text-lg font-medium text-gray-900">{title}</h2>
          {subtitle ? <div className="mt-0.5 text-sm text-gray-600">{subtitle}</div> : null}
        </div>
        {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
      </div>
      <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
        {rows.map((r) => (
          <div key={r.label} className="flex items-start justify-between gap-3">
            <dt className="text-sm font-medium text-gray-500">{r.label}</dt>
            <dd className="text-sm text-gray-900 text-right">
              {typeof r.present === 'boolean' ? (
                <SetupStatusBadge
                  present={r.present}
                  presentLabel={r.presentLabel}
                  missingLabel={r.missingLabel}
                  size="sm"
                />
              ) : (
                r.value ?? '—'
              )}
            </dd>
          </div>
        ))}
      </dl>
    </section>
  );
}


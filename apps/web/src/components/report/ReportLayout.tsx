import type { ReactNode } from 'react';
import { Badge } from '../Badge';

export type ReportKind = 'accounting' | 'analytics';

export function ReportKindBadge(props: { kind: ReportKind }) {
  const { kind } = props;
  const label = kind === 'accounting' ? 'Accounting report' : 'Operational analytics';

  return (
    <Badge variant={kind === 'accounting' ? 'neutral' : 'success'} size="md">
      {label}
    </Badge>
  );
}

export function ReportPage(props: { children: ReactNode; className?: string }) {
  return <div className={`space-y-6 ${props.className ?? ''}`}>{props.children}</div>;
}

export function ReportFilterCard(props: { children: ReactNode; className?: string }) {
  return <div className={`bg-white p-4 rounded-lg shadow space-y-4 ${props.className ?? ''}`}>{props.children}</div>;
}

export function ReportSectionCard(props: { children: ReactNode; className?: string }) {
  return <div className={`bg-white rounded-lg shadow overflow-hidden ${props.className ?? ''}`}>{props.children}</div>;
}


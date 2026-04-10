import { Link } from 'react-router-dom';
import { Badge } from '../Badge';
import type { FarmActivityTimelineItem } from '../../api/farmActivity';

function docHref(item: FarmActivityTimelineItem): string {
  if (item.kind === 'field_job') {
    return `/app/crop-ops/field-jobs/${item.id}`;
  }
  if (item.kind === 'harvest') {
    return `/app/harvests/${item.id}`;
  }

  return `/app/sales/${item.id}`;
}

function kindLabel(kind: FarmActivityTimelineItem['kind']): string {
  if (kind === 'field_job') {
    return 'Field job';
  }
  if (kind === 'harvest') {
    return 'Harvest';
  }

  return 'Sale';
}

function statusVariant(status: string): 'success' | 'warning' | 'neutral' {
  if (status === 'POSTED') {
    return 'success';
  }
  if (status === 'DRAFT') {
    return 'warning';
  }

  return 'neutral';
}

type Props = {
  items: FarmActivityTimelineItem[];
};

export function FarmActivityTimeline({ items }: Props) {
  if (items.length === 0) {
    return (
      <p className="text-sm text-gray-600 rounded-lg border border-dashed border-gray-200 bg-gray-50/80 px-4 py-8 text-center">
        No activity in this date range (or modules for field jobs, harvests, or sales are off).
      </p>
    );
  }

  return (
    <ol className="relative border-s border-gray-200 ms-3 space-y-6 pb-2 ps-0 list-none">
      {items.map((item) => (
        <li key={`${item.kind}-${item.id}`} className="ms-6">
          <span className="absolute flex items-center justify-center w-3 h-3 bg-[#1F6F5C] rounded-full -start-1.5 ring-4 ring-white" />
          <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm hover:border-[#1F6F5C]/25 transition-colors">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 tabular-nums">
                  {item.activity_date}
                </p>
                <p className="mt-1 text-base font-semibold text-gray-900">
                  {item.title}
                  {item.reference ? (
                    <span className="font-normal text-gray-600"> · {item.reference}</span>
                  ) : null}
                </p>
                <p className="mt-1 text-sm text-gray-700">{item.summary}</p>
              </div>
              <div className="flex flex-wrap items-center gap-2 shrink-0">
                <span className="text-xs text-gray-500">{kindLabel(item.kind)}</span>
                <Badge variant={statusVariant(item.status)}>{item.status}</Badge>
                <Link
                  to={docHref(item)}
                  className="text-sm font-medium text-[#1F6F5C] hover:underline whitespace-nowrap"
                >
                  Open →
                </Link>
              </div>
            </div>
          </div>
        </li>
      ))}
    </ol>
  );
}
